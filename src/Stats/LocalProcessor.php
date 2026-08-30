<?php

namespace LinkRobins\Birdseye\Stats;

use MaxMind\Db\Reader;

/**
 * The Birdseye crunch: one forum-day of events in, rollup rows out.
 *
 * This used to run on a hosted processor, pushed a day at a time over HTTP;
 * the maths is unchanged, it just runs here now, so every stat is computed on
 * the forum's own server and nothing ever leaves it. Deliberately pure — no
 * models, no cache, no state — one day of plain-array events in, rollup rows
 * out.
 *
 * Input events (from SyncBatchJob):
 *   { at: "HH:MM:SS", type: view|search|post|register, path, discussion_id,
 *     visitor, country, referrer, device, ip_prefix, q }
 *
 * Output rollups: [{ date, metric, key, value }] where scalar metrics use
 * key '' — the exact shape upserted into birdseye_rollups.
 *
 * List-metric semantics: page/discussion/search count VIEWS (the right
 * metric for content); source/device/country count DISTINCT VISITORS per
 * key (one person reading 40 pages on desktop is desktop=1, not 40), so
 * the breakdowns reconcile with the headline Visitors tile. Views without
 * a visitor hash are skipped for the visitor-counted metrics but still
 * count as pageviews.
 */
class LocalProcessor
{
    protected const SESSION_GAP_SECONDS = 1800;

    /** Rows kept per list metric; countries run deep for the world map. */
    protected const LIST_CAPS = [
        'page' => 20,
        'discussion' => 20,
        'source' => 20,
        'device' => 5,
        'country' => 50,
        'search' => 20,
    ];

    public function __construct(
        protected ?Reader $geo = null
    ) {
    }

    /**
     * One pass, streaming: `$events` may be a generator, and nothing about a
     * view is retained beyond the running tallies — a 100k-event day costs
     * the same memory as a 100-event one (the per-visitor second-lists for
     * sessionization are the only per-event growth, and they hold one int
     * per view).
     *
     * @param iterable<int, array<string, mixed>> $events
     * @return array<int, array{date: string, metric: string, key: string, value: int}>
     */
    public function process(string $date, iterable $events): array
    {
        $pageviews = 0;
        $visitorSet = [];
        $byVisitor = [];
        $posts = 0;
        $registrations = 0;
        $lists = array_fill_keys(['page', 'discussion', 'search'], []);
        $visitorSets = array_fill_keys(['source', 'device', 'country'], []);
        $searches = 0;

        foreach ($events as $e) {
            $type = (string) ($e['type'] ?? '');

            if ($type === 'post') {
                $posts++;
                continue;
            }

            if ($type === 'register') {
                $registrations++;
                continue;
            }

            if ($type === 'search') {
                $q = mb_strtolower(trim((string) ($e['q'] ?? '')));

                if ($q !== '') {
                    $searches++;
                    $this->bump($lists['search'], mb_substr($q, 0, 150));
                }
                continue;
            }

            if ($type !== 'view') {
                continue;
            }

            $pageviews++;

            $this->collectForSessions($byVisitor, $visitorSet, $e);

            if (($path = (string) ($e['path'] ?? '')) !== '') {
                $this->bump($lists['page'], mb_substr($path, 0, 150));
            }

            if (!empty($e['discussion_id'])) {
                $this->bump($lists['discussion'], (string) (int) $e['discussion_id']);
            }

            $visitor = (string) ($e['visitor'] ?? '');

            if ($visitor === '') {
                continue;
            }

            if (($ref = (string) ($e['referrer'] ?? '')) !== '') {
                $visitorSets['source'][mb_substr($ref, 0, 150)][$visitor] = true;
            }

            $visitorSets['device'][(string) ($e['device'] ?? 'desktop')][$visitor] = true;

            if (($country = $this->country($e)) !== null) {
                $visitorSets['country'][$country][$visitor] = true;
            }
        }

        [$sessions, $bounces, $seconds] = $this->sessionize($byVisitor);

        $visitors = count($visitorSet);

        $rollups = [
            ['date' => $date, 'metric' => 'visitors', 'key' => '', 'value' => $visitors],
            ['date' => $date, 'metric' => 'pageviews', 'key' => '', 'value' => $pageviews],
            ['date' => $date, 'metric' => 'sessions', 'key' => '', 'value' => $sessions],
            ['date' => $date, 'metric' => 'bounce_sessions', 'key' => '', 'value' => $bounces],
            ['date' => $date, 'metric' => 'session_seconds', 'key' => '', 'value' => $seconds],
            ['date' => $date, 'metric' => 'posts', 'key' => '', 'value' => $posts],
            ['date' => $date, 'metric' => 'registrations', 'key' => '', 'value' => $registrations],
            ['date' => $date, 'metric' => 'searches', 'key' => '', 'value' => $searches],
        ];

        foreach (self::LIST_CAPS as $metric => $cap) {
            $counts = array_key_exists($metric, $lists)
                ? $lists[$metric]
                : array_map('count', $visitorSets[$metric]);

            arsort($counts);

            foreach (array_slice($counts, 0, $cap, true) as $key => $value) {
                $rollups[] = ['date' => $date, 'metric' => $metric, 'key' => (string) $key, 'value' => $value];
            }
        }

        return $rollups;
    }

    /** @param array<string, int> $counts */
    protected function bump(array &$counts, string $key): void
    {
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    /**
     * Fold one view into the visitor set and the per-visitor second-lists the
     * sessionizer runs over. Views without a visitor hash join neither (they
     * still counted as pageviews); an unparseable timestamp still marks the
     * visitor as seen but cannot open a session.
     *
     * @param array<string, array<int, int>> $byVisitor
     * @param array<string, true> $visitorSet
     * @param array<string, mixed> $e
     */
    protected function collectForSessions(array &$byVisitor, array &$visitorSet, array $e): void
    {
        $visitor = (string) ($e['visitor'] ?? '');

        if ($visitor === '') {
            return;
        }

        $visitorSet[$visitor] = true;

        $at = (string) ($e['at'] ?? '');

        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $at)) {
            return;
        }

        [$h, $m, $s] = explode(':', $at);
        $byVisitor[$visitor][] = ((int) $h) * 3600 + ((int) $m) * 60 + (int) $s;
    }

    /**
     * 30-minute-gap sessionization over the per-visitor second-lists.
     * Returns [sessions, bounce_sessions, total_session_seconds].
     *
     * @param array<string, array<int, int>> $byVisitor
     * @return array{0: int, 1: int, 2: int}
     */
    protected function sessionize(array $byVisitor): array
    {
        $sessions = $bounces = $seconds = 0;

        foreach ($byVisitor as $times) {
            sort($times);

            $start = $prev = array_shift($times);
            $count = 1;

            foreach ($times as $t) {
                if ($t - $prev > self::SESSION_GAP_SECONDS) {
                    // Close the session that just ended: a single view is a
                    // bounce, anything longer accrues its duration.
                    $sessions++;
                    $count === 1 ? $bounces++ : $seconds += $prev - $start;

                    $start = $t;
                    $count = 0;
                }

                $prev = $t;
                $count++;
            }

            // The visitor's last (or only) session closes at end of day.
            $sessions++;
            $count === 1 ? $bounces++ : $seconds += $prev - $start;
        }

        return [$sessions, $bounces, $seconds];
    }

    /**
     * Country: the proxy-supplied code wins; otherwise a transient lookup on
     * the anonymized prefix against a locally configured MaxMind database,
     * used and immediately forgotten. Lookup failures (no database file, the
     * prefix not in it) just mean "no country".
     *
     * @param array<string, mixed> $event
     */
    protected function country(array $event): ?string
    {
        $code = strtoupper((string) ($event['country'] ?? ''));

        if (preg_match('/^[A-Z]{2}$/', $code)) {
            return $code;
        }

        $prefix = (string) ($event['ip_prefix'] ?? '');

        if ($prefix === '' || $this->geo === null) {
            return null;
        }

        try {
            $iso = $this->geo->get($prefix)['country']['iso_code'] ?? null;

            return is_string($iso) && $iso !== '' ? strtoupper($iso) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
