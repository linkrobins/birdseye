<?php

/*
 * This file is part of linkrobins/birdseye.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Birdseye\Tests\integration\api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LinkRobins\Birdseye\Api\StatusHandler;

/**
 * The status handler, talking to a mocked service instead of Birdseye.
 *
 * A null response stands for "could not connect at all", which is the case the
 * banner most needs to get right.
 */
class FakeStatusHandler extends StatusHandler
{
    public static ?Response $response = null;

    /** @var list<\Psr\Http\Message\RequestInterface> */
    public static array $sent = [];

    protected function client(): Client
    {
        $queued = self::$response ?? new ConnectException('offline', new Request('POST', '/'));

        $stack = HandlerStack::create(new MockHandler([$queued]));
        $stack->push(Middleware::mapRequest(function ($request) {
            self::$sent[] = $request;

            return $request;
        }));

        return new Client(['handler' => $stack]);
    }
}
