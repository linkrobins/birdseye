<?php

namespace LinkRobins\Birdseye\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Birdseye\Permissions;
use LinkRobins\Birdseye\Stats\StatsBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/birdseye/stats — the dashboard's data source. Admins always pass;
 * other groups only via the viewStats permission (exposing traffic stats to
 * members is the forum operator's opt-in). Guests get 401, not 403.
 */
class StatsHandler implements RequestHandlerInterface
{
    public function __construct(
        protected StatsBuilder $stats
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertPermission($actor->hasPermission(Permissions::VIEW_STATS));

        return new JsonResponse($this->stats->build());
    }
}
