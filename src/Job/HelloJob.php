<?php

namespace LinkRobins\Birdseye\Job;

use Flarum\Foundation\Config;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;

/**
 * First-contact check-in, dispatched when an admin saves a license key
 * (first paste or a rotation). Pushes an EMPTY event set for yesterday so
 * the processor can verify the key and bind it to this forum right away —
 * without it, binding waits for the first complete-day sync, which can be
 * a full day after install and reads as "not working" on the customer
 * dashboard. Carries no analytics data, consumes no buffered events, and
 * stores nothing locally (the response is ignored); the normal sync path
 * stays the single source of truth. Best-effort like the rest of
 * Birdseye: on any failure the hourly sync simply makes first contact
 * instead.
 */
class HelloJob extends AbstractJob
{
    public int $tries = 1;

    public int $timeout = 30;

    public function handle(SettingsRepositoryInterface $settings, Config $config): void
    {
        $key = trim((string) $settings->get('linkrobins-birdseye.license_key'));
        $endpoint = trim((string) $settings->get('linkrobins-birdseye.endpoint'));

        if ($key === '' || $endpoint === '') {
            return;
        }

        try {
            // Short timeouts: on the sync queue driver this runs inside the
            // admin's settings-save request, so a dead endpoint must not hang
            // the admin panel — 4s total is the worst case an admin waits.
            // Synchronous-by-design: dispatchAfterResponse is a no-op in
            // Flarum (core's Application::terminate() never flushes it) and
            // skipping on the sync driver would kill instant bind for the
            // majority default install.
            (new Client(['timeout' => 4, 'connect_timeout' => 2]))->post($endpoint, [
                'headers' => [
                    'Authorization' => "Bearer {$key}",
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'forum_url' => (string) $config->url(),
                    'date' => gmdate('Y-m-d', strtotime('-1 day')),
                    'events' => [],
                ], JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable) {
            // The hourly sync will make first contact instead.
        }
    }
}
