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
use PHPUnit\Framework\Attributes\Test;

/**
 * The by-month and by-year history (d/39605/60): the whole rollup history
 * folded into calendar buckets, so the dashboard's numbers stop reading as
 * one endlessly accumulating total.
 *
 * Seeded across a month boundary two months back, so no bucket under test
 * ever collides with the "current month is partial" flag.
 */
class CalendarHistoryTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private string $m1;
    private string $m2;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-birdseye');

        // Last day of the month before last, and the 1st/2nd of last month.
        $lastMonth = new \DateTimeImmutable('first day of last month', new \DateTimeZone('UTC'));
        $before = $lastMonth->modify('-1 day');

        $this->m1 = $before->format('Y-m');
        $this->m2 = $lastMonth->format('Y-m');

        $rollups = [];
        $days = [
            [$before->format('Y-m-d'), 3, 30],
            [$lastMonth->format('Y-m-d'), 5, 50],
            [$lastMonth->modify('+1 day')->format('Y-m-d'), 7, 70],
        ];

        foreach ($days as [$date, $visitors, $pageviews]) {
            $rollups[] = ['date' => $date, 'metric' => 'visitors', 'key' => '', 'value' => $visitors];
            $rollups[] = ['date' => $date, 'metric' => 'pageviews', 'key' => '', 'value' => $pageviews];
            $rollups[] = ['date' => $date, 'metric' => 'sessions', 'key' => '', 'value' => 4];
            $rollups[] = ['date' => $date, 'metric' => 'bounce_sessions', 'key' => '', 'value' => 1];
            $rollups[] = ['date' => $date, 'metric' => 'session_seconds', 'key' => '', 'value' => 400];
            $rollups[] = ['date' => $date, 'metric' => 'posts', 'key' => '', 'value' => 2];
            $rollups[] = ['date' => $date, 'metric' => 'registrations', 'key' => '', 'value' => 1];
        }

        $this->prepareDatabase(['birdseye_rollups' => $rollups]);
    }

    private function payload(): array
    {
        $response = $this->send($this->request('GET', '/api/birdseye/stats', ['authenticatedAs' => 1]));

        $this->assertEquals(200, $response->getStatusCode());

        return json_decode($response->getBody()->getContents(), true);
    }

    #[Test]
    public function months_fold_days_into_calendar_buckets(): void
    {
        $months = collect($this->payload()['months'])->keyBy('month');

        $this->assertTrue($months->has($this->m1));
        $this->assertTrue($months->has($this->m2));

        // One day in the older month…
        $this->assertSame(3, $months[$this->m1]['visits']);
        $this->assertSame(30, $months[$this->m1]['pageviews']);

        // …two days folded together in the newer one.
        $this->assertSame(12, $months[$this->m2]['visits']);
        $this->assertSame(120, $months[$this->m2]['pageviews']);
        $this->assertSame(4, $months[$this->m2]['posts']);
        $this->assertSame(2, $months[$this->m2]['registrations']);

        // Derived, not summed: 2 bounces over 8 sessions, 800s over 8.
        $this->assertSame(0.25, $months[$this->m2]['bounce_rate']);
        $this->assertSame(100, $months[$this->m2]['avg_session_sec']);

        // Newest first.
        $keys = array_keys($months->all());
        $this->assertSame($this->m2, $keys[0]);

        // Completed months are not partial.
        $this->assertFalse($months[$this->m1]['partial']);
        $this->assertFalse($months[$this->m2]['partial']);
    }

    #[Test]
    public function years_fold_the_same_days(): void
    {
        $years = collect($this->payload()['years'])->keyBy('year');

        $total = 0;
        foreach ($years as $row) {
            $total += $row['visits'];
        }

        // All 15 seeded visitors appear across the year buckets, however the
        // month boundary happens to fall relative to a year boundary.
        $this->assertSame(15, $total);
    }

    #[Test]
    public function the_current_bucket_is_flagged_partial(): void
    {
        // A rollup for yesterday lands in the current month (or, on the 1st,
        // the month that "yesterday" belongs to — the flag follows today's
        // bucket, so only assert when yesterday is still this month).
        $yesterday = new \DateTimeImmutable('-1 day', new \DateTimeZone('UTC'));

        if ($yesterday->format('Y-m') !== gmdate('Y-m')) {
            $this->markTestSkipped('Yesterday falls in the previous month today.');
        }

        $this->prepareDatabase(['birdseye_rollups' => [
            ['date' => $yesterday->format('Y-m-d'), 'metric' => 'visitors', 'key' => '', 'value' => 1],
        ]]);

        $months = collect($this->payload()['months'])->keyBy('month');

        $this->assertTrue((bool) $months[gmdate('Y-m')]['partial']);
    }
}
