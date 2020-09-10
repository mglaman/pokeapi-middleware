<?php declare(strict_types=1);

use Clue\React\NDJson\Encoder;
use React\Http\Browser;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;
use React\Stream\WritableResourceStream;
use React\Stream\WritableStreamInterface;

require __DIR__ . '/vendor/autoload.php';

const RESULT_LIMIT = 40;

$loop = React\EventLoop\Factory::create();
$client = new Browser($loop);
$artifact = new WritableResourceStream(fopen('artifacts/pokemon.json', 'wb'), $loop);
$dest = new Encoder($artifact);

function addApiFetchTimer(int $offset, LoopInterface $loop, Browser $client, WritableStreamInterface $dest)
{
    $loop->futureTick(static function () use ($offset, $loop, $client, $dest) {
        doApiFetch($offset, $loop, $client, $dest);
    });
}
function doFetchPokemon(string $url, LoopInterface $loop, Browser $client, WritableStreamInterface $dest)
{
    $client->get($url)->then(static function (ResponseInterface $response) use ($dest) {
        $pokemon = \json_decode((string)$response->getBody());
        $normalizedPokemon = [
            'name' => $pokemon->name,
            'order' => $pokemon->order,
            'image' => $pokemon->sprites->front_default,
            'types' => array_map(static function (object $type) {
                return $type->type->name;
            }, $pokemon->types),
        ];
        $dest->write($normalizedPokemon);
    });
}

function doApiFetch(int $offset, LoopInterface $loop, Browser $client, WritableStreamInterface $dest)
{
    // @todo allow leveraging the value from `$body->next` specifically.
    $client
        ->get($_ENV['POKEAPI_URL'] . '/pokemon?' . http_build_query([
            'offset' => $offset,
            'limit' => RESULT_LIMIT,
        ]))
        ->then(static function (ResponseInterface $response) use ($offset, $loop, $client, $dest) {
            $body = \json_decode((string)$response->getBody());
            if ($body->next !== null) {
                addApiFetchTimer($offset + RESULT_LIMIT, $loop, $client, $dest);
            }
            foreach ($body->results as $result) {
                $loop->futureTick(static function () use ($result, $loop, $client, $dest) {
                    doFetchPokemon($result->url, $loop, $client, $dest);
                });
            }
        });
}
addApiFetchTimer(0, $loop, $client, $dest);


$loop->run();
