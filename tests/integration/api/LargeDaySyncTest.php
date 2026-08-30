<?php

/*
 * This file is part of linkrobins/birdseye.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Birdseye\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use LinkRobins\Birdseye\Job\SyncBatchJob;
use PHPUnit\Framework\Attributes\Test;

/**
 * The stall from d/39605/68: the sync job always retries the OLDEST complete
 * day first, so a day big enough to kill the run (v2.3.0 materialized the
 * whole day as an array before processing) wedges the extension on that day
 * forever — every hourly run dies in the same place, rollups stop, the today
 * strip keeps working, and the 72-hour prune starts eating the backlog. The
 * day is streamed now; this exercises a deliberately large one end to end
 * and pins the numbers.
 */
class LargeDaySyncTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const VIEWS = 6000;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-birdseye');

        $day = gmdate('Y-m-d', time() - 86400);
        $rows = [];

        // 6000 views from 500 visitors, each visitor's dozen views a minute
        // apart: one session per visitor, eleven minutes long, no bounces.
        for ($i = 0; $i < self::VIEWS; $i++) {
            $visitor = 'v'.str_pad((string) ($i % 500), 3, '0', STR_PAD_LEFT);
            $minute = intdiv($i, 500);

            $rows[] = [
                'type' => 'view',
                'path' => '/d/'.($i % 25),
                'discussion_id' => $i % 25,
                'visitor' => $visitor,
                'occurred_at' => sprintf('%s 10:%02d:00', $day, $minute),
            ];
        }

        $this->prepareDatabase([
            'users' => [$this->normalUser()],
            'birdseye_events' => $rows,
        ]);
    }

    /**
     * @test
     */
    #[Test]
    public function a_six_thousand_event_day_streams_through_in_one_run()
    {
        $this->app()->getContainer()->make(\Illuminate\Contracts\Bus\Dispatcher::class)
            ->dispatch(new SyncBatchJob());

        $this->assertSame(0, (int) $this->database()->table('birdseye_events')->count(), 'the day was consumed');

        $rollups = [];
        foreach ($this->database()->table('birdseye_rollups')->where('key', '')->get() as $r) {
            $rollups[$r->metric] = (int) $r->value;
        }

        $this->assertSame(self::VIEWS, $rollups['pageviews']);
        $this->assertSame(500, $rollups['visitors']);
        $this->assertSame(500, $rollups['sessions'], 'a minute between views never splits a session');
        $this->assertSame(0, $rollups['bounce_sessions']);
        $this->assertSame(500 * 11 * 60, $rollups['session_seconds'], 'eleven minutes per visitor');
    }
}
