<?php declare(strict_types=1);

namespace mglaman\PokeAPiMiddleware;

use Clue\React\NDJson\Decoder;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Http\Browser;
use React\Http\Message\ResponseException;
use React\Socket\Connector;
use React\Stream\ReadableResourceStream;

final class PokeTransporter
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
     * @var \Clue\React\NDJson\Decoder
     */
    private $artifact;

    /**
     * @var string
     */
    private $authHeader;

    private $pokemon = [];

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
        $this->client = new Browser($loop, new Connector($loop, [
            'tls' => [
                // mkcert CA is not being validated.
                'verify_peer' => false,
            ],
        ]));

        $stream = new ReadableResourceStream(fopen('artifacts/pokemon.json', 'rb'), $this->loop);
        $this->artifact = new Decoder($stream);

        $this->authHeader = 'Basic ' . base64_encode("{$_ENV['DRUPAL_API_USER']}:{$_ENV['DRUPAL_API_PASS']}");
    }

    private function getItemByLangcode(array $property)
    {
        $items = array_filter($property, static function (\stdClass $item) {
            return $item->langcode === 'en';
        });
        return reset($items);
    }

    private function createNode(\stdClass $pokemon)
    {
        $this->loop->futureTick(function () use ($pokemon): void {
            $name = $this->getItemByLangcode($pokemon->name);
            $description = $this->getItemByLangcode($pokemon->description);
            $genus = $this->getItemByLangcode($pokemon->genus);
            if (empty($name)) {
                return;
            }
            $document = [
                'data' => [
                    'type' => 'node--pokemon',
                    'attributes' => [
                        'title' => $name->value,
                        'field_description' => $description->value ?? '',
                        'field_genus' => $genus->value ?? '',
                        'field_guid' => $pokemon->id,
                        'field_image' => $pokemon->image,
                        'field_legendary' => $pokemon->legendary,
                        'field_baby' => $pokemon->baby,
                        'field_mythical' => $pokemon->mythical,
                        'field_order' => $pokemon->order < 0 ? 1000 : $pokemon->order,
                    ],
                ],
            ];
            $this->client
            ->post($_ENV['DRUPAL_API_URL'] . '/jsonapi/node/pokemon', [
                'Accept' => 'application/vnd.api+json',
                'Content-Type' => 'application/vnd.api+json',
                'Authorization' => $this->authHeader,
            ], \json_encode($document))
            ->then(function (ResponseInterface $response) use ($pokemon) {
                print "[{$response->getStatusCode()}]Created {$pokemon->id}" . PHP_EOL;
            }, static function ($data) use ($pokemon) {
                print "[error] Could not create {$pokemon->id}" . PHP_EOL;
            });
        });
    }

    private function updateNode(\stdClass $pokemon, \stdClass $node)
    {
        $this->loop->futureTick(function () use ($pokemon, $node): void {
            $name = $this->getItemByLangcode($pokemon->name);
            $description = $this->getItemByLangcode($pokemon->description);
            $genus = $this->getItemByLangcode($pokemon->genus);
            $document = [
                'data' => [
                    'type' => 'node--pokemon',
                    'id' => $node->id,
                    'attributes' => [
                        'title' => $name->value,
                        'field_description' => $description->value ?? '',
                        'field_genus' => $genus->value ?? '',
                        'field_guid' => $pokemon->id,
                        'field_image' => $pokemon->image,
                        'field_legendary' => $pokemon->legendary,
                        'field_baby' => $pokemon->baby,
                        'field_mythical' => $pokemon->mythical,
                        'field_order' => $pokemon->order < 0 ? 1000 : $pokemon->order,
                    ],
                ],
            ];
            $this->client
                ->patch($_ENV['DRUPAL_API_URL'] . '/jsonapi/node/pokemon/' . $node->id, [
                    'Accept' => 'application/vnd.api+json',
                    'Content-Type' => 'application/vnd.api+json',
                    'Authorization' => $this->authHeader,
                ], \json_encode($document))
                ->then(function (ResponseInterface $response) use ($pokemon) {
                    print "[{$response->getStatusCode()}] Updated {$pokemon->id}" . PHP_EOL;
                }, static function ($data) use ($pokemon) {
                    $reason = '';
                    if ($data instanceof ResponseException) {
                        $reason = (string) $data->getResponse()->getBody();
                    }
                    print "[error] Could not update {$pokemon->id}: " . $reason;
                });
        });
    }

    private function ensureEntry(\stdClass $pokemon)
    {
        $this->loop->futureTick(function () use ($pokemon): void {
            print "Checking {$pokemon->id}" . PHP_EOL;
            $this->client
            ->get($_ENV['DRUPAL_API_URL'] . '/jsonapi/node/pokemon?filter[field_guid]=' . $pokemon->id, [
                'Accept' => 'application/vnd.api+json',
                'Authorization' => $this->authHeader,
            ])
            ->then(function (ResponseInterface $response) use ($pokemon): void {
                $document = \json_decode((string) $response->getBody(), false, 512, JSON_THROW_ON_ERROR);
                if (count($document->data) === 0) {
                    $this->createNode($pokemon);
                } elseif (count($document->data) === 1) {
                    $this->updateNode($pokemon, $document->data[0]);
                } else {
                    print "[error] There are multiple records for {$pokemon->id} in the destination API." . PHP_EOL;
                }
            }, function (\RuntimeException $data) use ($pokemon) {
                print "[error] Rejected for {$pokemon->id} :" . $data->getMessage() . PHP_EOL;
            });
        });
    }

    public function sync(): void
    {
        $this->artifact->on('data', function ($data): void {
            $this->pokemon[] = $data;
        });
        $this->loop->addPeriodicTimer(0.5, function (TimerInterface $timer) {
            if (!$this->artifact->isReadable() && count($this->pokemon) === 0) {
                $this->loop->cancelTimer($timer);
                return;
            }

            $pokemon = array_shift($this->pokemon);
            print "Processing {$pokemon->id}." . PHP_EOL;
            $this->ensureEntry($pokemon);
        });
    }
}
