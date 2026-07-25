<?php

namespace LinkRobins\Birdseye\Job;

use Flarum\Foundation\Config;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use LinkRobins\Birdseye\Buffer\BufferedEvent;
use LinkRobins\Birdseye\Rollup\Rollup;

/**
 * Consumes the buffer one COMPLETE UTC day at a time. A day is pushed as a
 * single request so the stateless processor sees every event it needs for
 * uniques and sessionization — no cross-push memory required on either
 * side. Keyed: push to the processor, store the returned rollups. Unkeyed:
 * compute basic local counts (the free funnel) and never phone out.
 *
 * Idempotent by construction: rollup writes are upserts on
 * (date, metric, key), and events are only deleted after rollups land, so
 * a retry re-pushes the same day and overwrites identical values.
 */
class SyncBatchJob extends AbstractJob implements ShouldBeUnique
{
    public int $tries = 3;

    public int $timeout = 120;

    /** Days consumed per run — bounds catch-up work after downtime. */
    protected const MAX_DAYS = 3;

    /** Hard cap per day; a bigger day is truncated and noted in the log. */
    protected const MAX_EVENTS = 100000;

    public function handle(SettingsRepositoryInterface $settings, Config $config): void
    {
        $key = trim((string) $settings->get('linkrobins-birdseye.license_key'));
        $endpoint = trim((string) $settings->get('linkrobins-birdseye.endpoint'));
        $keyed = $key !== '' && $endpoint !== '';

        for ($i = 0; $i < self::MAX_DAYS; $i++) {
            $day = $this->oldestCompleteDay();

            if ($day === null) {
                return;
            }

            // Plain query-builder rows, streamed — a 100k-event day never
            // hydrates 100k Eloquent models (that could brush memory_limit
            // on shared hosting). occurred_at arrives as the raw
            // "Y-m-d H:i:s" string, no Carbon cast. The keyed path still
            // materializes one plain-array payload for the day — the
            // one-day-one-push protocol needs the whole day in a single
            // request so the stateless processor can compute uniques and
            // sessions — while the unkeyed path aggregates on the fly and
            // retains nothing.
            $rows = BufferedEvent::query()
                ->whereBetween('occurred_at', ["{$day} 00:00:00", "{$day} 23:59:59"])
                ->orderBy('id')
                ->limit(self::MAX_EVENTS)
                ->toBase()
                ->cursor();

            if ($keyed) {
                $events = [];

                foreach ($rows as $row) {
                    $events[] = [
                        'at' => substr((string) $row->occurred_at, 11, 8),
                        'type' => $row->type,
                        'path' => $row->path,
                        'discussion_id' => $row->discussion_id,
                        'visitor' => $row->visitor,
                        'country' => $row->country,
                        'referrer' => $row->referrer,
                        'device' => $row->device,
                        'ip_prefix' => $row->ip_prefix,
                        'q' => $row->search_query,
                    ];
                }

                $rollups = $this->pushForProcessing($endpoint, $key, (string) $config->url(), $day, $events);
            } else {
                $rollups = $this->localRollups($day, $rows);
            }

            foreach ($rollups as $r) {
                Rollup::put($r['date'], $r['metric'], $r['key'] ?? '', (int) $r['value']);
            }

            // Only now is the day consumed. Chunked delete, no long lock.
            do {
                $deleted = BufferedEvent::query()
                    ->whereBetween('occurred_at', ["{$day} 00:00:00", "{$day} 23:59:59"])
                    ->limit(5000)
                    ->delete();
            } while ($deleted > 0);
        }
    }

    protected function oldestCompleteDay(): ?string
    {
        $oldest = BufferedEvent::query()->min('occurred_at');

        if ($oldest === null) {
            return null;
        }

        $day = substr((string) $oldest, 0, 10);

        // Today (UTC) is still accumulating — never consume it.
        return $day < gmdate('Y-m-d') ? $day : null;
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<int, array{date: string, metric: string, key?: string, value: int}>
     */
    protected function pushForProcessing(string $endpoint, string $key, string $forumUrl, string $day, array $events): array
    {
        $client = new Client(['timeout' => 60, 'connect_timeout' => 10]);

        $response = $client->post($endpoint, [
            'headers' => [
                'Authorization' => "Bearer {$key}",
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'forum_url' => $forumUrl,
                'date' => $day,
                'truncated' => count($events) >= self::MAX_EVENTS,
                'events' => $events,
            ], JSON_UNESCAPED_SLASHES),
        ]);

        $body = json_decode((string) $response->getBody(), true);

        if (!is_array($body) || !is_array($body['rollups'] ?? null)) {
            throw new \RuntimeException('Birdseye processor returned an unexpected response.');
        }

        return $body['rollups'];
    }

    /**
     * Unkeyed fallback: honest basic counts, computed locally in one
     * streaming pass. Everything richer (sessions, bounce, countries, top
     * lists) is what the license key buys.
     *
     * @param iterable<int, object> $rows
     * @return array<int, array{date: string, metric: string, key?: string, value: int}>
     */
    protected function localRollups(string $day, iterable $rows): array
    {
        $pageviews = $posts = $registrations = 0;
        $visitors = [];

        foreach ($rows as $row) {
            $type = (string) $row->type;

            if ($type === BufferedEvent::TYPE_VIEW) {
                $pageviews++;

                if (($v = (string) ($row->visitor ?? '')) !== '') {
                    $visitors[$v] = true;
                }
            } elseif ($type === BufferedEvent::TYPE_POST) {
                $posts++;
            } elseif ($type === BufferedEvent::TYPE_REGISTER) {
                $registrations++;
            }
        }

        return [
            ['date' => $day, 'metric' => 'pageviews', 'value' => $pageviews],
            ['date' => $day, 'metric' => 'visitors', 'value' => count($visitors)],
            ['date' => $day, 'metric' => 'posts', 'value' => $posts],
            ['date' => $day, 'metric' => 'registrations', 'value' => $registrations],
        ];
    }

    public function failed(?\Throwable $exception): void
    {
        // The buffer keeps the day; the next hourly sync retries it. Beyond
        // the 72h prune window the day is accepted loss (documented).
    }
}
