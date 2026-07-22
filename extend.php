<?php

use Flarum\Extend;
use Flarum\Post\Event\Posted;
use Flarum\User\Event\Registered;
use LinkRobins\Birdseye\Capture\CaptureMiddleware;
use LinkRobins\Birdseye\Console\SyncCommand;
use LinkRobins\Birdseye\Listener\RecordPosted;
use LinkRobins\Birdseye\Listener\RecordRegistered;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),

    new Extend\Locales(__DIR__ . '/locale'),

    // Capture runs on both stacks: 'forum' sees full page loads, 'api' sees
    // the SPA's JSON:API navigation (discussion show, search). Capture is
    // best-effort by contract — it must never break a request.
    (new Extend\Middleware('forum'))->add(CaptureMiddleware::class),
    (new Extend\Middleware('api'))->add(CaptureMiddleware::class),

    (new Extend\Event())
        ->listen(Posted::class, RecordPosted::class)
        ->listen(Registered::class, RecordRegistered::class),

    (new Extend\Console())
        ->command(SyncCommand::class)
        // onOneServer + withoutOverlapping need a shared cache on multi-node
        // installs; the command is idempotent either way.
        ->schedule(SyncCommand::class, function ($event) {
            $event->hourly()->onOneServer()->withoutOverlapping();
        }),

    (new Extend\Settings())
        // Kill-switch: collection can be paused without disabling the
        // extension (existing rollups keep rendering).
        ->default('linkrobins-birdseye.collect', true)
        // Where batches are pushed for processing. Only meaningful with a
        // license key; without one the sync command rolls up basic counts
        // locally and never phones out.
        ->default('linkrobins-birdseye.endpoint', 'https://linkrobins.com/api/birdseye/process')
        ->default('linkrobins-birdseye.license_key', '')
        // Store an anonymized IP prefix (/24 v4, /48 v6) in the 72h buffer so
        // the processor can resolve visitor country when the forum is not
        // behind a proxy that supplies a country header. Prefix is discarded
        // with the buffer row; full IPs are never written anywhere.
        ->default('linkrobins-birdseye.geo_ip_prefix', true),
];
