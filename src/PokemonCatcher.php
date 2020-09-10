<?php declare(strict_types=1);

namespace mglaman\PokeAPiMiddleware;

use Clue\React\NDJson\Encoder;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Stream\WritableResourceStream;

final class PokemonCatcher {

    private const LIMIT = 25;

    private $loop;
    private $client;
    private $artifact;

    private $nextUrl;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
        $this->client = new Browser($loop);

        $stream = new WritableResourceStream(fopen('artifacts/pokemon.json', 'wb'), $this->loop);
        $this->artifact = new Encoder($stream);

        $this->nextUrl = $_ENV['POKEAPI_URL'] . '/pokemon?limit=' . self::LIMIT;
    }

    private function normalizePokemon(object $pokemon)
    {
        return [
            'name' => $pokemon->name,
            'order' => $pokemon->order,
            'image' => $pokemon->sprites->front_default,
            'types' => array_map(static function (object $type) {
                return $type->type->name;
            }, $pokemon->types),
        ];
    }

    private function fetchPokemon(object $result)
    {
        $this->loop->futureTick(function () use ($result) {
            print "[http] Fetching {$result->url}" . PHP_EOL;
            $this->client->get($result->url)->then(function (ResponseInterface $response) {
                $pokemon = \json_decode((string)$response->getBody());
                $this->artifact->write($this->normalizePokemon($pokemon));
            });
        });
    }

    private function fetchPokemonList()
    {
        $this->loop->futureTick(function () {
            print "[http] Fetching {$this->nextUrl}" . PHP_EOL;
            $this->client
            ->get($this->nextUrl)
            ->then(function (ResponseInterface $response) {
                $body = \json_decode((string)$response->getBody());

                if ($body->next !== null) {
                    $this->nextUrl = $body->next;
                    $this->fetchPokemonList();
                }

                foreach ($body->results as $result) {
                    $this->fetchPokemon($result);
                }
            });
        });
    }

    public function catchEmAll(): void {
        $this->fetchPokemonList();
    }

}
