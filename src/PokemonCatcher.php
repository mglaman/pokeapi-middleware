<?php declare(strict_types=1);

namespace mglaman\PokeAPiMiddleware;

use Clue\React\NDJson\Encoder;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Stream\WritableResourceStream;

final class PokemonCatcher
{
    private $loop;
    private $client;
    private $artifact;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
        $this->client = new Browser($loop);

        $stream = new WritableResourceStream(fopen('artifacts/pokemon.json', 'wb'), $this->loop);
        $this->artifact = new Encoder($stream, JSON_UNESCAPED_SLASHES);

        $this->nextUrl = $_ENV['POKEAPI_URL'] . '/pokemon';
    }

    private function normalizePokemon(object $pokemon, object $species)
    {
        return [
            'id' => $pokemon->name,
            'order' => $pokemon->order,
            'image' => $pokemon->sprites->front_default,
            'types' => array_map(static function (object $type) {
                return $type->type->name;
            }, $pokemon->types),
            'name' => array_map(static function (object $text) {
                return [
                    'value' => $text->name,
                    'langcode' => $text->language->name
                ];
            }, array_values(array_filter($species->names, static function (object $text) {
                return in_array($text->language->name, ['en', 'es']);
            }))),
            'description' => array_map(static function (object $text) {
                return [
                    'value' => $text->flavor_text,
                    'langcode' => $text->language->name,
                ];
            }, array_values(array_filter($species->flavor_text_entries, static function (object $text) {
                return $text->version->name === 'y' && in_array($text->language->name, ['en', 'es']);
            }))),
            'genus' => array_map(static function (object $text) {
                return [
                    'value' => $text->genus,
                    'langcode' => $text->language->name
                ];
            }, array_values(array_filter($species->genera, static function (object $text) {
                return in_array($text->language->name, ['en', 'es']);
            }))),
            'baby' => $species->is_baby,
            'legendary' => $species->is_legendary,
            'mythical' => $species->is_mythical,
        ];
    }

    private function fetchPokemon(object $result)
    {
        $this->loop->futureTick(function () use ($result) {
            print "\033[32m[http]\033[0m Fetching {$result->url}" . PHP_EOL;
            $this->client->get($result->url)->then(function (ResponseInterface $response) {
                $pokemon = \json_decode((string)$response->getBody());

                $species_url = $pokemon->species->url;
                print "\033[32m[http]\033[0m Fetching {$species_url}" . PHP_EOL;
                $this->client->get($species_url)->then(function (ResponseInterface $response) use ($pokemon) {
                    $species = \json_decode((string) $response->getBody());
                    $this->artifact->write($this->normalizePokemon($pokemon, $species));
                });
            });
        });
    }

    private function fetchPokemonList(string $url)
    {
        $this->loop->futureTick(function () use ($url) {
            print "\033[32m[http]\033[0m Fetching {$url}" . PHP_EOL;
            $this->client
            ->get($url)
            ->then(function (ResponseInterface $response) {
                $body = \json_decode((string)$response->getBody());

                if ($body->next !== null) {
                    $this->fetchPokemonList($body->next);
                }

                foreach ($body->results as $result) {
                    $this->fetchPokemon($result);
                }
            });
        });
    }

    public function catchEmAll(): void
    {
        $this->fetchPokemonList($_ENV['POKEAPI_URL'] . '/pokemon');
    }
}
