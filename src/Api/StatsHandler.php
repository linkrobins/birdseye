<?php

namespace LinkRobins\Birdseye\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Birdseye\Stats\StatsBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/birdseye/stats — the dashboard's data source. Admin-only: stats
 * summarize guest traffic, and exposing them is the forum operator's call,
 * not a member-visible feature.
 */
class StatsHandler implements RequestHandlerInterface
{
    public function __construct(
        protected StatsBuilder $stats
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return new JsonResponse($this->stats->build());
    }
}
