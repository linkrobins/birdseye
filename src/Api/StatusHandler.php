<?php

namespace LinkRobins\Birdseye\Api;

use Flarum\Foundation\Config;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/birdseye/status — is the saved license key actually working?
 *
 * Asked when an admin opens the extension's settings page, and answered by
 * Birdseye itself rather than inferred locally. Nothing on this forum knows
 * whether a key has lapsed, been rotated, or is bound to somebody else's
 * forum: the sync only phones out when a complete day of events has built up,
 * and the first-contact check-in ignores its response by design. A badge based
 * on either would be stale on a quiet forum and wrong after a cancellation,
 * which on a paid extension is worse than showing nothing.
 *
 * The key never leaves the server. It is sent to Birdseye as the bearer token
 * and is not part of this response.
 */
class StatusHandler implements RequestHandlerInterface
{
    /**
     * An admin is waiting on this, so it fails fast. Being unable to reach
     * Birdseye is itself an answer worth showing, and a slow one is no better
     * than a quick one.
     */
    protected const TIMEOUT = 5;

    protected const CONNECT_TIMEOUT = 3;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Config $config
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        // Guests get 401 rather than 403, the same as the stats endpoint next
        // door, so the frontend can tell "log in" apart from "not for you".
        $actor->assertRegistered();
        $actor->assertAdmin();

        $key = trim((string) $this->settings->get('linkrobins-birdseye.license_key'));
        $endpoint = trim((string) $this->settings->get('linkrobins-birdseye.status_endpoint'));

        if ($key === '' || $endpoint === '') {
            return new JsonResponse(['state' => 'no_key']);
        }

        try {
            $response = $this->client()
                ->post($endpoint, [
                    'headers' => [
                        'Authorization' => 'Bearer '.$key,
                        'Content-Type' => 'application/json',
                        // Identifiable, or the request is blocked before it is
                        // ever seen.
                        'User-Agent' => 'linkrobins-birdseye (+https://linkrobins.com/birdseye)',
                    ],
                    // Sent so Birdseye can say whether this key is bound to a
                    // different forum. It does not bind anything.
                    'body' => json_encode(['forum_url' => (string) $this->config->url()], JSON_UNESCAPED_SLASHES),
                    'http_errors' => false,
                ]);
        } catch (\Throwable) {
            return new JsonResponse(['state' => 'unreachable']);
        }

        if ($response->getStatusCode() !== 200) {
            return new JsonResponse(['state' => 'unreachable']);
        }

        $body = json_decode((string) $response->getBody(), true);

        if (! is_array($body) || ! isset($body['status'])) {
            return new JsonResponse(['state' => 'unreachable']);
        }

        return new JsonResponse([
            // active | incomplete | canceled | invalid_key | bound_elsewhere
            'state' => (string) $body['status'],
            // When Birdseye last received a day of events from this forum.
            // Null until the first sync, which is normal for a key pasted
            // minutes ago and is why the banner does not treat it as a fault.
            'lastSeenAt' => $body['last_seen_at'] ?? null,
            'boundTo' => $body['forum_url'] ?? null,
        ]);
    }

    /**
     * Built here rather than injected: nothing in Flarum's container provides a
     * Guzzle client, and this is the seam the tests replace.
     */
    protected function client(): Client
    {
        return new Client([
            'timeout' => self::TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
        ]);
    }
}
