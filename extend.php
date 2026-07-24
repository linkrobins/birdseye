<?php

use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Serializer\ForumSerializer;
use Flarum\Extend;
use Flarum\Post\Event\Posted;
use Flarum\Settings\Event\Saved;
use Flarum\User\Event\Registered;
use LinkRobins\Birdseye\Api\ForumFields;
use LinkRobins\Birdseye\Api\StatsHandler;
use LinkRobins\Birdseye\Api\WorldMapHandler;
use LinkRobins\Birdseye\Capture\ApiCaptureMiddleware;
use LinkRobins\Birdseye\Capture\ForumCaptureMiddleware;
use LinkRobins\Birdseye\Console\DigestCommand;
use LinkRobins\Birdseye\Console\SyncCommand;
use LinkRobins\Birdseye\Listener\RecordPosted;
use LinkRobins\Birdseye\Listener\RecordRegistered;
use LinkRobins\Birdseye\Listener\SendHelloOnKeyChange;
use LinkRobins\Birdseye\Permissions;

// The forum field telling the frontend whether to offer the Analytics entry
// (fail-closed). On 2.0 it is a resource field; on 1.8, where API resources
// don't exist, the same attribute rides the legacy forum serializer. The
// ternary evaluates only the branch for the running major, so Extend\ApiSerializer
// (removed in 2.0) and Extend\ApiResource (absent in 1.8) are each referenced
// only where they exist.
$forumField = class_exists(ForumResource::class)
    ? (new Extend\ApiResource(ForumResource::class))->fields(ForumFields::class)
    : (new Extend\ApiSerializer(ForumSerializer::class))->attributes(function ($serializer, $model, array $attributes): array {
        try {
            $attributes['birdseyeCanViewStats'] = $serializer->getActor()->hasPermission(Permissions::VIEW_STATS);
        } catch (\Throwable) {
            $attributes['birdseyeCanViewStats'] = false;
        }

        return $attributes;
    });

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
    // A 2.0 resource field or a 1.8 serializer attribute; see $forumField above.
    $forumField,

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
        ->listen(Registered::class, RecordRegistered::class)
        // Saving a license key fires an immediate first-contact check-in so
        // the key binds (and shows as connected on the customer dashboard)
        // within seconds instead of after the first complete-day sync.
        ->listen(Saved::class, SendHelloOnKeyChange::class),

    (new Extend\Console())
        ->command(SyncCommand::class)
        // onOneServer + withoutOverlapping need a shared cache on multi-node
        // installs; the command is idempotent either way.
        ->schedule(SyncCommand::class, function ($event) {
            $event->hourly()->onOneServer()->withoutOverlapping();
        })
        ->command(DigestCommand::class)
        // Monday morning UTC; the command's own sent-marker makes a second
        // firing (or a multi-node race) a no-op.
        ->schedule(DigestCommand::class, function ($event) {
            $event->weeklyOn(1, '7:30')->onOneServer()->withoutOverlapping();
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
        // Monday email to admins summarizing last week's rollups. Local
        // only; skips silently when the week has no data.
        ->default('linkrobins-birdseye.weekly_digest', true)
        // Store an anonymized IP prefix (/24 v4, /48 v6) in the 72h buffer so
        // the processor can resolve visitor country when the forum is not
        // behind a proxy that supplies a country header. Prefix is discarded
        // with the buffer row; full IPs are never written anywhere.
        ->default('linkrobins-birdseye.geo_ip_prefix', true)
        // Which trusted-proxy header carries the visitor's country code.
        // Defaults to Cloudflare's. The header is trusted as-is (any client
        // NOT behind such a proxy can forge it), so operators without one
        // should blank this and rely on geo_ip_prefix instead.
        ->default('linkrobins-birdseye.country_header', 'CF-IPCountry'),
];
