<?php

namespace LinkRobins\Birdseye\Stats;

use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\Post;
use Flarum\User\User;
use LinkRobins\Birdseye\Buffer\BufferedEvent;
use LinkRobins\Birdseye\Rollup\Rollup;

/**
 * Assembles the dashboard payload from local rollup rows — the same block
 * shape the old Tinybird siteBlock produced, so the dashboard component
 * renders one well-known structure. Everything here reads the forum's OWN
 * database; nothing leaves the server.
 *
 * The forum-health blocks (today, new members, unanswered, tags) are computed
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

    /** Days shown on the new-members card, newest first. */
    protected const MEMBER_DAYS = 8;

    /** Users per scan chunk; MEMBER_CAP bounds the whole scan. */
    protected const MEMBER_CHUNK = 2000;
    protected const MEMBER_CAP = 20000;

    public function __construct(
        protected ExtensionManager $extensions
    ) {
    }

    /**
     * @return array{ranges: array<string, mixed>, today: array<string, int>, unanswered: array<int, mixed>}
     */
    public function build(User $actor): array
    {
        return [
            'ranges' => [
                '7d' => $this->range(7, $actor),
                '30d' => $this->range(30, $actor),
            ],
            'today' => $this->today(),
            'unanswered' => $this->unanswered($actor),
        ];
    }

    /** @return array<string, mixed> */
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
                fn ($label, $visits) => $this->listRow($metric, (string) $label, $visits),
                array_keys($sums),
                array_values($sums)
            );
        }

        $block['discussions'] = $this->titleDiscussions($block['discussions'], $actor);
        // null (not []) when the tags extension isn't installed, so the
        // frontend can drop the card instead of showing an empty one.
        $block['tags'] = $this->tagViews($discussionSums);
        $block['new_members'] = $this->newMembers($from, $to);

        return $block;
    }

    /**
     * One breakdown row, with a forum-relative link to the content it counts
     * where one exists so the dashboard can offer a click-through. Pages are
     * already a path; searches rebuild the query URL. Sources/devices/
     * countries have nowhere meaningful to go, so they stay plain.
     *
     * @return array{label: string, visits: int, url?: string}
     */
    protected function listRow(string $metric, string $label, int $visits): array
    {
        $row = ['label' => $label, 'visits' => $visits];

        $url = match ($metric) {
            // A page key is a captured request path. Only offer it as a link
            // when it's a plain same-origin absolute path — never a protocol-
            // relative "//host" (the frontend refuses those too, belt and
            // braces). Empty means the forum root.
            'page' => $label === '' ? '/' : (preg_match('#^/(?:[^/].*)?$#', $label) ? $label : null),
            'search' => '/?q=' . rawurlencode($label),
            default => null,
        };

        if ($url !== null) {
            $row['url'] = $url;
        }

        return $row;
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
     * How many people became members on each day of the range — counted on
     * the day they actually registered, not on a bucket boundary
     * (discuss.flarum.org d/39605/36).
     *
     * "New member" is Flarum's Member group, which core grants off
     * `is_email_confirmed` (see User::permissions()) — there are no
     * group_user rows to join against (d/39605/37). Accounts that never
     * confirmed are not members; they're counted raw by the Signups tile.
     *
     * Read from the forum's own users table, no capture involved.
     *
     * @return array<int, array{label: string, visits: int}>
     */
    protected function newMembers(string $from, string $to): array
    {
        $days = [];

        // Chunked so a mass-signup forum can't balloon PHP memory. The cap
        // bounds pathological forums; days beyond it are sampled (rows arrive
        // id-ordered ≈ join-ordered), not silently wrong.
        $processed = 0;

        User::query()
            ->whereBetween('joined_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->select('id', 'joined_at', 'is_email_confirmed')
            ->chunkById(self::MEMBER_CHUNK, function ($users) use (&$days, &$processed) {
                foreach ($users as $user) {
                    // Read the flag through the model cast, so MySQL's
                    // tinyint and Postgres' boolean both land as a PHP bool.
                    if (!$user->is_email_confirmed) {
                        continue;
                    }

                    // The raw column string (not the cast) so the date is
                    // byte-identical to what the database stores — UTC.
                    $day = substr((string) $user->getRawOriginal('joined_at'), 0, 10);

                    $days[$day] = ($days[$day] ?? 0) + 1;
                }

                $processed += $users->count();

                return $processed < self::MEMBER_CAP;
            });

        // Newest day first, then the same row cap the other cards use.
        krsort($days);
        $days = array_slice($days, 0, self::MEMBER_DAYS, true);

        return array_map(
            fn ($day, $count) => ['label' => $day, 'visits' => $count],
            array_keys($days),
            array_values($days)
        );
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
        $replied = Post::query()
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
                $out[] = ['label' => (string) $titles[$id], 'visits' => $visits, 'url' => '/d/' . (int) $id];
            }
        }

        return $out;
    }

    /**
     * View counts aggregated by tag, from the discussion rollups joined to
     * the tags tables locally. Soft dependency: returns null (card hidden)
     * unless the tags extension is enabled. Restricted tags are excluded
     * for every viewer — a permission-holding member must not learn a staff
     * area's name from an analytics card.
     *
     * @param array<int|string, int> $discussionSums full views-per-discussion map for the range
     * @return array<int, array{label: string, visits: int}>|null
     */
    protected function tagViews(array $discussionSums): ?array
    {
        if (!$this->extensions->isEnabled('flarum-tags')) {
            return null;
        }

        if ($discussionSums === []) {
            return [];
        }

        // Bound the whereIn: the 500 most-viewed discussions carry virtually
        // all the signal a top-8 tag ranking needs.
        $ids = array_slice(array_keys($discussionSums), 0, 500);

        // Through the Discussion builder (not the raw connection) so table
        // prefixing and connection handling stay Eloquent's problem; no
        // flarum/tags class is referenced, keeping the dependency soft.
        // toBase() because these joined rows aren't Discussion models —
        // they're (discussion_id, tag name, tag slug) tuples.
        $rows = Discussion::query()
            ->join('discussion_tag', 'discussion_tag.discussion_id', '=', 'discussions.id')
            ->join('tags', 'tags.id', '=', 'discussion_tag.tag_id')
            ->whereIn('discussion_tag.discussion_id', $ids)
            ->where('tags.is_restricted', false)
            ->toBase()
            ->get(['discussion_tag.discussion_id', 'tags.name', 'tags.slug']);

        $sums = [];
        $slugs = [];

        foreach ($rows as $row) {
            $views = $discussionSums[$row->discussion_id] ?? $discussionSums[(string) $row->discussion_id] ?? 0;
            $sums[$row->name] = ($sums[$row->name] ?? 0) + $views;
            $slugs[$row->name] = $row->slug;
        }

        arsort($sums);
        $sums = array_slice($sums, 0, 8, true);

        return array_map(function ($label, $visits) use ($slugs) {
            $row = ['label' => (string) $label, 'visits' => $visits];

            if (!empty($slugs[$label])) {
                $row['url'] = '/t/' . $slugs[$label];
            }

            return $row;
        }, array_keys($sums), array_values($sums));
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

        return array_map(function ($r) use ($titles) {
            $id = (int) $r['label'];
            $row = [
                'label' => (string) ($titles[$id] ?? "#{$r['label']}"),
                'visits' => $r['visits'],
            ];

            // Only link discussions the viewer may actually open; the "#id"
            // fallback for hidden/deleted rows stays unlinked.
            if (isset($titles[$id])) {
                $row['url'] = '/d/' . $id;
            }

            return $row;
        }, $rows);
    }
}
