<?php declare(strict_types=1);

namespace mglaman\PokeAPiMiddleware;

use Clue\React\NDJson\Encoder;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Stream\WritableResourceStream;

final class PokemonMaster
{
    /**
     * @var \React\EventLoop\LoopInterface
     */
    private $loop;

    /**
     * @var \React\Http\Browser
     */
    private $client;

    /**
     * @var \Clue\React\NDJson\Encoder
     */
    private $artifact;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
        $this->client = new Browser($loop);

        $stream = new WritableResourceStream(fopen('artifacts/pokemon.json', 'wb'), $this->loop);
        $this->artifact = new Encoder($stream, JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePokemon(\stdClass $pokemon, \stdClass $species): array
    {
        return [
            'id' => $pokemon->name,
            'order' => $pokemon->order,
            'image' => $pokemon->sprites->front_default,
            'types' => array_map(static function (\stdClass $type) {
                return $type->type->name;
            }, $pokemon->types),
            'name' => array_map(static function (\stdClass $text): array {
                return [
                    'value' => $text->name,
                    'langcode' => $text->language->name
                ];
            }, array_values(array_filter($species->names, static function (\stdClass $text): bool {
                return in_array($text->language->name, ['en', 'es'], true);
            }))),
            'description' => array_map(static function (\stdClass $text): array {
                return [
                    'value' => $text->flavor_text,
                    'langcode' => $text->language->name,
                ];
            }, array_values(array_filter($species->flavor_text_entries, static function (\stdClass $text): bool {
                return $text->version->name === 'y' && in_array($text->language->name, ['en', 'es'], true);
            }))),
            'genus' => array_map(static function (\stdClass $text): array {
                return [
                    'value' => $text->genus,
                    'langcode' => $text->language->name
                ];
            }, array_values(array_filter($species->genera, static function (\stdClass $text): bool {
                return in_array($text->language->name, ['en', 'es'], true);
            }))),
            'baby' => $species->is_baby,
            'legendary' => $species->is_legendary,
            'mythical' => $species->is_mythical,
        ];
    }

    private function fetchPokemon(\stdClass $result): void
    {
        $this->loop->futureTick(function () use ($result): void {
            print "\033[32m[http]\033[0m Fetching {$result->url}" . PHP_EOL;
            $this->client->get($result->url)->then(function (ResponseInterface $response): void {
                $pokemon = \json_decode((string)$response->getBody());

                $species_url = $pokemon->species->url;
                print "\033[32m[http]\033[0m Fetching {$species_url}" . PHP_EOL;
                $this->client->get($species_url)->then(function (ResponseInterface $response) use ($pokemon): void {
                    $species = \json_decode((string) $response->getBody());
                    $this->artifact->write($this->normalizePokemon($pokemon, $species));
                });
            });
        });
    }

    private function fetchPokemonList(string $url): void
    {
        $this->loop->futureTick(function () use ($url): void {
            print "\033[32m[http]\033[0m Fetching {$url}" . PHP_EOL;
            $this->client
            ->get($url)
            ->then(function (ResponseInterface $response): void {
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
