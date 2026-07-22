<?php

namespace LinkRobins\Birdseye\Stats;

use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use LinkRobins\Birdseye\Buffer\BufferedEvent;
use LinkRobins\Birdseye\Rollup\Rollup;

/**
 * Assembles the dashboard payload from local rollup rows — the same block
 * shape the old Tinybird siteBlock produced, so the dashboard component
 * renders one well-known structure. Everything here reads the forum's OWN
 * database; nothing leaves the server.
 *
 * The forum-health blocks (today, activation, unanswered, tags) are computed
 * entirely locally too — they need no license key and no processor round
 * trip, which is the point: the free tier should already feel like a forum
 * tool, not a generic pageview counter.
 */
class StatsBuilder
{
    /** Scalar metrics summed per day into totals. */
    protected const SCALARS = [
        'visitors', 'pageviews', 'sessions', 'bounce_sessions',
        'session_seconds', 'posts', 'registrations',
    ];

    /** List metrics → payload key + row cap. Countries run 50 deep for the map. */
    protected const LISTS = [
        'page' => ['pages', 8],
        'discussion' => ['discussions', 8],
        'source' => ['sources', 8],
        'device' => ['devices', 8],
        'country' => ['locations', 50],
        'search' => ['searches', 8],
    ];

    /** Weekly cohorts shown on the activation card. */
    protected const ACTIVATION_WEEKS = 8;

    public function __construct(
        protected ConnectionInterface $db
    ) {
    }

    /**
     * @return array{ranges: array<string, mixed>, today: array<string, int>, activation: array<int, mixed>, unanswered: array<int, mixed>}
     */
    public function build(User $actor): array
    {
        return [
            'ranges' => [
                '7d' => $this->range(7, $actor),
                '30d' => $this->range(30, $actor),
            ],
            'today' => $this->today(),
            'activation' => $this->activation(),
            'unanswered' => $this->unanswered($actor),
        ];
    }

    protected function range(int $days, User $actor): array
    {
        // Yesterday back — today's rollup doesn't exist until the day closes.
        $to = gmdate('Y-m-d', strtotime('-1 day'));
        $from = gmdate('Y-m-d', strtotime("-{$days} days"));

        $rows = Rollup::query()
            ->whereBetween('date', [$from, $to])
            ->get();

        // Scalars: per-day for the timeseries, summed for totals.
        $daily = [];
        $totals = array_fill_keys(self::SCALARS, 0);

        foreach ($rows as $row) {
            if ($row->key === '' && in_array($row->metric, self::SCALARS, true)) {
                $day = $row->date->format('Y-m-d');
                $daily[$day][$row->metric] = $row->value;
                $totals[$row->metric] += $row->value;
            }
        }

        $timeseries = [];

        for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
            $day = gmdate('Y-m-d', $d);
            $timeseries[] = [
                'date' => $day,
                'visits' => $daily[$day]['visitors'] ?? 0,
                'pageviews' => $daily[$day]['pageviews'] ?? 0,
            ];
        }

        $block = [
            'totals' => [
                'visits' => $totals['visitors'],
                'pageviews' => $totals['pageviews'],
                // null (not 0) when sessions were never computed — the
                // unkeyed tier — so the UI can show "—" honestly.
                'bounce_rate' => $totals['sessions'] > 0
                    ? round($totals['bounce_sessions'] / $totals['sessions'], 4)
                    : null,
                'avg_session_sec' => $totals['sessions'] > 0
                    ? (int) round($totals['session_seconds'] / $totals['sessions'])
                    : null,
                'posts' => $totals['posts'],
                'registrations' => $totals['registrations'],
            ],
            'timeseries' => $timeseries,
        ];

        // List metrics: sum per key across the range, rank, cap.
        $discussionSums = [];

        foreach (self::LISTS as $metric => [$key, $cap]) {
            $sums = [];

            foreach ($rows as $row) {
                if ($row->metric === $metric && $row->key !== '') {
                    $sums[$row->key] = ($sums[$row->key] ?? 0) + $row->value;
                }
            }

            arsort($sums);

            if ($metric === 'discussion') {
                // The tags card needs the full map, not the top 8.
                $discussionSums = $sums;
            }

            $sums = array_slice($sums, 0, $cap, true);

            $block[$key] = array_map(
                fn ($label, $visits) => ['label' => (string) $label, 'visits' => $visits],
                array_keys($sums),
                array_values($sums)
            );
        }

        $block['discussions'] = $this->titleDiscussions($block['discussions'], $actor);
        // null (not []) when the tags extension isn't installed, so the
        // frontend can drop the card instead of showing an empty one.
        $block['tags'] = $this->tagViews($discussionSums);

        return $block;
    }

    /**
     * Today's raw counts straight from the capture buffer. Rollups only
     * exist for finished days; this closes the freshness gap without
     * touching the one-complete-day-per-push contract. Counts are exact for
     * pageviews/posts/registrations; "visitors" is distinct hashes today,
     * which matches what the rollup will say when the day closes.
     *
     * @return array{visits: int, pageviews: int, posts: int, registrations: int}
     */
    protected function today(): array
    {
        $start = gmdate('Y-m-d 00:00:00');

        $base = fn () => BufferedEvent::query()->where('occurred_at', '>=', $start);

        return [
            // count(distinct visitor) skips NULLs on every driver, which is
            // what we want — post/register rows carry no visitor hash.
            'visits' => $base()->where('type', BufferedEvent::TYPE_VIEW)->distinct()->count('visitor'),
            'pageviews' => $base()->where('type', BufferedEvent::TYPE_VIEW)->count(),
            'posts' => $base()->where('type', BufferedEvent::TYPE_POST)->count(),
            'registrations' => $base()->where('type', BufferedEvent::TYPE_REGISTER)->count(),
        ];
    }

    /**
     * Lurker→poster conversion: for each of the last N join-weeks, the share
     * of new members who posted anything within 7 days of joining. Computed
     * from the forum's own users/posts tables — no capture involved. The
     * newest cohorts read low until their 7-day window has elapsed; the UI
     * says so.
     *
     * @return array<int, array{week: string, joined: int, converted: int, pct: float|null}>
     */
    protected function activation(): array
    {
        $weekStarts = [];
        $monday = new \DateTimeImmutable('monday this week', new \DateTimeZone('UTC'));

        for ($i = self::ACTIVATION_WEEKS - 1; $i >= 0; $i--) {
            $weekStarts[] = $monday->modify("-{$i} weeks");
        }

        $since = $weekStarts[0]->format('Y-m-d 00:00:00');

        // One portable pass: each new user with their first-ever post time.
        // Grouping by both selected non-aggregates keeps Postgres happy; the
        // hard limit bounds pathological mass-signup forums (cohorts beyond
        // it would be sampled, not silently wrong: rows arrive join-ordered).
        $users = $this->db->table('users')
            ->leftJoin('posts', 'posts.user_id', '=', 'users.id')
            ->where('users.joined_at', '>=', $since)
            ->groupBy('users.id', 'users.joined_at')
            ->orderBy('users.joined_at')
            ->limit(20000)
            ->get(['users.id', 'users.joined_at', $this->db->raw('MIN(posts.created_at) as first_post_at')]);

        $cohorts = [];

        foreach ($weekStarts as $start) {
            $cohorts[$start->format('Y-m-d')] = ['joined' => 0, 'converted' => 0];
        }

        foreach ($users as $user) {
            $joined = new \DateTimeImmutable((string) $user->joined_at, new \DateTimeZone('UTC'));
            $week = $joined->modify('monday this week')->format('Y-m-d');

            if (!isset($cohorts[$week])) {
                continue;
            }

            $cohorts[$week]['joined']++;

            if ($user->first_post_at !== null
                && new \DateTimeImmutable((string) $user->first_post_at, new \DateTimeZone('UTC')) <= $joined->modify('+7 days')) {
                $cohorts[$week]['converted']++;
            }
        }

        return array_map(fn ($week, $c) => [
            'week' => $week,
            'joined' => $c['joined'],
            'converted' => $c['converted'],
            'pct' => $c['joined'] > 0 ? round($c['converted'] / $c['joined'], 4) : null,
        ], array_keys($cohorts), array_values($cohorts));
    }

    /**
     * Read-to-reply gaps: discussions people viewed this week that nobody
     * replied to in the same window — ranked by views, so the top row is
     * the loudest unanswered question on the forum. Titles are resolved
     * through the viewer's visibility scope like everywhere else.
     *
     * @return array<int, array{label: string, visits: int}>
     */
    protected function unanswered(User $actor): array
    {
        $to = gmdate('Y-m-d', strtotime('-1 day'));
        $from = gmdate('Y-m-d', strtotime('-7 days'));

        $views = [];

        $rows = Rollup::query()
            ->where('metric', 'discussion')
            ->whereBetween('date', [$from, $to])
            ->get();

        foreach ($rows as $row) {
            $views[(int) $row->key] = ($views[(int) $row->key] ?? 0) + $row->value;
        }

        arsort($views);
        // Candidates are the 50 most-viewed; enough that the top 8 gaps are
        // exact while whereIn stays bounded.
        $views = array_slice($views, 0, 50, true);

        if ($views === []) {
            return [];
        }

        // type=comment excludes event posts (renames, tag changes) — those
        // are not replies; number>1 excludes the opening post itself.
        $replied = $this->db->table('posts')
            ->whereIn('discussion_id', array_keys($views))
            ->where('created_at', '>=', $from . ' 00:00:00')
            ->where('type', 'comment')
            ->where('number', '>', 1)
            ->distinct()
            ->pluck('discussion_id')
            ->all();

        $gaps = array_diff_key($views, array_flip(array_map('intval', $replied)));
        $gaps = array_slice($gaps, 0, 8, true);

        if ($gaps === []) {
            return [];
        }

        $titles = Discussion::query()
            ->whereVisibleTo($actor)
            ->whereIn('id', array_keys($gaps))
            ->pluck('title', 'id');

        $out = [];

        foreach ($gaps as $id => $visits) {
            // Invisible-to-viewer (or deleted) discussions drop out rather
            // than leak a title or show an unactionable "#id" row.
            if (isset($titles[$id])) {
                $out[] = ['label' => (string) $titles[$id], 'visits' => $visits];
            }
        }

        return $out;
    }

    /**
     * View counts aggregated by tag, from the discussion rollups joined to
     * the tags tables locally. Soft dependency: returns null (card hidden)
     * when the tags extension isn't installed. Restricted tags are excluded
     * for every viewer — a permission-holding member must not learn a staff
     * area's name from an analytics card.
     *
     * @param array<int|string, int> $discussionSums full views-per-discussion map for the range
     * @return array<int, array{label: string, visits: int}>|null
     */
    protected function tagViews(array $discussionSums): ?array
    {
        if (!$this->db->getSchemaBuilder()->hasTable('tags')) {
            return null;
        }

        if ($discussionSums === []) {
            return [];
        }

        // Bound the whereIn: the 500 most-viewed discussions carry virtually
        // all the signal a top-8 tag ranking needs.
        $ids = array_slice(array_keys($discussionSums), 0, 500);

        $rows = $this->db->table('discussion_tag')
            ->join('tags', 'tags.id', '=', 'discussion_tag.tag_id')
            ->whereIn('discussion_tag.discussion_id', $ids)
            ->where('tags.is_restricted', false)
            ->get(['discussion_tag.discussion_id', 'tags.name']);

        $sums = [];

        foreach ($rows as $row) {
            $views = $discussionSums[$row->discussion_id] ?? $discussionSums[(string) $row->discussion_id] ?? 0;
            $sums[$row->name] = ($sums[$row->name] ?? 0) + $views;
        }

        arsort($sums);
        $sums = array_slice($sums, 0, 8, true);

        return array_map(
            fn ($label, $visits) => ['label' => (string) $label, 'visits' => $visits],
            array_keys($sums),
            array_values($sums)
        );
    }

    /**
     * Discussion rollup keys are ids; resolve titles locally at render time
     * (the processor never needs to know titles), scoped to what the viewer
     * may see — the dashboard can be granted to non-admin groups now, and a
     * private tag's discussion titles must not leak through a stats card.
     * Invisible and deleted discussions keep their id as the label.
     *
     * @param array<int, array{label: string, visits: int}> $rows
     * @return array<int, array{label: string, visits: int}>
     */
    protected function titleDiscussions(array $rows, User $actor): array
    {
        if ($rows === []) {
            return $rows;
        }

        $titles = Discussion::query()
            ->whereVisibleTo($actor)
            ->whereIn('id', array_map(fn ($r) => (int) $r['label'], $rows))
            ->pluck('title', 'id');

        return array_map(fn ($r) => [
            'label' => (string) ($titles[(int) $r['label']] ?? "#{$r['label']}"),
            'visits' => $r['visits'],
        ], $rows);
    }
}
