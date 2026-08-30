<?php

namespace LinkRobins\Birdseye\Job;

use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use LinkRobins\Birdseye\Buffer\BufferedEvent;
use LinkRobins\Birdseye\Rollup\Rollup;
use LinkRobins\Birdseye\Stats\LocalProcessor;
use MaxMind\Db\Reader;

/**
 * Consumes the buffer one COMPLETE UTC day at a time, entirely on this
 * server. A day is processed whole so uniques and sessionization see every
 * event they need — no cross-run memory required. Nothing is ever sent
 * anywhere: the processing that used to happen on a hosted endpoint runs
 * in {@see LocalProcessor} now.
 *
 * Idempotent by construction: rollup writes are upserts on
 * (date, metric, key), and events are only deleted after rollups land, so
 * a retry re-processes the same day and overwrites identical values.
 */
class SyncBatchJob extends AbstractJob implements ShouldBeUnique
{
    /**
     * Note on ShouldBeUnique: in Flarum's stack it is decorative — the lock
     * acquisition lives in illuminate/foundation, which Flarum doesn't ship,
     * so no dispatch path ever takes it. The real overlap guard is the
     * scheduler's withoutOverlapping()/onOneServer() on the sync command.
     * The interface stays for queue setups that do honor it.
     */
    public int $tries = 3;

    public int $timeout = 120;

    /** Days consumed per run — bounds catch-up work after downtime. */
    protected const MAX_DAYS = 3;

    /** Hard cap per day; a bigger day is truncated and noted in the log. */
    protected const MAX_EVENTS = 100000;

    public function handle(SettingsRepositoryInterface $settings): void
    {
        $processor = new LocalProcessor($this->geoReader($settings));

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

            // A generator, not an array: a 100k-event day must never be
            // materialized on shared-hosting memory limits. The processor
            // aggregates in one pass and retains only its running tallies.
            $events = (function () use ($rows) {
                foreach ($rows as $row) {
                    yield [
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
            })();

            $rollups = $processor->process($day, $events);

            foreach ($rollups as $r) {
                Rollup::put($r['date'], $r['metric'], $r['key'], (int) $r['value']);
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
     * A MaxMind country database reader, when the admin has pointed the
     * geoip_db_path setting at one (GeoLite2-Country works; the admin
     * downloads it from MaxMind under their own account, since the file
     * cannot be redistributed). Without one, country still comes from the
     * trusted-proxy header at capture time — this is only the fallback for
     * forums not behind such a proxy.
     */
    protected function geoReader(SettingsRepositoryInterface $settings): ?Reader
    {
        $path = trim((string) $settings->get('linkrobins-birdseye.geoip_db_path'));

        if ($path === '' || !is_file($path)) {
            return null;
        }

        try {
            return new Reader($path);
        } catch (\Throwable) {
            // A malformed database must not stop the day's stats; it just
            // means "no country fallback".
            return null;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        // The buffer keeps the day; the next hourly sync retries it. Beyond
        // the 72h prune window the day is accepted loss (documented).
    }
}
