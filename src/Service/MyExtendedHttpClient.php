<?php

namespace App\Service;

use JsonMachine\Items;
use JsonMachine\JsonDecoder\PassThruDecoder;
use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MyExtendedHttpClient implements HttpClientInterface
{
    use AsyncDecoratorTrait;

    private static ?ResponseInterface $main = null;

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $passthru = function (ChunkInterface $chunk, AsyncContext $context) use ($options) {
            static $wholeChunk = '';

            if ($chunk->isFirst()) {
                if (404 === $context->getStatusCode()) {
                    $page = preg_split('/page=(\d)/', $context->getInfo()['original_url'], -1, PREG_SPLIT_DELIM_CAPTURE)[1];

                    if (1 === (int)$page) {
                        // e.g. look for another sector
                        //do what you want

                        $context->getResponse()->cancel();

                        $context->replaceRequest(
                            'GET',
                            'https://jsonplaceholder.typicode.com/users',
                            $options
                        );

                        return;
                    }

                    $context->passthru();
                }

                yield $chunk;

                return;
            }

            $wholeChunk .= $chunk->getContent();

            if (!$chunk->isLast()) {
                return;
            }

            $chunksAsIterable = Items::fromString($wholeChunk, ['decoder' => new PassThruDecoder()])->getIterator();
            $parsedMainResponse = iterator_to_array($chunksAsIterable);

            yield $context->createChunk('[');

            [$blocks, $part] = self::prepareSubRequests($context, $parsedMainResponse, $options);
            array_shift($parsedMainResponse);

            // yield until it hits a ResponseInterface
            while (null !== $chunk = self::passthru($context, $part)->current()) {
                yield $chunk;
            }

            $context->passthru(static function (ChunkInterface $chunk, AsyncContext $context) use (&$parsedMainResponse, &$part, &$blocks, $options) {
                static $notYield = false;

                if ($chunk->isFirst() && 404 === $context->getStatusCode()) {
                    //customize as you wish => link etc ...
                    yield $context->createChunk('null');

                    $notYield = true;
                }

                if ($chunk->isFirst()) {
                    return;
                }

                if ($chunk->isLast()) {
                    while ($part) {
                        while (null !== $chunk = self::passthru($context, $part)->current()) {
                            yield $chunk;
                        }

                        if (!$part && 1 === count($blocks) && $blocks[0] instanceof ResponseInterface) {
                            yield $context->createChunk(']');

                            //loop back on original response
                            $context->replaceResponse(array_shift($blocks));

                            return;
                        }

                        if (!$part && $blocks) {
                            $part = array_shift($blocks);
                            array_shift($parsedMainResponse);

                            continue;
                        }

                        if (!$part) {
                            [$blocks, $part] = self::prepareSubRequests($context, $parsedMainResponse, $options);
                            array_shift($parsedMainResponse);

                            continue;
                        }

                        return;
                    }
                }

                if ($notYield) {
                    $notYield = false;

                    return;
                }

                yield $chunk;
            });
        };

        return new AsyncResponse($this->client, $method, $url, $options, $passthru);
    }

    private static function prepareSubRequests(AsyncContext $context, array $parsedMainResponse, array $options): array
    {
        $blocks = self::updateData($parsedMainResponse, $context, $options);
        $part = array_shift($blocks);

        // identify all prepared blocks and identify the block to process
        return [$blocks, $part];
    }

    private static function passthru(AsyncContext $context, array &$part): \Generator
    {
        foreach ($part as $key => $p) {
            if ($p instanceof ResponseInterface) {
                $context->replaceResponse($p);
                unset($part[$key]);

                break;
            }

            unset($part[$key]);

            yield $context->createChunk($p);
        }

        yield;
    }

    public static function updateData(array $parsedMainResponse, AsyncContext $context, array $options): array
    {
        $isPartial = false;

        if (!self::$main) {
            self::$main = $context->getResponse();
        }

        // identify original request in order to loop back on it
        $original = self::$main;
        $toAdd = $options['user_data']['add'];
        unset($options['query']);
        $concurrencyLimit = (int)$options['user_data']['concurrency'];

        $blocks = [];

        $lastKey = array_key_last($parsedMainResponse);

        foreach ($parsedMainResponse as $mainKey => $subChunk) {
            if ($concurrencyLimit && $concurrencyLimit < $mainKey) {
                $isPartial = true;

                break;
            }

            $tempAdditionalResponses = [];
            $tempAdditionalResponses[] = substr($subChunk, 0, -1);

            foreach ($toAdd as $key => $url) {
                preg_match('/"id":(\d+)/', $subChunk, $match);

                $tempAdditionalResponses[] = sprintf(', "%s":', $key);

                // proceed the checks you need
                $url = str_replace('{id}', $match[1], $url);

                $additionalResponse = $context->replaceRequest(
                    'GET',
                    $url[0],
                    $options
                );

                $tempAdditionalResponses[] = $additionalResponse;
            }

            $tempAdditionalResponses[] = substr($subChunk, -1);

            if ($lastKey !== $mainKey) {
                $tempAdditionalResponses[] = ',';
            }
            $blocks[] = $tempAdditionalResponses;
        }

        if (!$isPartial) {
            $blocks[] = $original;
        }

        return $blocks;
    }
}
