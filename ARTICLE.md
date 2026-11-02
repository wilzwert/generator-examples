# Générateurs PHP : itération paresseuse, flux et orchestration de tâches 

J'ai récemment eu l'occasion d'assister à plusieurs conférences lors de l'API Platform Conference 2026 (au passage, merci Webnet pour cette opportunité !).

Généralement, on repart de ces événements avec le cerveau bouillonnant d'idées, de nouveaux outils ou technologies à tester, mais aussi de remises en question sur des notions connues mais finalement pas maîtrisées. C'est précisément de ce dernier point que je vais parler dans cet article, et qui me vient d'un talk très intéressant de Sébastien Rogier (Semji) qui nous a présenté l'évolution d'une stack LLM en PHP sur 5 ans.

Au fur et à mesure de la présentation, en plus de la découverte de l'architecture de la stack LLM Semji, de ses abstractions et de ses évolutions, j'ai en particulier remarqué une utilisation intensive de l'instruction `yield` et des `Generator`, dans le contexte entre autres de streaming de réponses.
J'ai ainsi pris conscience que je n'en avais pas croisé énormément dans les codebases sur lesquelles j'ai travaillé, et surtout que je n'avais jamais réellement approfondi ce que sont les générateurs et coroutines (honte sur moi).

Cet article vise donc à présenter 2 exemples concrets d'utilisation de générateurs : streamer de multiples réponses de LLM (via SSE) et créer une pseudo-pipeline ETL sur un fichier CSV. 

> Tout le code de ce projet d'apprentissage est disponible ici : https://github.com/wilzwert/generator-examples

> L'objectif ici n'était pas d'écrire du code production-ready correctement testé, mais simplement d'essayer de mieux comprendre dans quels cas l'utilisation de générateurs peut être intéressante.

> Le code du projet nécessite PHP 8.5 ou supérieur (il utilise notamment l'opérateur pipe `|>` et la fonction `array_last()`, ainsi que des property hooks de PHP 8.4 dans une interface).

## 1. L'essentiel à savoir

Si les générateurs ne vous sont pas familiers, j'ai regroupé des rappels (évaluation paresseuse, `yield from`, nettoyage avec `finally`...) et un benchmark dans [un article à part](ARTICLE_rappels.md). Je reprends ici ce qui est essentiel pour la suite. 

Une fonction qui contient `yield` renvoie un `Generator` : son corps ne s'exécute qu'au fil de la consommation, une valeur à la fois, et son état (ses variables locales) est conservé entre deux valeurs.

Moins connu : un générateur peut aussi recevoir des données. C'est l'appelant qui les lui envoie avec sa méthode `send()`.

Un générateur est une coroutine : une fonction qui peut suspendre son exécution, puis la reprendre là où elle s'était arrêtée.

Voici un petit récapitulatif des manières dont on peut utiliser `yield` :

    yield;                    suspend l'exécution, produit null
    yield $value;             suspend l'exécution, produit $value
    $received = yield;        suspend, produit null, puis reçoit la valeur passée à send()
    $received = yield $value; suspend, produit $value, puis reçoit la valeur passée à send()

Petit exemple : un générateur qui tient un total cumulé, et à qui on envoie des montants.

```php
function runningTotal(): \Generator
{
    $total = 0;
    while (true) {
        $amount = yield $total;
        $total += $amount;
    }
}

$gen = runningTotal();
echo $gen->current(), "\n";
echo $gen->send(10), "\n";
echo $gen->send(5), "\n";
```

Sortie :

    0
    10
    15

Il se passe pas mal de choses ici :
- `current()` démarre le générateur : son corps s'exécute jusqu'au premier `yield`, qui produit `0` (le total initial) ;
- `send(10)` fait trois choses d'un coup : il relance le générateur, donne la valeur `10` à l'expression `yield` (qui se retrouve dans `$amount`), puis laisse le générateur s'exécuter jusqu'au `yield` suivant, dont il renvoie la valeur (`10`) ;
- entre deux appels, `$total` est conservé : c'est une simple variable locale, que le générateur garde tant qu'il est suspendu.

Important : sur un générateur pas encore démarré, `send()` l'exécute d'abord jusqu'au premier `yield`, puis envoie sa valeur. La valeur produite par ce premier `yield` (ici le `0`) est alors perdue pour l'appelant. D'où l'appel à `current()` en premier.

On peut également utiliser `throw()`, qui fonctionne sur le même principe que `send()`. Au lieu de fournir une valeur, il lève une exception au niveau du `yield` où le générateur est suspendu. Le générateur peut l'attraper avec un `try/catch` autour du `yield`, comme n'importe quelle exception.

On reverra ces trois méthodes en section 3, où eà l'endroitlles serviront à piloter des tâches.

### Ce que les générateurs apportent (et ce qu'ils n'apportent pas)

Tout s'exécute dans un seul thread. On ne parle donc pas ici de parallélisme (exécuter plusieurs tâches au même instant), mais de concurrence : plusieurs tâches en cours progressent chacune leur tour, en alternance, ce qui donne l'impression qu'elles avancent en même temps.

Les générateurs ne rendent pas en eux-mêmes un traitement plus rapide. C'est l'association avec des I/O non bloquantes, comme `curl_multi` qu'on utilisera plus tard, qui permet de réduire le temps d'exécution total : les attentes réseau se chevauchent au lieu de s'additionner. Le générateur, lui, sert à structurer ce code.

Tout ce que l'on écrit avec des générateurs peut aussi être écrit autrement, que ce soit en utilisant des tableaux ou de la POO classique. On verra plus tard que l'intérêt est entre autres dans la lisibilité du code et dans le maintien de l'état local conservé à chaque `yield` sans avoir à créer de classes.

Pour chacun des deux exemples suivants, on verra les alternatives sans générateur, et dans quels cas le générateur vaut mieux.

## 2. Premier exemple : le multi-stream

### Le besoin

Ce premier exemple m'a été directement inspiré par la stack IA de Semji, et en particulier dans un contexte de streaming : envoyer une même question à plusieurs fournisseurs d'IA (LLM) et afficher leurs réponses en "streaming" dans le navigateur.

Le streaming intervient ici à deux niveaux :
- les fournisseurs nous envoient leurs réponses morceau par morceau, en Server-Sent Events (SSE), un format texte très simple : chaque événement est une ou plusieurs lignes `data: ...`, terminées par une ligne vide ;
- nous retransmettons ces morceaux au navigateur au fur et à mesure, là aussi en SSE.

### Côté consommateur : un simple `foreach`

Commençons par la fin, c'est-à-dire par ce que voit le code qui utilise notre multi-stream. Le script appelé par le navigateur (`public/multi-stream.php`) est une simple boucle :

```php
foreach ($multiStream->run($providerNames, $prompt) as $event) {
    if (connection_aborted()) {
        break;
    }

    echo 'data: ', json_encode($event, \JSON_THROW_ON_ERROR), "\n\n";
    flush();
}
```

`run()` renvoie un générateur, et chaque `$event` est un morceau de réponse (un `Event`) d'un des fournisseurs. Le script ne sait rien du fonctionnement interne de notre multi-stream, : il reçoit simplement des événements et les envoie au navigateur, et s'arrête si le client se déconnecte.

À noter : `connection_aborted()` n'est mis à jour qu'après une tentative d'envoi au client (ici le `echo` + `flush()` du tour précédent). Par ailleurs, par défaut, PHP interrompt le script dès qu'il détecte que le client s'est déconnecté. Pour que ce test serve à quelque chose, le script appelle donc `ignore_user_abort(true)` avant la boucle.

### Côté producteur : `MultiStream::run()`

Pour interroger plusieurs fournisseurs en même temps, on utilise `curl_multi`, qui permet de gérer plusieurs requêtes HTTP simultanément dans un seul processus PHP. Voici le squelette de `run()` :

```php
public function run(array $providerNames, string $userMessage): \Generator
{
    // ... un StreamExchange et un handle curl par fournisseur, ajoutés au handle "multi"

    try {
        do {
            // fait avancer tous les transferts, sans bloquer
            curl_multi_exec($multi, $running);

            // produit les événements disponibles, fournisseur par fournisseur
            foreach ($exchanges as $exchange) {
                yield from $exchange->getEvents();
            }

            // ... fins de transfert (voir plus bas)

            if ($running > 0) {
                // attend (au plus 0,1 s) que des données arrivent
                curl_multi_select($multi, 0.1);
            }
        } while ($running > 0);
    } finally {
        // s'exécute aussi quand l'appelant arrête d'itérer, par exemple après une déconnexion du client
        foreach ($handles as $handle) {
            curl_multi_remove_handle($multi, $handle);
        }
        curl_multi_close($multi);
    }
}
```

Le principe : à chaque tour de boucle, `curl_multi_exec()` fait avancer tous les transferts sans attendre, et indique dans `$running` combien sont encore en cours. Puis le générateur produit, avec `yield from`, tous les événements devenus disponibles. Et c'est là que la magie opère : à chaque `yield`, `run()` se suspend, le `foreach` du consommateur envoie l'événement au navigateur, puis relance le générateur. 
Enfin, `curl_multi_select()` évite que la boucle tourne à vide en attendant de nouvelles données.

Ici, le générateur n'est utilisé que comme producteur, sans `send()`.

### Derrière chaque réponse : un échange

Chaque fournisseur a son propre format de réponse, et curl ne fournit que des octets bruts, découpés au hasard du réseau. 
Chaque requête envoyée à un fournisseur est donc représentée par un `StreamExchange`, qui transforme ces octets en événements en deux étapes :

    octets reçus par curl
         │
         ▼
    StreamExchange (un par fournisseur)
         ├─ MessageSplitter : octets  ──> Message  (découpage, par exemple selon le format SSE)
         └─ MessageDecoder  : Message ──> Event    (un Event est un objet structuré qui atoute du sens à un message : morceau de texte, fin de réponse, erreur)

Le splitter et le décodeur sont fournis par un `Provider`, propre à chaque fournisseur ou modèle, qui sait aussi construire la requête HTTP à envoyer. Un registre (`ProviderRegistry`) nous permet de définir et maintenir une liste de `Provider` associés à un nom.

L'échange reçoit les octets avec sa méthode `receiveBytes()`, qui les confie au splitter. Et `getEvents()`, celle que `run()` appelle à chaque tour, est elle-même un générateur, construit à partir des générateurs du splitter et du décodeur :

```php
public function getEvents(): \Generator
{
    foreach ($this->messageSplitter->getMessages() as $message) {
        yield from $this->wrap($this->messageDecoder->decode($message));
    }
}
```

`getMessages()` produit les messages complets disponibles, `decode()` produit les événements de chacun, et `wrap()` se contente d'y ajouter le nom du fournisseur. Tout est produit à la demande : seuls les octets reçus depuis le tour précédent attendent dans le splitter (sous forme d'un buffer), le temps d'être découpés et décodés.

### Sous le capot avec `curl_multi`

**Faire parvenir les octets au bon échange.** Le lien entre curl et nos générateurs se fait grâce à l'option `\CURLOPT_WRITEFUNCTION` : curl appelle ce callback chaque fois qu'il reçoit des octets, ce qui permet de les transmettre au bon `StreamExchange` :

```php
curl_setopt_array($handle, $this->curlOptions($exchange->request) + [
    // Bytes go to the write callback instead of accumulating in curl.
    \CURLOPT_RETURNTRANSFER => false,
    \CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $data) use ($exchange, $name, &$errorBodies): int {
        $status = curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        if ($status < 200 || $status >= 300) {
            // keep the body for the error message, don't give it to the parser
            $errorBodies[$name] = substr($errorBodies[$name].$data, 0, self::MAX_ERROR_BODY_BYTES);
        } else {
            $exchange->receiveBytes($data);
        }

        // curl expects the number of bytes handled; anything else aborts the transfer.
        return \strlen($data);
    },
    \CURLOPT_TIMEOUT => $this->timeoutSeconds,
]);
```

Quand le fournisseur répond avec un statut d'erreur (clé d'API invalide, service surchargé...), le corps de la réponse n'est pas un flux d'événements : il est mis de côté (tronqué à 1 Ko) pour construire le message d'erreur.

`curl_multi_exec()` (voir https://www.php.net/manual/fr/function.curl-multi-exec.php) ne bloque pas : à chaque appel, il fait avancer tous les transferts autant que possible sans attendre (envoi des requêtes, lecture des octets déjà arrivés, ce qui déclenche nos callbacks), puis rend la main en indiquant dans `$running` le nombre de transferts encore en cours. C'est donc la boucle `do/while` qui mène les transferts à leur terme.

Entre deux tours, `curl_multi_select()` attend, avec un délai maximum, que des données soient disponibles sur l'une des connexions. Sans lui, la boucle tournerait à vide et occuperait un CPU à 100 %.

**Les fins de transfert.** À chaque tour, `run()` demande aussi à curl quels transferts viennent de se terminer, et appelle pour chacun la méthode `finish()` de l'échange :

```php
while ($info = curl_multi_info_read($multi)) {
    $name = array_search($info['handle'], $handles, true);
    if (false === $name) {
        continue;
    }
    yield from $exchanges[$name]->finish($this->failureReason($info['handle'], $info['result'], $errorBodies[$name]));
}
```

`finish()` est elle aussi un générateur. Elle peut produire le cas échéant un événement d'erreur si le transfert a échoué, ou bien traiter les éventuelles données encore présentes dans le splitter (`MessageSplitter::flush`), avec un événement `done` en fin de traitement. 

`MessageSplitter::flush()` (à ne pas confondre avec la fonction PHP `flush()` utilisée par le consommateur) produit les derniers messages (texte brut), ou les jette s'ils sont incomplets (en SSE, un événement qui ne se termine pas par une ligne vide doit être ignoré).

### L'inversion du sens des données

Avec `curl_multi`, curl nous transmet les octets via un callback au rythme où ils arrivent. Sans générateur, il y a deux façons de transmettre ces données à ceux qui les utilisent :
- **Callbacks / listeners** : `MultiStream` reçoit un consommateur et l'appelle pour chaque événement. Dans ce cas, le producteur dépend de son consommateur : tout ce qui sert à envoyer les événements au client (écriture SSE, `flush()`...) doit lui être injecté, et l'arrêt du flux doit être géré explicitement.
- **Un `Iterator` écrit à la main** : comme avec le générateur, le consommateur tire les événements avec un `foreach`. Mais la boucle `curl_multi_exec` doit être découpée entre `current()`, `next()` et `valid()`, et tout ce qui était une variable locale (handle multi, échanges, buffer d'événements en attente, phase en cours) devient une propriété. En quelque sorte on écrit à la main une forme de générateur.

Pour rendre la première option plus concrète, voici à quoi ressemblerait notre multi-stream avec un listener, ici un `callable` :

```php
// le producteur reçoit le consommateur...
public function run(array $providerNames, string $userMessage, callable $onEvent): void
{
    // ... même boucle curl_multi, mais à la place de chaque yield :
    if (false === $onEvent($event)) {
        return;
    }
}

// ... et le script doit lui confier tout ce qu'il fait de chaque événement
$multiStream->run($providerNames, $prompt, function (Event $event): bool {
    if (connection_aborted()) {
        return false;
    }

    echo 'data: ', json_encode($event, \JSON_THROW_ON_ERROR), "\n\n";
    flush();

    return true;
});
```

Ça fonctionne, mais `run()` doit maintenant connaître la forme du callback, et l'arrêt repose sur une convention (ici, renvoyer `false`) qu'il faut documenter et respecter. 
Et les événements ne peuvent plus être consommés autrement : impossible de les parcourir avec un `foreach`, ou de les passer à un autre générateur pour les filtrer.

Avec nos générateurs, le sens est en grande partie inversé : le callback passé à `curl_multi` permet d'envoyer les données dans le bon `StreamExchange`, et c'est ensuite le consommateur qui **tire** les événements avec un simple `foreach` sans rien connaître de curl, des providers. 
Le consommateur dépend du producteur et non l'inverse, et c'est lui également qui décide du rythme de consommation et de l'arrêt : après un `break`, le générateur n'est plus référencé et il est détruit, ce qui exécute son bloc `finally` et libère les handles curl. C'est le cas ici parce que `run()` est appelé directement dans le `foreach` ; si le générateur était conservé dans une variable, il ne serait détruit qu'à la disparition de celle-ci.


### Pourquoi un générateur ici ?

| Alternative                                                                   | Ce qu'elle coûte                                                                          |
|-------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------|
| construction d'un tableau de toutes les réponses avant renvoi au consommateur | plus de streaming : l'utilisateur attend la réponse la plus lente avant de voir quoi que ce soit |
| callback ou listener injecté                                                  | le producteur dépend du consommateur ; l'arrêt demande une convention                     |
| `Iterator` écrit à la main                                                    | une "machine à états" : propriétés handles, buffer, phase courante, logique répartie entre `current()` et `next()` |

Ce que le générateur apporte :
- **découplage** : `MultiStream` ne connaît ni SSE, ni `flush()`, ni `connection_aborted()`. Le même producteur sert une page web, une commande console ou un test, sans rien lui injecter ;
- **inversion de contrôle sans interface** : avec un listener, le producteur pousse les valeurs, et il faut une interface pour qu'il ne dépende pas d'une classe concrète. Avec un générateur, c'est le consommateur qui tire les valeurs : le producteur n'a besoin de connaître personne, et le consommateur décide quoi faire de chaque valeur ;
- **un code qui se lit de haut en bas** : l'initialisation, la boucle et le nettoyage sont dans la même fonction, avec des variables locales. Pas de propriétés pour "se souvenir où on en était" ;
- **arrêt et nettoyage gratuits** : un `break` suffit, et le `finally` garantit la libération des handles curl dès que le générateur est détruit, y compris quand le client se déconnecte (grâce à `ignore_user_abort(true)`) ;
- **composition** : un autre générateur pourrait par exemple filtrer ou transformer les événements sans toucher au producteur ;
- **rythme imposé par le consommateur** : `curl_multi_exec` n'est appelé que quand le consommateur demande la valeur suivante. Un consommateur lent ralentit donc la lecture côté PHP : les données continuent d'arriver dans les buffers réseau du système, puis, une fois ceux-ci pleins, TCP ralentit l'envoi côté fournisseur. Le flux entier n'est jamais chargé dans PHP. Attention toutefois : `CURLOPT_TIMEOUT` porte sur la durée totale du transfert, donc un consommateur très lent peut provoquer un timeout.

> Le code de cet exemple se trouve principalement dans le répertoire `src/Stream` du projet. En lançant l'environnement docker du projet, vous pouvez accéder à une page de test sur http://localhost:8080/example-multi-stream.php.
> Pour le moment seuls 2 providers "réels" sont fournis, avec des implémentations (très) rudimentaires des splitters et décodeurs : Anthropic (haiku ou opus) et OpenRouter (free). Chacun nécessite des clés d'API qui peuvent être configurées dans le fichier .env du projet (voir .env.example).   


## 3. Une architecture de coroutines : tâches, commandes, canaux, ordonnanceur

Dans notre exemple précédent, on a un seul producteur et un seul consommateur ; même si notre multi-stream permet de déléguer bon nombre de traitements à d'autres générateurs via nos splitters et decoders, il reste aussi le seul point d'accès aux données, qui sont consommées par un simple foreach. 
On n'a donc pas de notion de "communication" entre plusieurs générateurs.

> Avant d'aller plus loin, une petite précision : tout ce qui suit s'inspire énormément de l'article de Nikita Popov, [Cooperative multitasking using coroutines (in PHP!)](https://www.npopov.com/2012/12/22/Cooperative-multitasking-using-coroutines-in-PHP.html), écrit en 2012 au moment de l'arrivée des générateurs dans PHP 5.5. On y retrouve déjà une tâche qui enveloppe un générateur, une file d'exécution, des tâches qui produisent des demandes avec `yield` (ses `SystemCall`), et `throw()` pour leur renvoyer une erreur.
>
> Nos objectifs sont toutefois différents. Popov construit une sorte de mini système d'exploitation : les tâches peuvent connaître leur identifiant, en créer d'autres ou les tuer, attendre des sockets avec `stream_select()`, et il termine sur un petit serveur HTTP non bloquant. Il gère aussi à la main les appels entre coroutines, puisque `yield from` n'existait pas encore. 
>
> De mon côté, il s'agit d'un projet différent et conceptuellement bien plus simple : je me suis concentré sur la communication basique entre tâches, avec des canaux de capacité limitée, une forme de contre-pression, la fin de flux et la détection d'interblocage.
>
> Rappelons qu'il s'agit d'un projet d'apprentissage : le but était de reprendre certaines des idées de Popov pour mieux les comprendre, en les appliquant à un autre problème. Je me suis également inspiré de certaines notions découvertes en lisant (sans totalement comprendre :D) des articles sur les Goroutines ou les coroutines en Kotlin.

Nous allons voir ici comment on peut justement imaginer un système de coroutines coopératives très simplifié, ici pour un ETL dont le rôle sera simplement de :
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

### Le canal

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


#### Fermé n'est pas vide

`close()` signifie que les écritures ne peuvent plus s'effectuer, mais pas que le buffer doit être immédiatement vidé. Étant donné que le producteur ferme son canal juste après son dernier envoi, vider le buffer avant que le consommateur ait pu récupérer les données entraînerait des pertes. 
On a donc 2 états : 
- **fermé** : le producteur a indiqué avoir terminé, mais des valeurs peuvent rester dans le buffer ;
- **épuisé** (fermé et vide) : la vraie fin du flux du point de vue du consommateur.

Dans notre exemple, envoyer `null` dans un canal est interdit, car c'est par choix la valeur qui est donnée à un consommateur quand le canal est épuisé. 
Ce cas est vérifié dès la construction de la commande : `new Send($channel, null)` lève une `LogicException`. 

### Les commandes

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

### Les tâches : un générateur et son état

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

#### Piloter une tâche à la main

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

### L'ordonnanceur : tour de rôle, mise en attente, réveil

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

#### Équité : la tâche repasse en fin de file

L'ordonnancement est **coopératif** : l'ordonnanceur ne peut pas interrompre les tâches, ce sont les tâches elles-mêmes qui doivent rendre la main avec un `yield`. Bien sûr, une tâche qui effectue un calcul long et complexe à son tour bloque l'ensemble des tâches : il n'y a pas de système de préemption ou d'interruption possible ici.

Chaque tâche n'effectue qu'une action maximum lors de son tour, puis est remise à la fin de la file d'exécution ; ainsi on évite qu'une tâche produise une donnée puis soit relancée immédiatement et monopolise l'exécution.

#### Mettre une tâche en attente

Quand une tâche ne peut pas avancer, c'est-à-dire lorsque sa `Command` ne peut être traitée immédiatement (`Send` sur un canal plein, `Receive` sur un canal vide), l'ordonnanceur la **met en attente** : il ne la remet pas dans la file d'exécution, et la range dans une file d'attente propre au canal concerné.

Pour chaque canal, l'ordonnanceur tient en fait deux files d'attente :
- les **récepteurs** en attente d'une valeur ;
- les **émetteurs** en attente d'une place, avec la valeur qu'ils veulent envoyer (c'est le rôle de la petite classe `ParkedSender` : une tâche et sa valeur).

Une tâche en attente n'est plus relancée, jusqu'à ce qu'une autre tâche fasse évoluer le canal et que l'ordonnanceur la réveille.

#### Un déroulé tour par tour

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

#### Réveiller des tâches

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

#### Gérer les erreurs

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

#### L'état d'une tâche est local

Une tâche en attente garde tout son état jusqu'à ce qu'elle soit réveillée. L'ordonnanceur n'en sait rien et n'a rien à sauvegarder. 
C'est ce qui permet d'écrire des boucles simples dans les tâches, et on le verra plus loin avec le `Batcher` de notre ETL de démo, dont le buffer est une simple variable locale.

Le wrapper `GeneratorTask` n'ajoute que ce dont l'ordonnanceur a besoin autour du générateur : son statut, et la valeur ou l'erreur à lui transmettre à son prochain tour. Tout l'état métier (compteurs, buffers...) reste dans les variables locales du générateur.


### La fin du flux : réveiller avec `null`

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

### Détecter l'interblocage

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

Un cas plus sournois, où une seule tâche se bloque pendant que les autres avancent, est illustré sur l'ETL en section 4.

## 4. Exemple complet : un ETL de fichier CSV avec traitement par lots

> Le code de cet exemple est disponible dans `src/Etl`.
> 
> Pour générer un fichier CSV de test (ici 100 000 lignes) : `make console script="generate-orders 100000"`
> 
> Pour lancer l'ETL : `make console script=pipeline`
> 
> Pour lancer les scénarios de test  : `make console script=etl`

### Le besoin

Lire un fichier CSV de commandes clients, valider chaque ligne, construire des objets `Order`, les regrouper par lots de 100 et écrire chaque lot (ici, on se contente d'afficher une ligne par lot dans la sortie standard). 
Pour éviter toute confusion avec les commandes (`Command`) que les tâches envoient à l'ordonnanceur, je parlerai dans cette partie de **commandes clients** pour les données du fichier.
Les lignes invalides ne doivent pas arrêter le traitement : elles sont écartées et journalisées à part. 
La RAM utilisée ne doit pas dépendre de la taille du fichier.

On va donc découper les étapes et créer des tâches et des canaux :

    lecteur -> "read" -> transformateur -> "orders" -> groupeur -> "batches" -> écrivain
                                        \-> "rejects" -> journal des rejets

Chaque tâche est un générateur qui produit des commandes `Receive` (lire un canal), `Send` (écrire dans un canal) et `Close` (fermer un canal).

### Découper les tâches au bon endroit


| Tâche                          | Responsabilité                                                                                                                                   | Erreurs                                                                                          |
|--------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------|
| lecteur (`Reader`)             | **format** : lecture de fichier CSV, envoi des lignes sous forme d'`array` dans le canal 'read'                                                  | le pipeline s'arrête                                                                             |
| transformateur (`Transformer`) | **lecture dans 'read', validation/transformation** : champs requis, montant positif, date valide... envoi d'objets `Order` dans le canal 'orders' | ligne invalide : envoi dans le canal 'rejects'                                                   |
| groupeur (`Batcher`)           | **lecture dans 'orders', regroupement par 100** : envoi tableau d'objets `Order` dans le canal 'batches'                                         | si un élément n'est pas un `Order` : `\LogicException` : arrêt du pipeline                       |
| écrivain (`Writer`)            | **lecture dans 'batches'** : affichage console                                                                                                   | si un lot est vide ou contient autre chose que des `Order` : `\LogicException` : arrêt du pipeline |
| logger (`RejectLogger`)        | **lecture dans 'rejects'** : affichage console                                                                                                   | si un élément n'est pas un `Reject` : `\LogicException` : arrêt du pipeline                      |


### Les tâches

Chaque tâche est une classe invocable : le constructeur reçoit la configuration et les canaux, `__invoke()` renvoie le générateur. 

Le lecteur :

```php
/**
 * @return \Generator<int, Command, mixed, void>
 */
public function __invoke(): \Generator
{
    if (false === ($fp = fopen($this->path, 'r'))) {
        throw new \RuntimeException('Unable to open the file.');
    }

    try {
        $first = fgetcsv(stream: $fp, escape: '');
        if (false === $first) {
            throw new \RuntimeException('File is empty');
        }

        if (['order_id', 'customer', 'amount', 'createdAt'] !== $first) {
            throw new \RuntimeException('Wrong file format');
        }

        while (false !== ($line = fgetcsv(stream: $fp, escape: ''))) {
            if ([null] !== $line) {
                yield new Send($this->out, $line);
            }
        }

        // properly close the channel
        yield new Close($this->out);
    } finally {
        fclose($fp);
    }
}
```

`escape: ''` désactive le caractère d'échappement propre à PHP, qui ne fait pas partie de la norme CSV (RFC 4180). Depuis PHP 8.4, ne pas préciser ce paramètre déclenche d'ailleurs un avertissement de dépréciation.

Le transformateur : reçoit, valide, instancie et envoie un `Order` ou un `Reject`, puis ferme les canaux. 

```php
/**
 * @return \Generator<int, Command, mixed, void>
 */
public function __invoke(): \Generator
{
    while (null !== ($row = yield new Receive($this->in))) {
        if (!\is_array($row) || !array_is_list($row)) {
            throw new \LogicException('Row should be a list');
        }
        try {
            $order = $this->parseRow($row);
        } catch (InvalidRowException $e) {
            yield new Send($this->rejects, new Reject($row, $e));
            continue;
        }
        yield new Send($this->out, $order);
    }

    yield new Close($this->out);
    yield new Close($this->rejects);
}
```

### Le groupeur : un état local et une fin de flux

Le groupeur accumule les `Order` et envoie un lot quand il en a N (ici 100) :

```php
/**
 * @return \Generator<int, Command, mixed, void>
 */
public function __invoke(): \Generator
{
    /** @var list<Order> $buffer */
    $buffer = [];
    while (null !== ($order = yield new Receive($this->orders))) {
        if (!$order instanceof Order) {
            throw new \LogicException('Orders should be instances of the Order class');
        }
        $buffer[] = $order;
        if (\count($buffer) >= $this->batchSize) {
            yield new Send($this->batches, $buffer);
            $buffer = [];
        }
    }
    if ([] !== $buffer) {
        yield new Send($this->batches, $buffer);
    }
    yield new Close($this->batches);
}
```

Deux points intéressants : 
- le buffer est une variable locale du générateur. Un générateur ne sert qu'une fois, mais chaque appel à `__invoke()` en crée un nouveau, avec son propre buffer : on peut donc faire tourner plusieurs groupeurs sans qu'ils partagent d'état, ce qui est très pratique :)
- une fois reçu le `null` de fin de flux, un éventuel buffer non vide, c'est-à-dire un lot pas encore plein, est envoyé pour éviter de perdre des données.

### La configuration

Seul le script appelant (`cli/pipeline.php`, ici légèrement simplifié) connaît à la fois les canaux, tâches et le scheduler.

```php
$read = new Channel(CHANNEL_CAPACITY, 'read');
$orders = new Channel(CHANNEL_CAPACITY, 'orders');
$batches = new Channel(CHANNEL_CAPACITY, 'batches');
$rejects = new Channel(CHANNEL_CAPACITY, 'rejects');

$scheduler = new Scheduler();
$scheduler->spawn('reader', (new Reader(Paths::ORDERS_FILE_PATH, $read))());
$scheduler->spawn('transformer', (new Transformer($read, $orders, $rejects))());
$scheduler->spawn('batcher', (new Batcher(100, $orders, $batches))());
$scheduler->spawn('writer', (new Writer($batches))());
$scheduler->spawn('logger', (new RejectLogger($rejects))());

$scheduler->run();
```

Ici, `CHANNEL_CAPACITY` vaut 10.

### Confirmation : la mémoire ne dépend pas de la taille du fichier

Le pipeline complet (lots de 100, canaux de capacité 10) est mesuré avec `memory_get_peak_usage()` après `run()`, sur 2 000 puis 100 000 lignes.

Pour vérifier que cette stabilité vient bien du traitement au fil de l'eau, on refait le test en remplaçant notre écrivain par une tâche qui conserve tout ce qu'elle reçoit au lieu de l'écrire (`make console script="pipeline keep"`).

Voici le résultat obtenu : 

|                                 | 2 000 lignes | 100 000 lignes |
|---------------------------------|--------------|----------------|
| écrivain                        | 0,93 Mo      | 0,93 Mo        |
| tâche qui conserve les données  | 1,78 Mo      | 52,40 Mo       |

À retenir :
- la mémoire consommée est liée aux **capacités des canaux et à la taille des lots**, pas à la taille de l'entrée ;
- comme attendu, il suffit d'**une** tâche qui accumule des données pour perdre le bénéfice des générateurs, puisqu'elle contiendra forcément l'ensemble des données en fin d'exécution.

### Interblocage partiel et la contre-pression

L'interblocage n'est détecté que lorsque la file d'exécution est vide (voir section 3). Une tâche coincée pendant que les autres avancent risque donc de n'être signalée qu'à la toute fin : sur un fichier de 10 Go, potentiellement des heures plus tard.

Dans un pipeline, la contre-pression change la donne. Reprenons notre ETL, avec ses canaux de capacité 10, et imaginons que le journal des rejets reste bloqué sur un autre canal que personne n'alimente. Il ne lit donc plus le canal "rejects". La suite se déroule en cascade :

1. le transformateur continue d'envoyer ses rejets, et le canal "rejects" finit par être plein ;
2. au rejet suivant, le transformateur est mis en attente sur son `Send` ;
3. il ne lit plus le canal 'read', que le lecteur remplit à son tour ;
4. le lecteur est mis en attente sur son `Send` ;
5. le groupeur et l'écrivain ne reçoivent plus rien : ils sont, eux aussi, en attente, sur des canaux vides.

Toutes les tâches sont en attente, la file d'exécution se vide, et l'ordonnanceur signale l'interblocage :

    Deadlock: all tasks are blocked
      - batcher: waiting to receive on "orders"
      - writer: waiting to receive on "batches"
      - logger: waiting to receive on "never"
      - reader: waiting to send on "read"
      - transformer: waiting to send on "rejects"

En testant ce scénario sur un fichier de 2 000 lignes, le lecteur n'a envoyé qu'environ 340 lignes avant la détection, et ce nombre ne dépend que des capacités des canaux et de la position des rejets dans le fichier, pas de sa taille. 
Sans limite de capacité, le canal "rejects" aurait grossi jusqu'à la fin du fichier, et le blocage n'aurait été vu qu'à ce moment-là. 
C'est pour cela que nos canaux ont une capacité limitée.

Cette cascade suppose que la tâche bloquée a un producteur en amont. Si elle n'est reliée à aucune autre tâche active (elle attend sur un canal que personne n'alimente, et personne ne lit ce qu'elle produit), rien ne remonte : le reste du traitement se termine normalement, et le blocage n'est détecté qu'à la fin. 
Il existe des mécanismes pour détecter ce cas plus tôt, mais ce n'est pas notre objectif ici.

### Pourquoi des générateurs ici ?

Clairement, pour **cet** ETL, une simple boucle aurait suffi. Parcourir le fichier ligne par ligne, et pour chacune la valider, l'ajouter au lot, écrire le lot quand il est plein, écrire le restant après la boucle. 
Une cinquantaine de lignes, la même mémoire constante, aucun ordonnanceur. Les alternatives, et ce qu'elles coûtent :

| Alternative                                        | Ce qu'elle coûte                                                                                                                                                |
|----------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| une seule boucle procédurale                       | tout est mêlé : format, règles métier, regroupement, écriture ; chaque partie ne se teste qu'avec un fichier, et ne se réutilise pas                            |
| tableaux entre les étapes (`array_map`, `array_chunk`) | tout le fichier en mémoire (comme la "tâche qui conserve les données" de la mesure précédente)                                                              |
| objets en mode "poussée" (`push()` / `flush()`), chacun recevant le suivant par injection | chaque étage dépend du suivant ; l'état (tampon, compteurs) part en propriétés ; la fin de flux devient un `flush()` à propager à la main, et à ne pas oublier  |
| chaîne de générateurs en mode "tirage" (`batch(transform(read($path)))`) | la meilleure alternative pour un flux linéaire, sans ordonnanceur ; mais chaque tâche n'a **qu'une sortie**, son `yield` : pour séparer les rejets, il faut les faire transiter dans le même flux que les commandes clients valides (valeurs étiquetées, filtrées plus loin), ce qui mêle les deux flux |

Pour se faire une idée du mode "poussée", voici notre groupeur réécrit de cette façon :

```php
final class PushBatcher
{
    /** @var list<Order> */
    private array $buffer = [];

    public function __construct(private int $batchSize, private PushWriter $next)
    {
    }

    public function push(Order $order): void
    {
        $this->buffer[] = $order;
        if (\count($this->buffer) >= $this->batchSize) {
            $this->next->push($this->buffer);
            $this->buffer = [];
        }
    }

    public function flush(): void
    {
        if ([] !== $this->buffer) {
            $this->next->push($this->buffer);
            $this->buffer = [];
        }
        $this->next->flush(); // à ne pas oublier, dans chaque étage
    }
}
```

Par rapport au `Batcher` vu plus haut : le tampon est devenu une propriété, la tâche connaît son successeur (`PushWriter`), et la fin de flux, qui était simplement le code situé après la boucle `while`, est devenue une méthode `flush()`. Il faut penser à l'appeler une fois le fichier lu, et chaque étage doit la propager au suivant.

La chaîne de générateurs en tirage mérite qu'on s'y arrête : c'est un outil intéressant tant que le pipeline est une simple ligne. Chaque étage est un générateur qui consomme l'iterable produit par le précédent. Voici le groupeur dans cette version :

```php
/**
 * @param iterable<Order> $orders
 *
 * @return \Generator<int, list<Order>>
 */
function batch(iterable $orders, int $size): \Generator
{
    $buffer = [];
    foreach ($orders as $order) {
        $buffer[] = $order;
        if (\count($buffer) >= $size) {
            yield $buffer;
            $buffer = [];
        }
    }
    if ([] !== $buffer) {
        yield $buffer;
    }
}

foreach (batch(transform(read($path)), 100) as $batch) {
    // écrire le lot
}
```

`read()` et `transform()` ont la même forme : un `foreach` sur l'étage précédent, un `yield` par valeur produite. Pas d'ordonnanceur, pas de canaux, pas de `Close` : la fin de flux est la fin du `foreach`, et la mémoire reste constante, puisque chaque étage ne demande la valeur suivante qu'au moment où il en a besoin.

L'ordonnanceur et les canaux ne se justifient que lorsqu'on a lusieurs sorties ou plusieurs entrées (rejets, répartition vers plusieurs écrivains, fusion de plusieurs sources), ou lorsqu'on veut une contre-pression explicite entre les étages.

Ce que les générateurs apportent alors :
- **un code procédural** : chaque étage est une boucle `while` avec ses variables locales, qui se lit de haut en bas. Pas de machine à états, pas de propriété "où en étais-je", pas de méthode `flush()` : la fin de flux est simplement le code qui suit la boucle ;
- **testabilité** : un étage se pilote à la main (`current()`, `send()`), ou avec une tâche scriptée qui joue le rôle de ses voisins ; la validation, elle, est une méthode ordinaire ;
- **nettoyage garanti** : un `try/finally` par étage libère ses ressources, même quand le pipeline s'arrête sur une erreur.

Et ce qu'apporte l'architecture canaux + ordonnanceur construite par-dessus :
- **découplage** : un étage ne connaît que ses canaux, jamais les autres étages. Le transformateur ignore s'il est alimenté par un fichier, une API ou un test ;
- **câblage centralisé** : aucun étage n'injecte le suivant. Le graphe est dessiné à un seul endroit, le script de câblage, et se modifie sans toucher aux étages ;
- **mémoire bornée et contre-pression** : un producteur rapide ne peut pas inonder un consommateur lent, et la mémoire ne dépend que des capacités et de la taille des lots ;
- **pannes visibles** : un étage oublié, un `Close` manquant ou une boucle d'attente mutuelle donnent un interblocage détecté et décrit, au lieu d'un programme qui tourne à vide ou se termine en silence.
- **de l'amusement** (mais ça, c'est très subjectoif !) : c'est un schéma mental assez différent de ce qu'on a l'habitude de voir dans beaucoup de projets PHP

Le prix à payer : un ordonnanceur à écrire et à comprendre (environ 250 lignes), des canaux non typés, et des erreurs d'un genre nouveau (interblocages, fin de flux oubliée). 
Le choix se fait donc sur la forme du graphe : une ligne, une chaîne de générateurs ; un graphe, des canaux.

## 5. Conclusion

Au départ, j'utilisais des générateurs, parfois sans même le savoir, avec un `foreach` sur un iterable, ou des `yield`, bien souvent dans des tests unitaires.
A part l'avantage en terme d'occupation mémoire, je ne m'étais jamais vraiment posé de questions sur leur nature et leurs autres propriétés et capacités.

En creusant, j'ai découvert ou approfondi des notions très intéressantes (concurrence, coroutines, inversion du flux de données...) qui sont pour certaines plus connues dans d'autres langages. 
C'est aussi amusant de voir que, Malgré son statut d'"ancêtre" parfois très critiqué pour ses limites (réelles ou imaginaires, mais c'est un autre débat :)), PHP propose des fonctionnalités intéressantes et relativement méconnues, et dans ce cas précis depuis PHP 5.5 !

Si je devais retenir une règle pratique de ces deux exemples, ce serait celle-ci :
- pour produire ou transformer un flux linéaire (lire un fichier, streamer une réponse, enchaîner des étapes), un générateur consommé par un `foreach`, ou une chaîne de générateurs, suffit largement ;
- un 'ordonnanceur et ses canaux ne valent leur prix que lorsque le traitement forme un graphe (plusieurs sorties, plusieurs entrées), ou lorsqu'on a besoin d'une contre-pression explicite entre les étapes.

Dans les deux cas, les générateurs ne rendent rien plus rapide par eux-mêmes : ils structurent le code, gardent la mémoire sous contrôle, et laissent le consommateur décider du rythme et de l'arrêt.

Avec un peu de recul, ces deux exemples ressemblent à des sortes de boucles d'événements en miniature. La boucle `curl_multi_exec()` / `curl_multi_select()` du multi-stream attend des I/O réseau, et l'ordonnanceur de l'ETL fait tourner des tâches qui rendent la main à chaque `yield` et se réveillent quand une valeur ou une place arrive dans un canal ; une vraie boucle d'événements comme Revolt ou ReactPHP réunit simplement les deux : elle attend des I/O réelles (sockets, timers) et relance les tâches concernées.

Pour un ordonnanceur en production, il est intéressant de se tourner vers des outils éprouvés (plutôt que réinventer une roue pas tout à fait ronde :)). Par exemple :
- [AMPHP](https://amphp.org/), asynchrone en PHP, construite sur la boucle d'événements Revolt. Son paquet [`amphp/pipeline`](https://github.com/amphp/pipeline) propose des files avec contre-pression, très proches de nos canaux. AU passage, jusqu'à sa version 2, AMPHP reposait justement sur des générateurs ;
- [ReactPHP](https://reactphp.org/), une boucle d'événements, des flux avec contre-pression et des promesses ;
- [Swoole](https://www.swoole.com/) / [OpenSwoole](https://openswoole.com/), des extensions C qui apportent de vraies coroutines et une classe `Channel` avec capacité, quasiment le modèle de la section 3 ;
- [Flow PHP](https://flow-php.com/), un framework ETL qui traite les données en flux, à base de générateurs, avec une mémoire bornée : notre section 4, en version industrielle.

### Et ensuite ? Les Fibers

PHP 8.1 a introduit les Fibers, une autre forme de coroutine qui s'exécute elle aussi dans un seul thread. 
Leur principale différence avec les générateurs : une Fiber peut être suspendue (avec `Fiber::suspend()`) depuis n'importe quelle fonction de sa pile d'appels, à n'importe quelle profondeur, alors qu'un générateur ne peut se suspendre que dans son propre corps, avec `yield`. Les fonctions intermédiaires n'ont donc pas besoin de devenir des générateurs : c'est ce qui permet à des bibliothèques comme AMPHP v3, construite sur la boucle d'événements Revolt, d'offrir de l'asynchrone sans changer les signatures des fonctions.

L'ordonnanceur de la section 3 pourrait ainsi être réécrit avec des Fibers, sans commandes visibles dans le code des tâches. Le prix à payer : on ne voit plus dans le code les endroits où une tâche peut être suspendue, alors qu'avec un générateur chaque `yield` signale clairement "ici, une autre tâche peut s'exécuter". Autre différence de taille : une Fiber peut bien échanger des valeurs avec son appelant (via `Fiber::suspend()` et `resume()`), mais elle n'est pas un iterable. On ne peut ni la parcourir avec un `foreach`, ni la passer à une fonction qui attend un `iterable`, ni la composer avec `yield from` : il faudrait l'envelopper soi-même dans un `Iterator`. 
Les deux outils ne répondent donc pas aux mêmes besoins. Les générateurs restent le choix naturel pour produire des séquences paresseuses et enchaîner des traitements, comme dans nos deux exemples. Les Fibers servent surtout de fondation aux bibliothèques asynchrones, qui les cachent derrière des appels en apparence synchrones. Mais c'est un sujet qui mériterait un article à lui seul.

## Pour aller plus loin

- [Rappels sur les générateurs PHP](ARTICLE_rappels.md), l'annexe de cet article : bases, `yield from`, nettoyage avec `finally` et benchmark mémoire tableau contre générateur ;
- [Présentation des générateurs](https://www.php.net/manual/fr/language.generators.overview.php), dans le manuel PHP ;
- [Syntaxe des générateurs](https://www.php.net/manual/fr/language.generators.syntax.php) : `yield`, `yield from`, clés, valeurs renvoyées ;
- [La classe `Generator`](https://www.php.net/manual/fr/class.generator.php) et ses méthodes (`current()`, `send()`, `throw()`...) ;
- Nikita Popov, [Cooperative multitasking using coroutines (in PHP!)](https://www.npopov.com/2012/12/22/Cooperative-multitasking-using-coroutines-in-PHP.html) (2012) : la référence historique sur un ordonnanceur construit avec des générateurs, très proche de notre section 3 ;
