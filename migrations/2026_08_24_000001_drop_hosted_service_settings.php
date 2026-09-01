<?php

use Illuminate\Database\Schema\Builder;

// Birdseye no longer talks to a hosted service: rollups are computed locally
// by LocalProcessor. The settings that pointed at the service (and the key
// that authenticated to it) are dead weight; remove them so a stale endpoint
// can never be dialled again. Down is a no-op — there is nothing meaningful
// to restore.
return [
    'up' => function (Builder $schema) {
        $schema->getConnection()->table('settings')->whereIn('key', [
            'linkrobins-birdseye.license_key',
            'linkrobins-birdseye.endpoint',
            'linkrobins-birdseye.status_endpoint',
        ])->delete();
    },
    'down' => function (Builder $schema) {
        // Intentionally nothing.
    },
];
