<?php

namespace LinkRobins\Birdseye\Rollup;

use Flarum\Database\AbstractModel;

/**
 * One aggregated fact: (date, metric, key) => value. The permanent local
 * analytics history — this table IS the product's data, and it belongs to
 * the forum that runs the extension.
 *
 * Scalar metrics use key='' (visits, pageviews, bounce_sessions, sessions,
 * session_seconds, posts, registrations); list metrics use one row per key
 * (page, discussion, source, device, country, search).
 *
 * @property int $id
 * @property \Carbon\Carbon $date
 * @property string $metric
 * @property string $key
 * @property int $value
 */
class Rollup extends AbstractModel
{
    protected $table = 'birdseye_rollups';

    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
    ];

    /**
     * Idempotent write: the same (date, metric, key) replaces, never
     * duplicates — safe under job retries and re-pushed batches.
     */
    public static function put(string $date, string $metric, string $key, int $value): void
    {
        static::query()->upsert(
            [['date' => $date, 'metric' => $metric, 'key' => $key, 'value' => $value]],
            ['date', 'metric', 'key'],
            ['value']
        );
    }
}
