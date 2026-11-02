<?php

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/cli', __DIR__.'/public', __DIR__.'/src'])
    ->append([__DIR__.'/bin/console', __FILE__])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
    ])
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__.'/var/cache/.php-cs-fixer.cache')
    ->setFinder($finder)
;
