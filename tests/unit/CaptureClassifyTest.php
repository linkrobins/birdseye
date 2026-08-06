<?php

/*
 * This file is part of linkrobins/birdseye.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Birdseye\Tests\unit;

use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\ServerRequest;
use LinkRobins\Birdseye\Buffer\BufferedEvent;
use LinkRobins\Birdseye\Capture\ApiCaptureMiddleware;
use LinkRobins\Birdseye\Capture\ForumCaptureMiddleware;
use LinkRobins\Birdseye\Capture\VisitorHash;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The request classification is where the two stacks decide what counts. These
 * cover the stack-specific rules — including the cross-major guard that keeps a
 * discussion opened by a full page load from being counted on both the forum
 * and the api stacks (the internal ApiClient prefill inherits `Accept:
 * text/html`, and on Flarum 1.8 there is no RequestUtil::isInternal() to catch
 * it, so the header is the discriminator).
 */
class CaptureClassifyTest extends TestCase
{
    private function forum(): object
    {
        return new class ($this->createStub(SettingsRepositoryInterface::class), $this->createStub(VisitorHash::class)) extends ForumCaptureMiddleware {
            public function classifyPublic(ServerRequestInterface $r): ?array
            {
                return $this->classify($r);
            }

            public function isBotPublic(string $ua): bool
            {
                return $this->isBot($ua);
            }

            public function devicePublic(string $ua): string
            {
                return $this->device($ua);
            }

            public function referrerPublic(ServerRequestInterface $r): ?string
            {
                return $this->referrerHost($r);
            }
        };
    }

    private function api(): object
    {
        return new class ($this->createStub(SettingsRepositoryInterface::class), $this->createStub(VisitorHash::class)) extends ApiCaptureMiddleware {
            public function classifyPublic(ServerRequestInterface $r): ?array
            {
                return $this->classify($r);
            }
        };
    }

    private function req(string $path, string $accept = 'application/json', array $query = []): ServerRequestInterface
    {
        $r = new ServerRequest([], [], $path, 'GET', 'php://input', ['Accept' => $accept, 'User-Agent' => 'RealBrowser/1.0']);

        return $query ? $r->withQueryParams($query) : $r;
    }

    // --- forum stack ---

    /** @test */
    #[Test]
    public function the_forum_stack_counts_an_html_page_load(): void
    {
        $event = $this->forum()->classifyPublic($this->req('/', 'text/html'));

        $this->assertNotNull($event);
        $this->assertSame(BufferedEvent::TYPE_VIEW, $event['type']);
        $this->assertSame('/', $event['path']);
    }

    /** @test */
    #[Test]
    public function the_forum_stack_normalizes_a_discussion_path(): void
    {
        $event = $this->forum()->classifyPublic($this->req('/d/42-some-slug', 'text/html'));

        $this->assertSame('/d/42', $event['path']);
        $this->assertSame(42, $event['discussion_id']);
    }

    /** @test */
    #[Test]
    public function the_forum_stack_ignores_non_html_requests(): void
    {
        // Assets and XHR share the stack; only documents are page views.
        $this->assertNull($this->forum()->classifyPublic($this->req('/', 'application/json')));
    }

    // --- api stack ---

    /** @test */
    #[Test]
    public function the_api_stack_counts_real_spa_navigation(): void
    {
        $event = $this->api()->classifyPublic($this->req('/discussions/42'));

        $this->assertNotNull($event);
        $this->assertSame(BufferedEvent::TYPE_VIEW, $event['type']);
        $this->assertSame(42, $event['discussion_id']);
    }

    /** @test */
    #[Test]
    public function the_api_stack_counts_a_search(): void
    {
        $event = $this->api()->classifyPublic($this->req('/discussions', 'application/json', ['filter' => ['q' => 'hello']]));

        $this->assertNotNull($event);
        $this->assertSame(BufferedEvent::TYPE_SEARCH, $event['type']);
        $this->assertSame('hello', $event['search_query']);
    }

    /** @test */
    #[Test]
    public function the_api_stack_drops_the_internal_prefill_that_inherits_text_html(): void
    {
        // The document-prefill ApiClient subrequest carries the page's
        // `Accept: text/html`; a real SPA api call never does. Dropping it here
        // is what prevents the 1.8 double-count.
        $this->assertNull($this->api()->classifyPublic($this->req('/discussions/42', 'text/html')));
        $this->assertNull($this->api()->classifyPublic($this->req('/discussions', 'text/html', ['filter' => ['q' => 'hello']])));
    }

    /** @test */
    #[Test]
    public function the_api_stack_ignores_unrelated_paths(): void
    {
        $this->assertNull($this->api()->classifyPublic($this->req('/users/1')));
        $this->assertNull($this->api()->classifyPublic($this->req('/discussions'))); // no query = list, not a search
    }

    // --- shared heuristics ---

    /** @test */
    #[Test]
    public function bots_are_recognised_by_their_user_agent(): void
    {
        $f = $this->forum();

        $this->assertTrue($f->isBotPublic('Googlebot/2.1'));
        $this->assertTrue($f->isBotPublic('curl/8.1'));
        $this->assertTrue($f->isBotPublic('Mozilla/5.0 ... HeadlessChrome/120'));
        $this->assertFalse($f->isBotPublic('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120 Safari/537.36'));
    }

    /** @test */
    #[Test]
    public function the_device_class_comes_from_the_user_agent(): void
    {
        $f = $this->forum();

        $this->assertSame('mobile', $f->devicePublic('... iPhone ... Mobile/15E148'));
        $this->assertSame('tablet', $f->devicePublic('... iPad ...'));
        $this->assertSame('desktop', $f->devicePublic('Mozilla/5.0 (Windows NT 10.0) Chrome/120'));
    }

    /** @test */
    #[Test]
    public function a_same_site_referrer_is_not_a_referral(): void
    {
        $external = $this->req('/', 'text/html')->withHeader('Referer', 'https://google.com/search')->withUri(new \Laminas\Diactoros\Uri('https://myforum.test/'));
        $internal = $this->req('/', 'text/html')->withHeader('Referer', 'https://myforum.test/d/1')->withUri(new \Laminas\Diactoros\Uri('https://myforum.test/'));

        $this->assertSame('google.com', $this->forum()->referrerPublic($external));
        $this->assertNull($this->forum()->referrerPublic($internal));
    }
}
