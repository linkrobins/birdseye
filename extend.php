<?php

use Flarum\Api\Resource\ForumResource;
use Flarum\Extend;
use Flarum\Post\Event\Posted;
use Flarum\User\Event\Registered;
use LinkRobins\Birdseye\Api\ForumFields;
use LinkRobins\Birdseye\Api\StatsHandler;
use LinkRobins\Birdseye\Api\WorldMapHandler;
use LinkRobins\Birdseye\Capture\ApiCaptureMiddleware;
use LinkRobins\Birdseye\Capture\ForumCaptureMiddleware;
use LinkRobins\Birdseye\Console\SyncCommand;
use LinkRobins\Birdseye\Listener\RecordPosted;
use LinkRobins\Birdseye\Listener\RecordRegistered;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    // Forum side: an Analytics entry in the session menu opening the same
    // dashboard in a modal, for groups granted the viewStats permission.
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less'),

    // Tells the forum frontend whether to offer that entry (fail-closed).
    (new Extend\ApiResource(ForumResource::class))
        ->fields(ForumFields::class),

    // Dashboard data + the bundled world-map asset. Both gated on the
    // viewStats permission (admins always pass).
    (new Extend\Routes('api'))
        ->get('/birdseye/stats', 'birdseye.stats', StatsHandler::class)
        ->get('/birdseye/world-map', 'birdseye.world-map', WorldMapHandler::class),

    new Extend\Locales(__DIR__ . '/locale'),

    // Capture: 'forum' sees full page loads, 'api' sees the SPA's JSON:API
    // navigation (discussion show, search). Distinct classes per stack —
    // internal ApiClient subrequests inherit the parent's headers, so the
    // stack + path prefix are the only reliable discriminators (see
    // CaptureMiddleware). Capture is best-effort by contract — it must
    // never break a request.
    (new Extend\Middleware('forum'))->add(ForumCaptureMiddleware::class),
    (new Extend\Middleware('api'))->add(ApiCaptureMiddleware::class),

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
        // Where batches are pushed for processing. Deliberately NOT exposed
        // in the admin UI — customers never change it; it stays a hidden
        // setting so dev/staging can override it manually. Only meaningful
        // with a license key; without one the sync command rolls up basic
        // counts locally and never phones out.
        ->default('linkrobins-birdseye.endpoint', 'https://linkrobins.com/api/birdseye/process')
        ->default('linkrobins-birdseye.license_key', '')
        // Store an anonymized IP prefix (/24 v4, /48 v6) in the 72h buffer so
        // the processor can resolve visitor country when the forum is not
        // behind a proxy that supplies a country header. Prefix is discarded
        // with the buffer row; full IPs are never written anywhere.
        ->default('linkrobins-birdseye.geo_ip_prefix', true),
];
