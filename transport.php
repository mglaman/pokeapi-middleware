<?php declare(strict_types=1);

use mglaman\PokeAPiMiddleware\PokeTransporter;

require __DIR__ . '/vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

$catcher = new PokeTransporter($loop);
$catcher->sync();

$loop->run();
