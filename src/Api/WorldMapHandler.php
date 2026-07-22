<?php

namespace LinkRobins\Birdseye\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response;
use LinkRobins\Birdseye\Permissions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the bundled world-map SVG (jsvectormap outlines, MIT — attribution
 * comment embedded in the file). A route instead of a published asset so the
 * extension needs no assets:publish step for a non-JS/CSS file; cached hard
 * because the file only changes with an extension release.
 */
class WorldMapHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertPermission($actor->hasPermission(Permissions::VIEW_STATS));

        $response = new Response('php://memory', 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=86400',
        ]);

        $response->getBody()->write((string) file_get_contents(__DIR__ . '/../../resources/world-map.svg'));

        return $response;
    }
}
