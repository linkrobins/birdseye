<?php

/*
 * This file is part of linkrobins/birdseye.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Birdseye\Tests\integration\api;

use Flarum\Foundation\Paths;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use LinkRobins\Birdseye\Stats\CountryDownload;
use LinkRobins\Birdseye\Tests\unit\MmdbFixture;
use PHPUnit\Framework\Attributes\Test;

class StatsEndpointTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-birdseye');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2, a plain member
            ],
        ]);
    }

    #[Test]
    public function guests_are_unauthorized(): void
    {
        $response = $this->send($this->request('GET', '/api/birdseye/stats'));

        $this->assertEquals(401, $response->getStatusCode());
    }

    #[Test]
    public function members_without_the_permission_are_forbidden(): void
    {
        $response = $this->send($this->request('GET', '/api/birdseye/stats', ['authenticatedAs' => 2]));

        $this->assertEquals(403, $response->getStatusCode());
    }

    #[Test]
    public function admins_get_the_dashboard_payload(): void
    {
        $response = $this->send($this->request('GET', '/api/birdseye/stats', ['authenticatedAs' => 1]));

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getBody()->getContents(), true);

        foreach (['ranges', 'today', 'unanswered', 'country_lookup'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }

        $this->assertArrayHasKey('new_members', $data['ranges']['7d']);
    }

    /**
     * A forum behind no country-supplying proxy and with no local MaxMind
     * database resolves no country for anyone, and the map then draws a world
     * of zeroes that looks exactly like "nobody visited". Two operators
     * reported that as a bug. The payload now says which one it is.
     *
     * @test
     */
    #[Test]
    public function country_lookup_is_flagged_when_no_event_carries_a_country(): void
    {
        $this->setting('linkrobins-birdseye.geoip_auto_download', '');
        $this->seedEvents(null);

        $this->assertSame('unconfigured', $this->countryLookup());
    }

    /**
     * The opposite case: a proxy header is supplying countries, so there is
     * nothing to warn about even though no MaxMind database is configured.
     *
     * @test
     */
    #[Test]
    public function country_lookup_is_silent_when_events_carry_a_country(): void
    {
        $this->seedEvents('DE');

        $this->assertNull($this->countryLookup());
    }

    /**
     * A forum with no recent traffic gives nothing to judge by. Saying
     * "unconfigured" there would cry wolf at every new or quiet forum, so the
     * absence of events must stay silent rather than read as a fault.
     *
     * @test
     */
    #[Test]
    public function country_lookup_is_silent_when_there_is_no_traffic_to_judge(): void
    {
        $this->assertNull($this->countryLookup());
    }

    /**
     * A database path that is set but cannot be used is reported with its
     * reason, and even with no traffic: it is a definite misconfiguration,
     * not a judgment from the events. "Not configured" sent an admin who had
     * set a path looking for a setting they had already filled in
     * (d/39605/90).
     *
     * @test
     */
    #[Test]
    public function a_set_but_unusable_database_is_reported_with_its_reason(): void
    {
        $this->setting('linkrobins-birdseye.geoip_db_path', '/nowhere/GeoLite2-Country.mmdb');

        $this->assertSame('missing', $this->countryLookup());
    }

    /**
     * With the automatic download on (the default) and nothing downloaded
     * yet, traffic without countries points at the scheduler, not at a
     * setting the admin has to find.
     *
     * @test
     */
    #[Test]
    public function a_download_that_has_not_run_yet_is_reported_as_pending(): void
    {
        $this->seedEvents(null);

        $this->assertSame('download_pending', $this->countryLookup());
    }

    /**
     * A failed download is a definite fault, so it is reported even with no
     * traffic, and with the error itself.
     *
     * @test
     */
    #[Test]
    public function a_failed_download_is_reported_with_its_error(): void
    {
        $this->setting(CountryDownload::ERROR, 'cURL error 6: Could not resolve host: download.db-ip.com');

        $payload = $this->payload();

        $this->assertSame('download_failed', $payload['country_lookup']);
        $this->assertSame('cURL error 6: Could not resolve host: download.db-ip.com', $payload['country_error']);
    }

    /**
     * Once the DB-IP database is on disk it is used, and the dashboard is
     * told to show the credit its licence asks for. A failure recorded since
     * is beside the point while the earlier copy still works.
     *
     * @test
     */
    #[Test]
    public function the_downloaded_database_is_used_and_credited(): void
    {
        $this->setting(CountryDownload::ERROR, 'a later attempt failed');
        $path = CountryDownload::path($this->app()->getContainer()->make(Paths::class));
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, MmdbFixture::bytes('DBIP-Country-Lite'));

        try {
            $payload = $this->payload();
        } finally {
            unlink($path);
        }

        $this->assertNull($payload['country_lookup']);
        $this->assertTrue($payload['country_credit']);
    }

    /**
     * No credit when DB-IP's database is not the one in use.
     *
     * @test
     */
    #[Test]
    public function there_is_no_credit_without_the_dbip_database(): void
    {
        $this->assertFalse($this->payload()['country_credit']);
    }

    /** The country_lookup verdict from a fresh dashboard payload. */
    private function countryLookup(): ?string
    {
        return $this->payload()['country_lookup'];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $response = $this->send($this->request('GET', '/api/birdseye/stats', ['authenticatedAs' => 1]));

        $this->assertEquals(200, $response->getStatusCode());

        return json_decode($response->getBody()->getContents(), true);
    }

    /** Two buffered views, both carrying (or both missing) a country. */
    private function seedEvents(?string $country): void
    {
        $day = gmdate('Y-m-d');

        foreach (['v001', 'v002'] as $i => $visitor) {
            $this->database()->table('birdseye_events')->insert([
                'type' => 'view',
                'path' => '/',
                'visitor' => $visitor,
                'country' => $country,
                'occurred_at' => sprintf('%s 10:0%d:00', $day, $i),
            ]);
        }
    }

    /**
     * Someone who joined on a Sunday is counted on that Sunday. The card used
     * to bucket by week and label the row with the Monday, so every Sunday
     * signup read six days early (discuss.flarum.org d/39605/34).
     */
    #[Test]
    public function a_new_member_is_counted_on_the_day_they_registered(): void
    {
        $this->seedUser(3, 'joined_sunday', true, '-2 days');

        $expected = (new \DateTimeImmutable('-2 days', new \DateTimeZone('UTC')))->format('Y-m-d');

        $rows = $this->newMemberRows();

        $this->assertArrayHasKey($expected, $rows);
        $this->assertSame(1, $rows[$expected]);
    }

    /**
     * An account that never confirmed its email isn't a member — Flarum grants
     * the Member group off is_email_confirmed, so an unconfirmed account has
     * guest permissions (d/39605/37). It still shows in the Signups tile.
     */
    #[Test]
    public function unconfirmed_signups_are_not_counted_as_members(): void
    {
        $this->seedUser(3, 'unconfirmed', false, '-2 days');

        $day = (new \DateTimeImmutable('-2 days', new \DateTimeZone('UTC')))->format('Y-m-d');

        $this->assertArrayNotHasKey($day, $this->newMemberRows());
    }

    /** @return array<string, int> ISO day => new members, for the 7-day range. */
    protected function newMemberRows(): array
    {
        // The installer stamps the admin with joined_at = install time, which
        // would otherwise land in whichever day we're asserting on.
        $this->database()->table('users')->where('id', 1)->update(['joined_at' => '2020-01-01 00:00:00']);

        $data = json_decode(
            $this->send($this->request('GET', '/api/birdseye/stats', ['authenticatedAs' => 1]))->getBody()->getContents(),
            true
        );

        $rows = [];

        foreach ($data['ranges']['7d']['new_members'] as $row) {
            $rows[$row['label']] = $row['visits'];
        }

        return $rows;
    }

    protected function seedUser(int $id, string $name, bool $confirmed, string $joined): void
    {
        $this->database()->table('users')->insert([
            [
                'id' => $id,
                'username' => $name,
                'email' => $name.'@machine.local',
                'password' => 'x',
                'is_email_confirmed' => $confirmed ? 1 : 0,
                'joined_at' => (new \DateTimeImmutable($joined, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    #[Test]
    public function a_group_granted_view_stats_may_read_it(): void
    {
        $this->database()->table('group_permission')->insert([
            'group_id' => Group::MEMBER_ID,
            'permission' => 'lr-birdseye.viewStats',
        ]);

        $response = $this->send($this->request('GET', '/api/birdseye/stats', ['authenticatedAs' => 2]));

        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function the_world_map_endpoint_is_gated_the_same_way(): void
    {
        $this->assertEquals(401, $this->send($this->request('GET', '/api/birdseye/world-map'))->getStatusCode());
        $this->assertEquals(200, $this->send($this->request('GET', '/api/birdseye/world-map', ['authenticatedAs' => 1]))->getStatusCode());
    }

    #[Test]
    public function the_forum_resource_advertises_view_permission_fail_closed(): void
    {
        $asAdmin = json_decode($this->send($this->request('GET', '/api', ['authenticatedAs' => 1]))->getBody()->getContents(), true);
        $asGuest = json_decode($this->send($this->request('GET', '/api'))->getBody()->getContents(), true);

        $this->assertTrue($asAdmin['data']['attributes']['birdseyeCanViewStats']);
        $this->assertFalse($asGuest['data']['attributes']['birdseyeCanViewStats']);
    }
}
