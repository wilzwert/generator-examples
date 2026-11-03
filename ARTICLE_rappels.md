# Rappels sur les générateurs PHP

> Cet article complète [Générateurs PHP : itération paresseuse, flux et orchestration de tâches](https://github.com/wilzwert/generator-examples/blob/main/ARTICLE.md). Il regroupe les rappels de base sur les générateurs, ainsi qu'un petit benchmark mémoire.

Selon la [référence du langage PHP](https://www.php.net/manual/fr/language.generators.overview.php) :

> Les générateurs fournissent une façon simple de mettre en place des itérateurs sans le coût ni la complexité du développement d'une classe qui implémente l'interface Iterator.

Pour créer un générateur, il suffit d'utiliser l'instruction `yield` dans une fonction : celle-ci devient une *fonction génératrice*. L'appeler n'exécute pas son corps, mais renvoie un objet `Generator`. C'est d'ailleurs la seule manière d'obtenir un générateur : la classe `Generator` est `final` et ne peut pas être instanciée directement avec `new`.

Pour référence, voici les signatures de ses méthodes (extrait du manuel, ce n'est pas du code exécutable) :

```php
final class Generator implements Iterator {
    public function current(): mixed;
    public function getReturn(): mixed;
    public function key(): mixed;
    public function next(): void;
    public function rewind(): void;
    public function send(mixed $value): mixed;
    public function throw(Throwable $exception): mixed;
    public function valid(): bool;
    public function __wakeup(): void; // lève une exception : un générateur n'est pas sérialisable
}
```

Chaque instruction `yield` fournit une valeur au consommateur, puis suspend l'exécution de la fonction génératrice. Celle-ci ne reprend que lorsque le générateur est relancé : par un appel à sa méthode `next()` (ce que fait automatiquement `foreach` à chaque tour), ou à sa méthode `send()` que nous verrons plus bas.

Les générateurs permettent de "produire" facilement des données et sont utilisables comme n'importe quel iterable (la plupart du temps avec foreach), sans passer par l'étape "stocker toutes les données en RAM" avant de les consommer. Les valeurs sont produites une à une : le générateur ne fournit qu'une valeur à la fois, et tant que le consommateur ne les accumule pas lui-même, seule la valeur courante est en mémoire.

Petit exemple avec la suite de Fibonacci :

```php
function getFibonacci(): \Generator {
    $i = 0;
    $k = 1;
    yield $k; // Premier terme de la suite
    while (true) {
        $k = $i + $k;
        $i = $k - $i;
        yield $k; // termes suivants
    }
}

$i = 0;
foreach (getFibonacci() as $term) {
    ++$i;
    printf("Term %d : %d\n", $i, $term);
    if ($i >= 50) {
        break;
    }
}
```

Dans cet exemple, tant que le consommateur du générateur ne s'interrompt pas (ici avec `break`), la fonction continue de produire les termes un à un avec l'instruction `yield` ; elle n'a aucune notion de condition d'arrêt et ne sert qu'à produire les termes un par un.

À noter également : tant que le générateur n'est pas effectivement consommé, son code n'est pas exécuté. Ainsi dans notre exemple précédent, `getFibonacci()` n'exécute rien en soi, mais ne fait que créer un `Generator` prêt à fournir des valeurs. Le corps de la fonction ne s'exécute qu'au premier accès au générateur (premier tour de `foreach`, appel à `current()`, `send()`...), et seulement jusqu'au premier `yield`.

Les générateurs ont aussi une propriété moins connue : ils sont capables de produire des données mais aussi d'en recevoir. `yield` peut en effet produire une valeur, mais permet également de récupérer des données en entrée ; c'est dans ce cas l'appelant qui envoie des données au générateur avec sa méthode `send()`.

Voici un petit récapitulatif des manières dont on peut utiliser `yield` :

    yield;                    suspend l'exécution, produit null
    yield $value;             suspend l'exécution, produit $value
    $received = yield;        suspend, produit null, puis reçoit la valeur passée à send()
    $received = yield $value; suspend, produit $value, puis reçoit la valeur passée à send()

Un petit exemple vaut mieux qu'un long discours : un générateur qui tient un total cumulé, et à qui on envoie des montants.

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

Attention au premier appel : sur un générateur pas encore démarré, `send()` l'exécute d'abord jusqu'au premier `yield`, puis envoie sa valeur. La valeur produite par ce premier `yield` (ici le `0`) est alors perdue pour l'appelant. D'où l'appel à `current()` en premier.

Enfin, `throw()` fonctionne sur le même principe que `send()`, mais au lieu de fournir une valeur, il lève une exception à l'endroit du `yield` où le générateur est suspendu. Le générateur peut l'attraper avec un `try/catch` autour du `yield`, comme n'importe quelle exception.

Une coroutine est une fonction qui peut suspendre son exécution, puis la reprendre plus tard là où elle s'était arrêtée, en conservant son état. Selon cette définition, tout générateur est déjà une coroutine. `send()` ajoute la communication dans l'autre sens : le générateur ne se contente plus de produire des valeurs, il peut aussi en recevoir de celui qui le relance.

On reverra ces trois méthodes dans [l'article principal](https://github.com/wilzwert/generator-examples/blob/main/ARTICLE.md), où elles servent à piloter des tâches.

## Relayer les valeurs d'un autre iterable : `yield from`

Quand chaque valeur d'une source doit être transformée, on écrit une boucle avec un `yield` à chaque tour :

```php
$options = (function (): \Generator {
    $rows = DB::query('SELECT ...');
    foreach ($rows as $row) {
        // un seul objet Option construit à la fois
        yield Option::fromRow($row);
    }
})();
```

Ici, le générateur ne change rien au comportement de DB::query qui peut tout à fait charger toutes les valeurs en mémoire, mais le consommateur de $options recevra bien les valeurs une par une. Envelopper une source dans un générateur ne la rend pas paresseuse pour autant.

Quand on se contente de relayer les valeurs d'une source qui est déjà un iterable (tableau, `Iterator` ou autre générateur), l'instruction `yield from` évite d'écrire cette boucle : la production des valeurs est déléguée à la source.

```php
function readLines(string $path): \Generator
{
    if (false === ($fp = fopen($path, 'r'))) {
        throw new \RuntimeException("Unable to open $path");
    }

    try {
        while (false !== ($line = fgets($fp))) {
            yield $line;
        }
    } finally {
        fclose($fp);
    }
}

function readAllLogs(): \Generator
{
    yield from readLines('app-2025.log');
    yield from readLines('app-2026.log');
}
```

À noter :
- quand la source est un générateur, l'expression `yield from` vaut la valeur que ce générateur a renvoyée avec `return` : `$result = yield from inner();` ;
- `yield from` conserve les clés de la source. Avec `iterator_to_array()`, les clés identiques de deux sources successives s'écrasent alors (passer `false` en second argument pour l'éviter).

## Du code peut s'exécuter après le dernier yield

Dans `readLines()`, le fichier doit être fermé une fois toutes les lignes lues, donc après le dernier yield. Contrairement à `return`, `yield` ne termine pas la fonction : le code situé après le dernier yield s'exécute, mais seulement quand le générateur est relancé une fois de plus. Un foreach le fait automatiquement : son dernier appel à `next()` relance le générateur, qui exécute le code restant et se termine. Mais si le consommateur s'arrête avant (break, ou itération jamais terminée), ce code ne s'exécute jamais :

```php
foreach (readAllLogs() as $line) {
    if (str_contains($line, 'FATAL')) {
        break; // sans finally, le fichier en cours resterait ouvert
    }
}
```

Un nettoyage qui doit toujours avoir lieu doit donc être placé dans un bloc `finally`, qui s'exécute aussi lorsque le générateur est détruit avant la fin (par exemple quand plus aucune variable ne le référence). C'est pour cela que `readLines()` ferme son fichier dans un `finally` : après ce `break`, `readAllLogs()` et le générateur `readLines()` en cours sont détruits, et le fichier est bien fermé.


## Ce que ça change en mémoire : un petit benchmark

Pour mesurer concrètement la différence, le projet contient un petit benchmark (`src/Generator/Benchmark.php`) : exporter N UUID dans un fichier, soit en construisant d'abord un tableau, soit avec un générateur. Le consommateur est le même dans les deux cas, puisqu'il accepte n'importe quel `iterable` :

```php
/** Classic version: build and return the full array. */
public function uuidsArray(int $n): array
{
    $uuids = [];
    for ($i = 0; $i < $n; ++$i) {
        $uuids[] = $this->uuidV4();
    }

    return $uuids;
}

/** Generator version: UUIDs produced on demand. */
public function uuidsGenerator(int $n): \Generator
{
    for ($i = 0; $i < $n; ++$i) {
        yield $this->uuidV4();
    }
}

/** The consumer stays the same: it accepts any iterable. */
public function exporter(iterable $uuids, $stream): int
{
    $total = 0;
    foreach ($uuids as $uuid) {
        fwrite($stream, $uuid."\n");
        ++$total;
    }

    return $total;
}
```

Chaque mesure tourne dans son propre processus PHP, pour que le pic mémoire d'une mesure ne fausse pas la suivante. La limite mémoire est fixée à 128 Mo, et on garde la médiane de 5 exécutions :

    make console script="benchmark --memory-limit=128M --repetitions=5 10000 100000 1000000 5000000"

Résultat :

    PHP 8.5.12 - median over 5 runs, memory_limit=128M

    N            Method          Duration    Peak memory
    ----------------------------------------------------
    10 000       array            70.6 ms         3.3 MB
    10 000       generator        51.5 ms        82.6 KB

    100 000      array           408.1 ms        32.5 MB
    100 000      generator       419.3 ms        82.6 KB

    1 000 000    array       FAILED: Fatal error: Allowed memory size of 134217728 bytes exhausted (...)
    1 000 000    generator      4149.8 ms        82.6 KB

    5 000 000    array       FAILED: Fatal error: Allowed memory size of 134217728 bytes exhausted (...)
    5 000 000    generator     21759.6 ms        82.6 KB

Quelques constats :
- la mémoire de la version tableau grandit avec N, puisque tous les UUID sont conservés avant d'être écrits. Dès 1 million d'UUID, elle dépasse les 128 Mo : le script s'arrête sur une erreur fatale ;
- la version générateur reste à 82,6 Ko, de 10 000 à 5 millions d'UUID : seul l'UUID courant existe en mémoire, le temps d'être écrit dans le fichier ;
- les durées sont équivalentes dès que N grandit : le générateur ne rend pas le traitement plus rapide, il évite simplement de tout garder en mémoire ;
- `exporter()` est strictement le même dans les deux cas : on peut passer d'un tableau à un générateur sans toucher au code qui consomme les données.

