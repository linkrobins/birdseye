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

        foreach (['ranges', 'today', 'activation', 'unanswered'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
    }

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
