<?php

namespace LinkRobins\Birdseye\Stats;

use Flarum\Discussion\Discussion;
use LinkRobins\Birdseye\Rollup\Rollup;

/**
 * Assembles the dashboard payload from local rollup rows — the same block
 * shape the old Tinybird siteBlock produced, so the dashboard component
 * renders one well-known structure. Everything here reads the forum's OWN
 * database; nothing leaves the server.
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

    /**
     * @return array{ranges: array<string, mixed>}
     */
    public function build(): array
    {
        return [
            'ranges' => [
                '7d' => $this->range(7),
                '30d' => $this->range(30),
            ],
        ];
    }

    protected function range(int $days): array
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
        foreach (self::LISTS as $metric => [$key, $cap]) {
            $sums = [];

            foreach ($rows as $row) {
                if ($row->metric === $metric && $row->key !== '') {
                    $sums[$row->key] = ($sums[$row->key] ?? 0) + $row->value;
                }
            }

            arsort($sums);
            $sums = array_slice($sums, 0, $cap, true);

            $block[$key] = array_map(
                fn ($label, $visits) => ['label' => (string) $label, 'visits' => $visits],
                array_keys($sums),
                array_values($sums)
            );
        }

        $block['discussions'] = $this->titleDiscussions($block['discussions']);

        return $block;
    }

    /**
     * Discussion rollup keys are ids; resolve titles locally at render time
     * (the processor never needs to know titles). Deleted discussions keep
     * their id as the label.
     *
     * @param array<int, array{label: string, visits: int}> $rows
     * @return array<int, array{label: string, visits: int}>
     */
    protected function titleDiscussions(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $titles = Discussion::query()
            ->whereIn('id', array_map(fn ($r) => (int) $r['label'], $rows))
            ->pluck('title', 'id');

        return array_map(fn ($r) => [
            'label' => (string) ($titles[(int) $r['label']] ?? "#{$r['label']}"),
            'visits' => $r['visits'],
        ], $rows);
    }
}
