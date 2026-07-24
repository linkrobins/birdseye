<?php

/*
 * This file is part of linkrobins/birdseye.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Birdseye\Tests\integration;

use Carbon\Carbon;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Capture runs as middleware on real requests. These exercise it end to end,
 * including the guard that stops a discussion opened by a full page load from
 * being counted on both stacks (the fix that removed the 1.8 double-count).
 */
class CaptureMiddlewareTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-birdseye');

        $this->prepareDatabase([
            'discussions' => [
                ['id' => 1, 'title' => 'Hello', 'created_at' => Carbon::now()->toDateTimeString(), 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 1, 'slug' => 'hello', 'is_private' => 0],
            ],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now()->toDateTimeString(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>hi</p></t>', 'is_private' => 0],
            ],
        ]);
    }

    private function events(): int
    {
        return $this->database()->table('birdseye_events')->count();
    }

    private function browserGet(string $path, string $accept): ServerRequestInterface
    {
        return $this->request('GET', $path)
            ->withHeader('Accept', $accept)
            ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120 Safari/537.36');
    }

    #[Test]
    public function a_real_page_load_is_recorded_once(): void
    {
        $this->send($this->browserGet('/', 'text/html'));

        $this->assertSame(1, $this->events());
    }

    #[Test]
    public function bots_and_non_document_requests_are_not_recorded(): void
    {
        // A crawler UA.
        $this->send($this->request('GET', '/')->withHeader('Accept', 'text/html')->withHeader('User-Agent', 'Googlebot/2.1'));
        // A non-document Accept on the forum stack (asset/XHR).
        $this->send($this->browserGet('/', 'application/json'));

        $this->assertSame(0, $this->events());
    }

    #[Test]
    public function the_internal_prefill_of_a_discussion_page_is_not_double_counted(): void
    {
        // The document-prefill ApiClient subrequest hits the api stack carrying
        // the page's `Accept: text/html`; it must not be counted (on 1.8 there
        // is no RequestUtil::isInternal(), so the header is the only signal).
        $this->send($this->browserGet('/api/discussions/1', 'text/html'));

        $this->assertSame(0, $this->events());
    }

    #[Test]
    public function real_spa_navigation_to_a_discussion_is_recorded(): void
    {
        $this->send($this->browserGet('/api/discussions/1', 'application/json'));

        $this->assertSame(1, $this->events());
    }
}
