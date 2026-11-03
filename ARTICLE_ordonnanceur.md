# Construire un ordonnanceur de coroutines avec des générateurs PHP

> Cet article complète [Générateurs PHP : itération paresseuse, flux et orchestration de tâches](https://github.com/wilzwert/generator-examples/blob/main/ARTICLE.md). L'article principal présente le système de coroutines de façon condensée, puis l'utilise pour un ETL de fichier CSV. Ici, on le construit pas à pas, avec le code de chaque pièce.
>
> Si les générateurs ne vous sont pas familiers, commencez par [les rappels](https://github.com/wilzwert/generator-examples/blob/main/ARTICLE_rappels.md) : `send()`, `throw()` et `current()` sont utilisés partout dans la suite.

## Le problème : faire communiquer des générateurs

Un générateur consommé par un `foreach` a un seul producteur et un seul consommateur. On peut enchaîner des générateurs, mais chacun n'a qu'une sortie, son `yield`, et il n'y a pas de notion de "communication" entre plusieurs générateurs.

> Avant d'aller plus loin, une petite précision : tout ce qui suit s'inspire énormément de l'article de Nikita Popov, [Cooperative multitasking using coroutines (in PHP!)](https://www.npopov.com/2012/12/22/Cooperative-multitasking-using-coroutines-in-PHP.html), écrit en 2012 au moment de l'arrivée des générateurs dans PHP 5.5. On y retrouve déjà une tâche qui enveloppe un générateur, une file d'exécution, des tâches qui produisent des demandes avec `yield` (ses `SystemCall`), et `throw()` pour leur renvoyer une erreur.
>
> Nos objectifs sont toutefois différents. Popov construit une sorte de mini système d'exploitation : les tâches peuvent connaître leur identifiant, en créer d'autres ou les tuer, attendre des sockets avec `stream_select()`, et il termine sur un petit serveur HTTP non bloquant. Il gère aussi à la main les appels entre coroutines, puisque `yield from` n'existait pas encore. 
>
> De mon côté, il s'agit d'un projet différent et conceptuellement bien plus simple : je me suis concentré sur la communication basique entre tâches, avec des canaux de capacité limitée, une forme de contre-pression, la fin de flux et la détection d'interblocage.
>
> Rappelons qu'il s'agit d'un projet d'apprentissage : le but était de reprendre certaines des idées de Popov pour mieux les comprendre, en les appliquant à un autre problème. 

Nous allons voir ici comment on peut imaginer un système de coroutines coopératives très simplifié, conçu pour un ETL dont le rôle sera simplement de :
- lire un fichier CSV (une ligne à la fois)
- valider et convertir chaque ligne en une entité métier
- regrouper les entités métier pour traitement (batch, par 100)
- afficher un rapport des erreurs de validation

Nous allons utiliser :
- des **tâches** : des générateurs (lecture, validation/transformation, regroupement, traitement des rejets, affichage) ;
- des **canaux** : des files dans lesquelles certaines tâches déposent des valeurs, que d'autres tâches récupèrent ;
- des **commandes** : ce que les tâches produisent pour demander quelque chose (envoyer, recevoir, fermer un canal) ;
- un **ordonnanceur** : exécuter les commandes, gérer le remplissage des canaux, la mise en attente et le réveil des tâches

Plusieurs termes proches vont revenir ; voici le sens précis qu'ils auront dans la suite :
- **file d'exécution** : la file de l'ordonnanceur, qui contient les tâches prêtes à être relancées ;
- **tâche en attente** : une tâche que l'ordonnanceur a retirée de la file d'exécution, parce que sa commande ne peut pas être traitée tout de suite. Elle est rangée dans une file d'attente propre au canal concerné ;
- **réveiller** une tâche : la remettre dans la file d'exécution ;
- **relancer** une tâche : reprendre l'exécution de son générateur là où son dernier `yield` l'avait suspendu.

> Le code complet se trouve dans `src/Pipeline`. 

## Le canal

Un canal est à voir comme une sorte de tapis roulant : une tâche peut déposer une valeur sur le tapis, et une autre tâche peut la récupérer pour la traiter. Les valeurs ressortent dans l'ordre où elles sont entrées (FIFO).

Un canal a trois composants :
- un **buffer** : les valeurs envoyées dans le canal mais pas encore consommées ;
- une **capacité** : quand le buffer est plein, le producteur doit attendre. C'est la **contre-pression** (backpressure) : un producteur rapide ne peut pas inonder un consommateur lent, et la mémoire reste bornée. C'est l'ordonnanceur qui met le producteur en attente, puis le réveille quand une place se libère ;
- un état **fermé** : le producteur annonce qu'il n'enverra plus rien.

Un certain nombre de règles doivent s'appliquer : ne pas accepter de données sur un canal fermé, ne pas permettre de lire des données sur un canal vide, etc. `Channel` ne gère ici que ses propres règles internes, et lève une exception en cas de "mauvais" appel. L'ordonnanceur vérifie l'état du canal (`isClosed()`, `isFull()`, `isEmpty()`) avant chaque opération, pour ne jamais faire d'appel invalide. Ces exceptions sont donc un garde-fou : si l'une d'elles est levée, c'est le signe d'un bug, et elle interrompt l'ensemble du traitement.

Voici l'essentiel de la classe (le reste, comme `isFull()`, `isEmpty()` ou `isClosed()`, est dans le dépôt) :

```php
class Channel
{
    /**
     * @var \SplQueue<mixed>
     */
    private \SplQueue $queue;

    private bool $closed = false;

    public function __construct(public readonly int $capacity, public readonly string $name = '')
    {
        // ... vérifie que la capacité vaut au moins 1
        $this->queue = new \SplQueue();
    }

    public function push(mixed $value): void
    {
        if (null === $value) {
            throw new \LogicException('Value cannot be null');
        }

        if ($this->isClosed()) {
            throw new \LogicException('Channel is closed');
        }

        if ($this->isFull()) {
            throw new \LogicException('Channel is full');
        }

        $this->queue->enqueue($value);
    }

    public function shift(): mixed
    {
        if ($this->isEmpty()) {
            throw new \LogicException('Channel is empty');
        }

        return $this->queue->shift();
    }

    public function close(): void
    {
        if ($this->closed) {
            throw new \LogicException('Channel already closed');
        }

        $this->closed = true;
    }

    public function isDrained(): bool
    {
        return $this->isClosed() && $this->isEmpty();
    }
}
```


### Fermé n'est pas vide

`close()` signifie que les écritures ne peuvent plus s'effectuer, mais pas que le buffer doit être immédiatement vidé. Étant donné que le producteur ferme son canal juste après son dernier envoi, vider le buffer avant que le consommateur ait pu récupérer les données entraînerait des pertes. 
On a donc 2 états : 
- **fermé** : le producteur a indiqué avoir terminé, mais des valeurs peuvent rester dans le buffer ;
- **épuisé** (fermé et vide) : la vraie fin du flux du point de vue du consommateur.

Dans notre exemple, envoyer `null` dans un canal est interdit, car c'est par choix la valeur qui est donnée à un consommateur quand le canal est épuisé. 
Ce cas est vérifié dès la construction de la commande : `new Send($channel, null)` lève une `LogicException`. 

## Les commandes

Une tâche ne parle à l'ordonnanceur qu'à travers `yield`. Ce qu'elle produit est une **demande** : `yield new Send($out, $row)`, `yield new Receive($in)`, `yield new Close($out)`. La tâche décrit ce qu'elle veut ; l'ordonnanceur décide comment et quand l'exécuter (tout de suite, ou après l'avoir mise en attente).

Du point de vue de la tâche, la lecture est très simple : dans la même fonction, on produit simplement des commandes et on traite les valeurs obtenues en échange.

Une tâche consommatrice ressemble à ceci :

```php
/** @return \Generator<int, Command, mixed, void> */
function consumer(Channel $in): \Generator
{
    while (null !== ($value = yield new Receive($in))) {
        // handle $value
    }
}
```

Le type `\Generator<int, Command, mixed, void>` décrit, dans l'ordre : le type des clés, celui des valeurs produites par `yield`, celui des valeurs reçues par `send()`, et celui de la valeur renvoyée par `return`. Il permet à PHPStan de signaler un générateur qui produirait des données avec yield sans les envelopper dans une `Command`. Cette vérification est uniquement statique : à l'exécution, rien n'empêche une tâche de produire autre chose qu'une `Command`, c'est pourquoi l'ordonnanceur le vérifie aussi.

## Les tâches : un générateur et son état

Une tâche implémente une interface simple : 

```php
interface Task
{
    public string $id { get; }

    public function resume(): ?Command;

    public function getStatus(): TaskStatus;

    public function resumeWith(mixed $data): void;

    public function failWith(\Throwable $throwable): void;
}
```
Pour le moment, une seule implémentation `GeneratorTask` qui encapsule un générateur. Ainsi l'ordonnanceur n'a pas directement connaissance de la véritable nature des tâches. Initialement, je voulais également pouvoir créer d'autres types d'implémentations pour voir leurs différents impacts, mais j'ai renoncé pour conserver ma santé mentale (et mes cheveux).  

### Piloter une tâche à la main

Avant de regarder `GeneratorTask`, voyons ce que l'ordonnanceur devra faire, en pilotant nous-mêmes la tâche `consumer()` vue plus haut :

```php
$task = consumer(new Channel(1, 'in'));
$command = $task->current();  // Receive : la tâche démarre et demande une valeur
$command = $task->send('a');  // 'a' devient la valeur du yield ; la tâche la traite, puis redemande un Receive
$command = $task->send(null); // null : fin de flux, la boucle se termine, et la fonction aussi
var_dump($command);           // NULL : plus de commande
var_dump($task->valid());     // bool(false) : la tâche est terminée
```

Ici, c'est nous qui jouons le rôle de l'ordonnanceur : on lit la commande produite, on décide de la réponse, et on relance la tâche avec. 
Pour signaler une erreur (par exemple un envoi sur un canal fermé), on aurait appelé `throw()` à la place de `send()`.

`GeneratorTask` n'est rien d'autre que l'automatisation de ce pilotage. Avec une différence : l'ordonnanceur décide de la réponse à un moment donné (par exemple quand une valeur arrive dans le canal), mais la tâche ne sera relancée que plus tard, quand son tour viendra dans la file d'exécution. 
`GeneratorTask` mémorise donc cette réponse en attendant, en plus de l'état de la tâche (non démarrée, en cours, terminée).

```php
public function resume(): ?Command
{
    if (TaskStatus::DONE === $this->status) {
        throw new \LogicException('Cannot resume a finished task');
    }

    if (TaskStatus::NOT_STARTED === $this->status) {
        $this->status = TaskStatus::STARTED;
        $next = $this->generator->current();
    } elseif (null !== $this->failWith) {
        /** @var ?Command $next */
        $next = $this->generator->throw($this->failWith);
        $this->failWith = null;
    } else {
        $next = $this->generator->send($this->resumeWith);
        $this->resumeWith = null;
    }

    // a generator only knows it is finished once resumed past its last yield:
    // in that case $next is not a yielded value, just null
    if (!$this->generator->valid()) {
        $this->status = TaskStatus::DONE;
    }

    return $next;
}
```

On retrouve nos appels manuels : `current()` au premier tour, `throw()` si une erreur est en attente, `send()` sinon, puis `valid()` pour savoir si la tâche est terminée. Les méthodes `resumeWith()` et `failWith()` de l'interface se contentent de stocker la valeur ou l'exception qui sont consommées au prochain appel de `resume()`.

## L'ordonnanceur : tour de rôle, mise en attente, réveil

Les tâches sont confiées à l'ordonnanceur avec sa méthode `spawn($id, $generator)`, qui enveloppe le générateur dans une `GeneratorTask` et la place dans la file d'exécution. 
L'identifiant sert uniquement aux messages d'erreur ; deux tâches ne peuvent pas avoir le même.

Le point d'entrée d'exécution de l'ordonnanceur est très simple. Il s'agit d'une boucle qui itère sur les tâches en cours :
- prendre la prochaine tâche de la file
- la relancer
- vérifier son état (une tâche terminée n'envoie pas de commande, et n'a pas besoin d'être remise dans la file d'exécution)
- prendre en charge la `Command` produite par la tâche

Voici une version simplifiée (le code réel vérifie en plus que la tâche a bien produit une `Command`) :

```php
public function run(): void
{
    while (!$this->queue->isEmpty()) {
        $task = $this->queue->dequeue();
        $command = $task->resume();

        if (TaskStatus::DONE === $task->getStatus()) {
            continue;
        }

        match ($command::class) {
            Close::class => $this->handleClose($task, $command),
            Receive::class => $this->handleReceive($task, $command),
            Send::class => $this->handleSend($task, $command),
        };
    }

    // tâches encore en attente : voir "Détecter l'interblocage" plus bas
    $this->assertNoParkedTasks();
}
```

### Équité : la tâche repasse en fin de file

L'ordonnancement est **coopératif** : l'ordonnanceur ne peut pas interrompre les tâches, ce sont les tâches elles-mêmes qui doivent rendre la main avec un `yield`. Bien sûr, une tâche qui effectue un calcul long et complexe à son tour bloque l'ensemble des tâches : il n'y a pas de système de préemption ou d'interruption possible ici.

Chaque tâche n'effectue qu'une action maximum lors de son tour, puis est remise à la fin de la file d'exécution ; ainsi on évite qu'une tâche produise une donnée puis soit relancée immédiatement et monopolise l'exécution.

### Mettre une tâche en attente

Quand une tâche ne peut pas avancer, c'est-à-dire lorsque sa `Command` ne peut être traitée immédiatement (`Send` sur un canal plein, `Receive` sur un canal vide), l'ordonnanceur la **met en attente** : il ne la remet pas dans la file d'exécution, et la range dans une file d'attente propre au canal concerné.

Pour chaque canal, l'ordonnanceur tient en fait deux files d'attente :
- les **récepteurs** en attente d'une valeur ;
- les **émetteurs** en attente d'une place, avec la valeur qu'ils veulent envoyer (c'est le rôle de la petite classe `ParkedSender` : une tâche et sa valeur).

Une tâche en attente n'est plus relancée, jusqu'à ce qu'une autre tâche fasse évoluer le canal et que l'ordonnanceur la réveille.

### Un déroulé tour par tour

Déroulons l'exécution sur deux cas minimaux, avec un canal de capacité 1. Les traces ont été vérifiées avec le vrai `Scheduler`.

**Premier cas : le consommateur attend.** Le consommateur `C` lit le canal jusqu'à la fin du flux, avec la boucle `while` vue plus haut. Le producteur `P` envoie `'a'`, puis ferme le canal. `C` est confié à l'ordonnanceur en premier.

| Tour | Tâche relancée   | Commande produite | Ce que fait l'ordonnanceur                                                         | Canal       | File d'exécution après le tour |
|------|------------------|-------------------|------------------------------------------------------------------------------------|-------------|--------------------------------|
| 0    |                  |                   | tâches confiées dans l'ordre C, P                                                  | vide        | C, P                           |
| 1    | C                | `Receive`         | canal vide : `C` est mis en attente                                                | vide        | P                              |
| 2    | P                | `Send 'a'`        | dépose `'a'` ; un récepteur attend : `C` est réveillé avec `'a'` ; `P` est réveillé | vide        | C, P                           |
| 3    | C (reçoit `'a'`) | `Receive`         | canal vide : `C` est de nouveau mis en attente                                     | vide        | P                              |
| 4    | P                | `Close`           | ferme le canal ; `C` est réveillé avec `null` ; `P` est réveillé                   | vide, fermé | C, P                           |
| 5    | C (reçoit `null`)| aucune            | fin de flux : la boucle se termine, `C` est terminée                               | vide, fermé | P                              |
| 6    | P                | aucune            | `P` est terminée                                                                   | vide, fermé | (vide)                         |

La file d'exécution est vide et plus aucune tâche n'est en attente : le traitement est terminé. Au tour 2, `'a'` ne reste pas dans le canal : il est aussitôt remis au récepteur qui attendait. Le réveil avec `null` du tour 4 est détaillé plus bas, avec la fin du flux.

**Second cas : un producteur attend.** Deux producteurs `P1` et `P2` envoient chacun une valeur dans le même canal, et le consommateur `C` en lit deux. Cette fois, les producteurs sont lancés en premier.

| Tour | Tâche relancée   | Commande produite | Ce que fait l'ordonnanceur                                                                          | Canal   | File d'exécution après le tour |
|------|------------------|-------------------|-----------------------------------------------------------------------------------------------------|---------|--------------------------------|
| 0    |                  |                   | tâches confiées dans l'ordre P1, P2, C                                                              | vide    | P1, P2, C                      |
| 1    | P1               | `Send 'a'`        | canal pas plein : dépose `'a'` ; `P1` est réveillé                                                  | `a`     | P2, C, P1                      |
| 2    | P2               | `Send 'b'`        | canal plein : `P2` est mis en attente, avec `'b'`                                                   | `a`     | C, P1                          |
| 3    | C                | `Receive`         | `C` est réveillé avec `'a'` ; une place se libère : `'b'` est déposé pour `P2`, qui est réveillé    | `b`     | P1, C, P2                      |
| 4    | P1               | aucune            | `P1` est terminée                                                                                   | `b`     | C, P2                          |
| 5    | C (reçoit `'a'`) | `Receive`         | `C` est réveillé avec `'b'`                                                                         | vide    | P2, C                          |
| 6    | P2               | aucune            | `P2` est terminée                                                                                   | vide    | C                              |
| 7    | C (reçoit `'b'`) | aucune            | `C` est terminée                                                                                    | vide    | (vide)                         |

### Réveiller des tâches

Ces deux déroulés montrent la règle principale : relancer une tâche signifie que sa dernière commande est terminée. Au tour 3 du second cas, l'ordonnanceur ne peut donc pas se contenter de remettre `P2` dans la file d'exécution : il doit d'abord déposer sa valeur `'b'` dans le canal, sinon elle serait perdue silencieusement. De la même façon, un récepteur en attente n'est réveillé qu'avec une valeur : la plus ancienne disponible dans le canal.

Dans les deux cas, on réveille la tâche qui attend depuis le plus longtemps : on lui indique avec quelle valeur elle sera relancée, et on la remet dans la file d'exécution. C'est le rôle de la méthode `answer()` :

```php
/**
 * Resumes the task with a value on its next turn.
 */
private function answer(Task $task, mixed $value = null): void
{
    $task->resumeWith($value);
    $this->queue->enqueue($task);
}
```

Voici le traitement d'un `Send` :

```php
private function handleSend(Task $task, Send $command): void
{
    $channel = $command->channel;

    if ($channel->isClosed()) {
        $this->fail($task, 'Channel closed, cannot send.');

        return;
    }
    
    if ($channel->isFull()) {
        // canal plein : l'émetteur est mis en attente avec sa valeur (tour 2 du second cas) ;
        $this->parkedSendersOf($channel)->enqueue(new ParkedSender($task, $command->payload));

        return;
    }
    
    // dépôt de la valeur
    $channel->push($command->payload);

    if (!$this->parkedReceiversOf($channel)->isEmpty()) {
        // si un récepteur attendait, il est réveillé avec la plus ancienne valeur du canal
        // (tour 2 du premier cas).
        $this->answer($this->parkedReceiversOf($channel)->dequeue(), $channel->shift());
    }

    $this->answer($task);
}
```

Le traitement d'un `Receive` est le symétrique :

```php
private function handleReceive(Task $task, Receive $command): void
{
    $channel = $command->channel;

    if ($channel->isDrained()) {
        // canal épuisé (fermé et vide) : le récepteur est réveillé avec `null`, c'est la fin du flux
        $this->answer($task);

        return;
    }

    if ($channel->isEmpty()) {
        // canal vide : le récepteur est mis en attente (tours 1 et 3 du premier cas) ;
        $this->parkedReceiversOf($channel)->enqueue($task);

        return;
    }

    // réveil avec la plus ancienne valeur
    $this->answer($task, $channel->shift());

    // la place libérée revient à l'émetteur qui attend depuis le plus longtemps : sa valeur est déposée,
    // puis il est réveillé (tour 3 du second cas).
    if (!$this->parkedSendersOf($channel)->isEmpty()) {
        $sender = $this->parkedSendersOf($channel)->dequeue();
        $channel->push($sender->value);
        $this->answer($sender->task);
    }
}
```

`parkedSendersOf()` et `parkedReceiversOf()` renvoient simplement les deux files d'attente du canal.

### Gérer les erreurs

Comme on l'a vu juste au-dessus, en cas d'erreur le `Scheduler` appelle sa méthode `fail` :

```php
/**
 * Raises an exception at the task's yield on its next turn.
 */
private function fail(Task $task, string $message): void
{
    $task->failWith(new \LogicException($message));
    $this->queue->enqueue($task);
}
```

De la même manière qu'on réveille une tâche en lui fournissant la valeur à utiliser lors de son prochain tour, on demande ici à la tâche d'échouer à son prochain tour en lui passant un `Throwable`. La tâche, une fois son tour venu, pourra gérer spécifiquement l'erreur et/ou utiliser `finally` pour par exemple nettoyer des ressources (descripteurs de fichiers, connexions externes...).

### L'état d'une tâche est local

Une tâche en attente garde tout son état jusqu'à ce qu'elle soit réveillée. L'ordonnanceur n'en sait rien et n'a rien à sauvegarder. 
C'est ce qui permet d'écrire des boucles simples dans les tâches, comme le montre le `Batcher` de [l'ETL de l'article principal](https://github.com/wilzwert/generator-examples/blob/main/ARTICLE.md), dont le buffer est une simple variable locale.

Le wrapper `GeneratorTask` n'ajoute que ce dont l'ordonnanceur a besoin autour du générateur : son statut, et la valeur ou l'erreur à lui transmettre à son prochain tour. Tout l'état métier (compteurs, buffers...) reste dans les variables locales du générateur.


## La fin du flux : réveiller avec `null`

Une tâche consommatrice ne sait pas combien de valeurs vont arriver. Elle lit donc jusqu'à la fin du flux, avec la boucle `while (null !== ($value = yield new Receive($in)))` vue plus haut.

Quand un canal est épuisé (fermé et vide), l'ordonnanceur répond `null` au `Receive`. L'expression `yield` vaut `null`, la boucle se termine, et la tâche exécute **le code qui suit la boucle** : vider ce qui reste, fermer ses propres canaux de sortie. La fin du flux se propage donc.

À la fermeture d'un canal, l'ordonnanceur réveille **tous** les récepteurs en attente avec `null` (alors qu'un `Send` n'en réveille qu'un) : s'ils attendent, le buffer est vide, donc le canal est désormais épuisé pour chacun d'eux. Les émetteurs encore en attente, eux, reçoivent une erreur : leur valeur ne pourra jamais être livrée.

```php
private function handleClose(Task $task, Close $command): void
{
    $channel = $command->channel;

    if ($channel->isClosed()) {
        $this->fail($task, 'Channel already closed');

        return;
    }

    $channel->close();

    // parked receivers imply an empty buffer: the channel is now drained, answer with end of stream
    $receivers = $this->parkedReceiversOf($channel);
    while (!$receivers->isEmpty()) {
        $this->answer($receivers->dequeue());
    }

    // parked senders can never deliver their value
    $senders = $this->parkedSendersOf($channel);
    while (!$senders->isEmpty()) {
        $this->fail($senders->dequeue()->task, 'Channel closed while sending.');
    }

    $this->answer($task);
}
```

Le `Close` est indispensable parce que, du côté du consommateur, un canal vide à un instant T ne signifie pas que le canal ne recevra jamais de donnée.
Fermer explicitement un canal est donc le seul moyen fiable d'informer ses consommateurs.

À noter : fermer deux fois le même canal n'arrête pas tout le pipeline, seule la tâche fautive reçoit une erreur.

## Détecter l'interblocage

Quand la file d'exécution est vide, plus personne ne peut avancer. Il n'y a que deux possibilités :
- aucune tâche n'est en attente : tout est terminé, fin normale ;
- des tâches sont encore en attente : elles ne pourront plus être réveillées. C'est un **interblocage** (deadlock).

Cette détection ne coûte qu'un test après la boucle. L'ordonnanceur lève alors une exception qui liste les tâches bloquées.

Exemple minimal : deux tâches qui attendent chacune une valeur de l'autre avant d'envoyer la leur.

```php
$ab = new Channel(1, 'ab'); // de A vers B
$ba = new Channel(1, 'ba'); // de B vers A

$scheduler = new Scheduler();
$scheduler->spawn('A', (function () use ($ab, $ba): \Generator {
    $value = yield new Receive($ba);
    yield new Send($ab, 'pong');
})());
$scheduler->spawn('B', (function () use ($ab, $ba): \Generator {
    $value = yield new Receive($ab);
    yield new Send($ba, 'ping');
})());

$scheduler->run();
```

    Deadlock: all tasks are blocked
      - A: waiting to receive on "ba"
      - B: waiting to receive on "ab"

Un cas plus sournois, où une seule tâche se bloque pendant que les autres avancent, est illustré sur l'ETL de [l'article principal](https://github.com/wilzwert/generator-examples/blob/main/ARTICLE.md).


## Et ensuite

Avec ces quatre pièces (tâches, commandes, canaux, ordonnanceur), on dispose d'un petit runtime de coroutines coopératives : des tâches écrites comme de simples boucles, qui communiquent par des canaux bornés, avec une fin de flux explicite et une détection d'interblocage.

[L'article principal](https://github.com/wilzwert/generator-examples/blob/main/ARTICLE.md) l'utilise pour un ETL de fichier CSV avec traitement par lots : découpage des tâches, mesure de la mémoire, interblocage partiel et contre-pression, et surtout la question de savoir quand cette architecture vaut son prix face à une simple chaîne de générateurs.
