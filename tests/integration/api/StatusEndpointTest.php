<?php

/*
 * This file is part of linkrobins/birdseye.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Birdseye\Tests\integration\api;

use Flarum\Extend;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;

/**
 * The endpoint behind the settings-page banner.
 *
 * Its whole reason to exist is that the banner should say something true, so
 * what is worth pinning down is that it passes Birdseye's verdict through
 * unchanged, that it never says "connected" when it does not know, and that the
 * license key stays on the server.
 */
class StatusEndpointTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-birdseye');

        $this->extend(
            (new Extend\ServiceProvider())->register(FakeStatusProvider::class)
        );

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2, a plain member
            ],
        ]);

        FakeStatusHandler::$response = null;
        FakeStatusHandler::$sent = [];
    }

    #[Test]
    public function guests_are_unauthorized(): void
    {
        $this->assertEquals(401, $this->send($this->request('GET', '/api/birdseye/status'))->getStatusCode());
    }

    /**
     * The connection state is an operator's business, and the request carries
     * the license key onward, so members have no place here even though the
     * dashboard itself can be shared with them.
     */
    #[Test]
    public function members_are_forbidden(): void
    {
        $response = $this->send($this->request('GET', '/api/birdseye/status', ['authenticatedAs' => 2]));

        $this->assertEquals(403, $response->getStatusCode());
    }

    #[Test]
    public function with_no_key_saved_it_says_so_without_calling_out(): void
    {
        $this->assertEquals('no_key', $this->ask()['state']);
        $this->assertCount(0, FakeStatusHandler::$sent, 'nothing to ask about, so nothing should be sent');
    }

    #[Test]
    public function it_passes_birdseyes_verdict_through(): void
    {
        $this->setting('linkrobins-birdseye.license_key', 'bde_test');

        foreach (['active', 'incomplete', 'canceled', 'invalid_key', 'bound_elsewhere'] as $status) {
            FakeStatusHandler::$response = new Response(200, [], (string) json_encode([
                'status' => $status,
                'last_seen_at' => '2026-08-14T10:00:00+00:00',
                'forum_url' => 'https://elsewhere.example',
            ]));

            $body = $this->ask();

            $this->assertEquals($status, $body['state']);
            $this->assertEquals('2026-08-14T10:00:00+00:00', $body['lastSeenAt']);
        }
    }

    /**
     * A key pasted minutes ago has no last-seen time, because stats are pushed
     * a whole day at a time. That is not a fault and must not read as one.
     */
    #[Test]
    public function a_key_that_has_never_reported_is_still_active(): void
    {
        $this->setting('linkrobins-birdseye.license_key', 'bde_test');

        FakeStatusHandler::$response = new Response(200, [], (string) json_encode([
            'status' => 'active',
            'last_seen_at' => null,
        ]));

        $body = $this->ask();

        $this->assertEquals('active', $body['state']);
        $this->assertNull($body['lastSeenAt']);
    }

    /**
     * Not knowing is its own answer. Reporting anything else here would put a
     * connected badge on a forum whose subscription may have lapsed.
     */
    #[Test]
    public function a_service_that_cannot_be_reached_is_reported_as_such(): void
    {
        $this->setting('linkrobins-birdseye.license_key', 'bde_test');

        FakeStatusHandler::$response = new Response(500);
        $this->assertEquals('unreachable', $this->ask()['state']);

        FakeStatusHandler::$response = new Response(200, [], 'not json at all');
        $this->assertEquals('unreachable', $this->ask()['state']);

        FakeStatusHandler::$response = null; // the client throws
        $this->assertEquals('unreachable', $this->ask()['state']);
    }

    /**
     * The key authenticates the request and is not part of the answer, so it
     * never reaches the browser.
     */
    #[Test]
    public function the_license_key_stays_on_the_server(): void
    {
        $this->setting('linkrobins-birdseye.license_key', 'bde_secret_value');

        FakeStatusHandler::$response = new Response(200, [], (string) json_encode(['status' => 'active']));

        $response = $this->send($this->request('GET', '/api/birdseye/status', ['authenticatedAs' => 1]));

        $this->assertStringNotContainsString('bde_secret_value', (string) $response->getBody());
        $this->assertEquals('Bearer bde_secret_value', FakeStatusHandler::$sent[0]->getHeaderLine('Authorization'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function ask(): array
    {
        $response = $this->send($this->request('GET', '/api/birdseye/status', ['authenticatedAs' => 1]));

        $this->assertEquals(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true);
    }
}
