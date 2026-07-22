<?php

namespace LinkRobins\Birdseye\Capture;

use LinkRobins\Birdseye\Buffer\BufferedEvent;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Capture on the 'forum' stack: full document loads only. Internal API
 * subrequests never traverse this stack, so everything countable here is a
 * real page view. The text/html gate additionally drops asset/XHR traffic
 * that shares the stack.
 */
class ForumCaptureMiddleware extends CaptureMiddleware
{
    protected function classify(ServerRequestInterface $request): ?array
    {
        if (!str_contains($request->getHeaderLine('Accept'), 'text/html')) {
            return null;
        }

        $path = $request->getUri()->getPath();
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
}
