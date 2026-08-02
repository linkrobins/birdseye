<?php

namespace LinkRobins\Birdseye\Capture;

use Flarum\Settings\SettingsRepositoryInterface;
use LinkRobins\Birdseye\Buffer\BufferedEvent;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Server-side capture, registered per stack via the two thin subclasses:
 * ForumCaptureMiddleware ('forum' stack — full page loads) and
 * ApiCaptureMiddleware ('api' stack — the SPA's JSON:API navigation).
 *
 * The split is load-bearing, not cosmetic: Flarum renders pages by firing
 * INTERNAL ApiClient subrequests through the api middleware stack, and
 * those inherit the parent's headers, so they arrive looking much like real
 * traffic. What separates them here: an internal subrequest never traverses
 * the FORUM stack, and on the API stack it carries the parent page's
 * `Accept: text/html`, which genuine SPA navigation never asks for.
 *
 * Writes at most one buffer row per request, after the response is built,
 * and swallows its own failures — analytics must never break a request
 * (that includes never joining a write transaction; a plain single-row
 * INSERT outside any transaction is the contract here).
 */
abstract class CaptureMiddleware implements MiddlewareInterface
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
     * Stack-specific — see the subclasses.
     *
     * @return array{type: string, path: ?string, discussion_id: ?int, search_query: ?string}|null
     */
    abstract protected function classify(ServerRequestInterface $request): ?array;

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

    /**
     * Country from the configured trusted-proxy header (default Cloudflare's
     * CF-IPCountry; nginx-geoip users can point it at X-Country etc.). The
     * header is trusted as-is: a client NOT routed through such a proxy can
     * forge it, so operators without one should blank the setting and rely
     * on geo_ip_prefix — analytics-only data either way.
     */
    protected function country(ServerRequestInterface $request): ?string
    {
        $header = trim((string) $this->settings->get('linkrobins-birdseye.country_header', 'CF-IPCountry'));

        if ($header === '' || !preg_match('/^[A-Za-z0-9-]+$/', $header)) {
            return null;
        }

        $code = strtoupper($request->getHeaderLine($header));

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
