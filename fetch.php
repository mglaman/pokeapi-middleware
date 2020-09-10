<?php declare(strict_types=1);

use mglaman\PokeAPiMiddleware\PokemonCatcher;

require __DIR__ . '/vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

$catcher = new PokemonCatcher($loop);
$catcher->catchEmAll();

$loop->run();
