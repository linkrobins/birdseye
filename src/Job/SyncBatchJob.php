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

        for ($i = 0; $i < self::MAX_DAYS; $i++) {
            $day = $this->oldestCompleteDay();

            if ($day === null) {
                return;
            }

            $events = BufferedEvent::query()
                ->whereBetween('occurred_at', ["{$day} 00:00:00", "{$day} 23:59:59"])
                ->orderBy('id')
                ->limit(self::MAX_EVENTS)
                ->get();

            if ($key !== '' && $endpoint !== '') {
                $rollups = $this->pushForProcessing($endpoint, $key, (string) $config->url(), $day, $events);
            } else {
                $rollups = $this->localRollups($day, $events);
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
     * @param \Illuminate\Support\Collection<int, BufferedEvent> $events
     * @return array<int, array{date: string, metric: string, key?: string, value: int}>
     */
    protected function pushForProcessing(string $endpoint, string $key, string $forumUrl, string $day, $events): array
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
                'truncated' => $events->count() >= self::MAX_EVENTS,
                'events' => $events->map(fn (BufferedEvent $e) => [
                    'at' => $e->occurred_at->format('H:i:s'),
                    'type' => $e->type,
                    'path' => $e->path,
                    'discussion_id' => $e->discussion_id,
                    'visitor' => $e->visitor,
                    'country' => $e->country,
                    'referrer' => $e->referrer,
                    'device' => $e->device,
                    'ip_prefix' => $e->ip_prefix,
                    'q' => $e->search_query,
                ])->all(),
            ], JSON_UNESCAPED_SLASHES),
        ]);

        $body = json_decode((string) $response->getBody(), true);

        if (!is_array($body) || !is_array($body['rollups'] ?? null)) {
            throw new \RuntimeException('Birdseye processor returned an unexpected response.');
        }

        return $body['rollups'];
    }

    /**
     * Unkeyed fallback: honest basic counts, computed locally. Everything
     * richer (sessions, bounce, countries, top lists) is what the license
     * key buys.
     *
     * @param \Illuminate\Support\Collection<int, BufferedEvent> $events
     * @return array<int, array{date: string, metric: string, key?: string, value: int}>
     */
    protected function localRollups(string $day, $events): array
    {
        $views = $events->where('type', BufferedEvent::TYPE_VIEW);

        return [
            ['date' => $day, 'metric' => 'pageviews', 'value' => $views->count()],
            ['date' => $day, 'metric' => 'visitors', 'value' => $views->pluck('visitor')->filter()->unique()->count()],
            ['date' => $day, 'metric' => 'posts', 'value' => $events->where('type', BufferedEvent::TYPE_POST)->count()],
            ['date' => $day, 'metric' => 'registrations', 'value' => $events->where('type', BufferedEvent::TYPE_REGISTER)->count()],
        ];
    }

    public function failed(?\Throwable $exception): void
    {
        // The buffer keeps the day; the next hourly sync retries it. Beyond
        // the 72h prune window the day is accepted loss (documented).
    }
}
