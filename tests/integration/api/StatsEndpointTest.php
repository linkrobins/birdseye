<?php

/*
 * This file is part of linkrobins/birdseye.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Birdseye\Tests\integration\api;

use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
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

    /** @test */
    #[Test]
    public function guests_are_unauthorized(): void
    {
        $response = $this->send($this->request('GET', '/api/birdseye/stats'));

        $this->assertEquals(401, $response->getStatusCode());
    }

    /** @test */
    #[Test]
    public function members_without_the_permission_are_forbidden(): void
    {
        $response = $this->send($this->request('GET', '/api/birdseye/stats', ['authenticatedAs' => 2]));

        $this->assertEquals(403, $response->getStatusCode());
    }

    /** @test */
    #[Test]
    public function admins_get_the_dashboard_payload(): void
    {
        $response = $this->send($this->request('GET', '/api/birdseye/stats', ['authenticatedAs' => 1]));

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getBody()->getContents(), true);

        foreach (['ranges', 'today', 'unanswered'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }

        $this->assertArrayHasKey('new_members', $data['ranges']['7d']);
    }

    /**
     * Someone who joined on a Sunday is counted on that Sunday. The card used
     * to bucket by week and label the row with the Monday, so every Sunday
     * signup read six days early (discuss.flarum.org d/39605/34).
     *
     * @test
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
     *
     * @test
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

    /** @test */
    #[Test]
    public function a_group_granted_view_stats_may_read_it(): void
    {
        $this->database()->table('group_permission')->insert([
            'group_id' => Group::MEMBER_ID,
            'permission' => 'linkrobins-birdseye.viewStats',
        ]);

        $response = $this->send($this->request('GET', '/api/birdseye/stats', ['authenticatedAs' => 2]));

        $this->assertEquals(200, $response->getStatusCode());
    }

    /** @test */
    #[Test]
    public function the_world_map_endpoint_is_gated_the_same_way(): void
    {
        $this->assertEquals(401, $this->send($this->request('GET', '/api/birdseye/world-map'))->getStatusCode());
        $this->assertEquals(200, $this->send($this->request('GET', '/api/birdseye/world-map', ['authenticatedAs' => 1]))->getStatusCode());
    }

    /** @test */
    #[Test]
    public function the_forum_resource_advertises_view_permission_fail_closed(): void
    {
        $asAdmin = json_decode($this->send($this->request('GET', '/api', ['authenticatedAs' => 1]))->getBody()->getContents(), true);
        $asGuest = json_decode($this->send($this->request('GET', '/api'))->getBody()->getContents(), true);

        $this->assertTrue($asAdmin['data']['attributes']['birdseyeCanViewStats']);
        $this->assertFalse($asGuest['data']['attributes']['birdseyeCanViewStats']);
    }
}
