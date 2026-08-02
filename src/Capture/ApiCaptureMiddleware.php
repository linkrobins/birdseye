<?php

namespace LinkRobins\Birdseye\Capture;

use LinkRobins\Birdseye\Buffer\BufferedEvent;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Capture on the 'api' stack: SPA navigation only. The stack sees RELATIVE
 * paths ('/discussions/2', never '/api/discussions/2'), and Flarum's
 * internal ApiClient subrequests — fired on every page render, with the
 * parent's headers — ride this same stack looking identical. 1.x has no
 * first-party marker on them, so they are told apart by the Accept header
 * below; without that, every full page load double-counts.
 */
class ApiCaptureMiddleware extends CaptureMiddleware
{
    protected function classify(ServerRequestInterface $request): ?array
    {
        // The document-prefill ApiClient subrequest is built from the page's
        // globals, so it inherits the HTML request's `Accept: text/html`. Real
        // SPA api calls never ask for text/html, so this drops the internal
        // prefill without dropping genuine navigation. Without it, a discussion
        // opened by a full page load is counted here as well as on the forum
        // stack (verified double-counting on 1.8).
        if (str_contains($request->getHeaderLine('Accept'), 'text/html')) {
            return null;
        }

        $path = $request->getUri()->getPath();

        // Discussion opened via SPA navigation.
        if (preg_match('#^/discussions/(\d+)$#', $path, $m)) {
            return [
                'type' => BufferedEvent::TYPE_VIEW,
                'path' => '/d/' . $m[1],
                'discussion_id' => (int) $m[1],
                'search_query' => null,
            ];
        }

        // A search: the discussion list filtered by a query string.
        if ($path === '/discussions') {
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
}
