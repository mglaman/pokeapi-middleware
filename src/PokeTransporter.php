<?php declare(strict_types=1);

namespace mglaman\PokeAPiMiddleware;

use Clue\React\NDJson\Decoder;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
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
     * @var \Clue\React\NDJson\Encoder
     */
    private $artifact;

    /**
     * @var string
     */
    private $authHeader;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
        $this->client = new Browser($loop);

        $stream = new ReadableResourceStream(fopen('artifacts/pokemon.json', 'rb'), $this->loop);
        $this->artifact = new Decoder($stream);

        $this->authHeader = 'Basic ' . base64_encode("{$_ENV['DRUPAL_API_USER']}:{$_ENV['DRUPAL_API_PASS']}");
    }

    private function createNode(\stdClass $pokemon)
    {
        $this->loop->futureTick(function () use ($pokemon): void {
            print "Must create {$pokemon->id}" . PHP_EOL;
        });
    }

    private function updateNode(\stdClass $pokemon, \stdClass $node)
    {
        $this->loop->futureTick(function () use ($pokemon, $node): void {
            print "Must update {$pokemon->id}" . PHP_EOL;
        });
    }

    private function ensureEntry(\stdClass $pokemon)
    {
        $this->loop->addTimer(0.5, function () use ($pokemon): void {
            $this->client
            ->get($_ENV['DRUPAL_API_URL'] . '/jsonapi/node/pokemon?filter[field_guid]=' . $pokemon->id, [
                'Accept' => 'application/vnd.api+json',
                'Authorization' => $this->authHeader,
            ])
            ->then(function (ResponseInterface $response) use ($pokemon): void {
                $document = \json_decode((string) $response->getBody());
                if (count($document->data) === 0) {
                    $this->createNode($pokemon);
                } elseif (count($document->data) === 1) {
                    $this->updateNode($pokemon, $document->data[0]);
                } else {
                    print "[error] There are multiple records for {$pokemon->id} in the destination API." . PHP_EOL;
                }
            });
        });
    }

    public function sync(): void
    {
        $this->artifact->on('data', function ($data): void {
            $this->ensureEntry(($data));
        });
    }
}
