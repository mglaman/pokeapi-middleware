<?php declare(strict_types=1);

use mglaman\PokeAPiMiddleware\PokemonMaster;

require __DIR__ . '/vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

$catcher = new PokemonMaster($loop);
$catcher->catchEmAll();

$loop->run();
