<?php

require __DIR__.'/../vendor/autoload.php';

header('Content-Type: text/html; charset=utf-8');

$examples = glob(__DIR__.'/example*.php') ?: [];

echo '<!doctype html><meta charset="utf-8"><title>Generator examples</title>';
echo '<h1>Generator examples</h1><ul>';
foreach ($examples as $example) {
    $name = basename($example);
    printf('<li><a href="/%1$s">%1$s</a></li>', htmlspecialchars($name));
}
echo '</ul>';
