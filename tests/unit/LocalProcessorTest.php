<?php

/*
 * This file is part of linkrobins/birdseye.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Birdseye\Tests\unit;

use LinkRobins\Birdseye\Stats\LocalProcessor;
use PHPUnit\Framework\TestCase;

/**
 * The whole product in one class: a day of events in, rollups out. Every
 * expectation below is computed by hand from the fixture, so this guards the
 * maths itself, not a snapshot of what the code happened to produce. The
 * fixture is small enough to recompute on paper:
 *
 *   vA views 00:00:00 and 00:10:00 (one 600s session), then 01:00:00 —
 *       a 3000s gap, past the 1800s session cutoff, so a second, single-view
 *       (bounce) session.
 *   vB views once at 05:00:00 — a bounce.
 *   One view has no visitor hash: it counts as a pageview but can never join
 *   a session, a visitor count, or a per-visitor breakdown.
 */
class LocalProcessorTest extends TestCase
{
    private function day(): array
    {
        $view = fn (string $at, string $visitor, string $path, array $extra = []) => $extra + [
            'at' => $at,
            'type' => 'view',
            'path' => $path,
            'discussion_id' => null,
            'visitor' => $visitor,
            'country' => '',
            'referrer' => '',
            'device' => 'desktop',
            'ip_prefix' => '',
            'q' => null,
        ];

        return [
            $view('00:00:00', 'vA', '/a', ['discussion_id' => 5, 'country' => 'US', 'referrer' => 'google.com']),
            $view('00:10:00', 'vA', '/a', ['discussion_id' => 5, 'country' => 'US']),
            $view('01:00:00', 'vA', '/b', ['country' => 'US']),
            $view('05:00:00', 'vB', '/c', ['country' => 'KR', 'device' => 'phone']),
            $view('06:00:00', '', '/d'),
            ['at' => '10:00:00', 'type' => 'post'],
            ['at' => '10:00:01', 'type' => 'post'],
            ['at' => '11:00:00', 'type' => 'register'],
            ['at' => '12:00:00', 'type' => 'search', 'q' => 'Foo'],
            ['at' => '12:00:05', 'type' => 'search', 'q' => 'foo '],
            ['at' => '12:00:09', 'type' => 'search', 'q' => ''],
        ];
    }

    private function rollups(): array
    {
        $out = [];

        foreach ((new LocalProcessor())->process('2026-08-20', $this->day()) as $r) {
            $out[$r['metric'].'|'.$r['key']] = $r['value'];
        }

        return $out;
    }

    /**
     * @test
     */
    public function scalar_metrics_are_computed_by_hand_correctly()
    {
        $r = $this->rollups();

        $this->assertSame(2, $r['visitors|'], 'two distinct hashes; the hashless view adds nobody');
        $this->assertSame(5, $r['pageviews|'], 'every view counts, hashless included');
        $this->assertSame(3, $r['sessions|'], 'vA splits across the 30-minute gap; vB has one');
        $this->assertSame(2, $r['bounce_sessions|'], 'vA second session and vB');
        $this->assertSame(600, $r['session_seconds|'], 'only the two-view session accrues time');
        $this->assertSame(2, $r['posts|']);
        $this->assertSame(1, $r['registrations|']);
        $this->assertSame(2, $r['searches|'], 'the empty query never counts');
    }

    /**
     * @test
     */
    public function list_metrics_count_the_right_unit()
    {
        $r = $this->rollups();

        // Content lists count views…
        $this->assertSame(2, $r['page|/a']);
        $this->assertSame(1, $r['page|/d'], 'hashless views still count as content views');
        $this->assertSame(2, $r['discussion|5']);
        $this->assertSame(2, $r['search|foo'], 'queries are trimmed and lowercased before counting');

        // …audience lists count distinct visitors, so they reconcile with the
        // Visitors tile instead of triple-counting one person's browsing.
        $this->assertSame(1, $r['device|desktop'], 'vA browsed three pages on desktop and is one person');
        $this->assertSame(1, $r['device|phone']);
        $this->assertSame(1, $r['country|US']);
        $this->assertSame(1, $r['country|KR']);
        $this->assertSame(1, $r['source|google.com']);
        $this->assertArrayNotHasKey('device|', $r, 'the hashless view joins no audience list');
    }

    /**
     * @test
     */
    public function a_generator_produces_the_same_rollups_as_an_array()
    {
        // The sync job feeds a generator so a large day is never materialized;
        // the aggregation must not care which it gets.
        $fromArray = (new LocalProcessor())->process('2026-08-20', $this->day());
        $fromGenerator = (new LocalProcessor())->process('2026-08-20', (function () {
            yield from $this->day();
        })());

        $this->assertSame($fromArray, $fromGenerator);
    }

    /**
     * @test
     */
    public function an_unparseable_timestamp_is_a_pageview_but_never_a_session()
    {
        $events = $this->day();
        $events[] = ['at' => 'bogus', 'type' => 'view', 'path' => '/x', 'visitor' => 'vC'];

        $out = [];
        foreach ((new LocalProcessor())->process('2026-08-20', $events) as $r) {
            $out[$r['metric'].'|'.$r['key']] = $r['value'];
        }

        $this->assertSame(6, $out['pageviews|']);
        $this->assertSame(3, $out['visitors|'], 'the hash is real even when the clock is not');
        $this->assertSame(3, $out['sessions|'], 'but an unparseable time cannot open a session');
    }
}
