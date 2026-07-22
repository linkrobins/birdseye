<?php

namespace LinkRobins\Birdseye\Capture;

use Flarum\Settings\SettingsRepositoryInterface;
use LinkRobins\Birdseye\Buffer\BufferedEvent;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Server-side capture: registered on both the 'forum' stack (full page
 * loads) and the 'api' stack (the SPA's JSON:API navigation). Writes at most
 * one buffer row per request, after the response is built, and swallows its
 * own failures — analytics must never break a request (that includes never
 * joining a write transaction; a plain single-row INSERT outside any
 * transaction is the contract here).
 */
class CaptureMiddleware implements MiddlewareInterface
{
    /** Bot fragments checked against the lowercased user agent. */
    protected const BOT_MARKERS = [
        'bot', 'crawl', 'spider', 'slurp', 'curl/', 'wget/', 'python-requests',
        'headless', 'lighthouse', 'pingdom', 'uptime', 'monitor', 'facebookexternalhit',
    ];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected VisitorHash $hash
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        try {
            if ((bool) $this->settings->get('linkrobins-birdseye.collect', true)) {
                $this->capture($request, $response);
            }
        } catch (\Throwable) {
            // Best-effort by design.
        }

        return $response;
    }

    protected function capture(ServerRequestInterface $request, ResponseInterface $response): void
    {
        if ($request->getMethod() !== 'GET' || $response->getStatusCode() >= 400) {
            return;
        }

        $ua = $request->getHeaderLine('User-Agent');

        if ($ua === '' || $this->isBot($ua)) {
            return;
        }

        $event = $this->classify($request);

        if ($event === null) {
            return;
        }

        $ip = $this->clientIp($request);

        BufferedEvent::query()->insert($event + [
            'occurred_at' => gmdate('Y-m-d H:i:s'),
            'visitor' => $ip !== null ? $this->hash->make($ip, $ua) : null,
            'country' => $this->country($request),
            'referrer' => $this->referrerHost($request),
            'device' => $this->device($ua),
            'ip_prefix' => $this->shouldStorePrefix($request) && $ip !== null
                ? $this->hash->ipPrefix($ip)
                : null,
        ]);
    }

    /**
     * Decide whether this request is a countable event, and which one.
     *
     * @return array{type: string, path: ?string, discussion_id: ?int, search_query: ?string}|null
     */
    protected function classify(ServerRequestInterface $request): ?array
    {
        $path = $request->getUri()->getPath();

        // JSON:API traffic (SPA navigation).
        if (str_contains($path, '/api/')) {
            // Discussion opened via SPA navigation.
            if (preg_match('#/api/discussions/(\d+)$#', $path, $m)) {
                return [
                    'type' => BufferedEvent::TYPE_VIEW,
                    'path' => '/d/' . $m[1],
                    'discussion_id' => (int) $m[1],
                    'search_query' => null,
                ];
            }

            // A search: the discussion list filtered by a query string.
            if (str_ends_with($path, '/api/discussions')) {
                $q = (string) ($request->getQueryParams()['filter']['q'] ?? '');

                if ($q !== '') {
                    return [
                        'type' => BufferedEvent::TYPE_SEARCH,
                        'path' => null,
                        'discussion_id' => null,
                        'search_query' => mb_substr($q, 0, 191),
                    ];
                }
            }

            return null;
        }

        // Full page loads on the forum stack: count document requests only.
        $accept = $request->getHeaderLine('Accept');

        if ($accept !== '' && !str_contains($accept, 'text/html') && !str_contains($accept, '*/*')) {
            return null;
        }

        $discussionId = null;

        if (preg_match('#/d/(\d+)#', $path, $m)) {
            $discussionId = (int) $m[1];
            $path = '/d/' . $m[1];
        }

        return [
            'type' => BufferedEvent::TYPE_VIEW,
            'path' => mb_substr($path, 0, 191) ?: '/',
            'discussion_id' => $discussionId,
            'search_query' => null,
        ];
    }

    protected function isBot(string $ua): bool
    {
        $ua = strtolower($ua);

        foreach (self::BOT_MARKERS as $marker) {
            if (str_contains($ua, $marker)) {
                return true;
            }
        }

        return false;
    }

    protected function clientIp(ServerRequestInterface $request): ?string
    {
        $params = $request->getServerParams();
        $ip = (string) ($params['REMOTE_ADDR'] ?? '');

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    /** Country from a trusted-proxy header when one is present (Cloudflare). */
    protected function country(ServerRequestInterface $request): ?string
    {
        $code = strtoupper($request->getHeaderLine('CF-IPCountry'));

        return preg_match('/^[A-Z]{2}$/', $code) && $code !== 'XX' ? $code : null;
    }

    protected function referrerHost(ServerRequestInterface $request): ?string
    {
        $host = parse_url($request->getHeaderLine('Referer'), PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return null;
        }

        // Same-site navigation is not a referral.
        if (strcasecmp($host, (string) $request->getUri()->getHost()) === 0) {
            return null;
        }

        return mb_substr(strtolower($host), 0, 191);
    }

    protected function device(string $ua): string
    {
        $ua = strtolower($ua);

        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')) {
            return 'tablet';
        }

        if (str_contains($ua, 'mobi') || str_contains($ua, 'android') || str_contains($ua, 'iphone')) {
            return 'mobile';
        }

        return 'desktop';
    }

    protected function shouldStorePrefix(ServerRequestInterface $request): bool
    {
        // The prefix only exists for country lookup; skip it when a proxy
        // header already answered the question.
        return (bool) $this->settings->get('linkrobins-birdseye.geo_ip_prefix', true)
            && $this->country($request) === null;
    }
}
