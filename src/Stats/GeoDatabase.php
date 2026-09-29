<?php

namespace LinkRobins\Birdseye\Stats;

use Flarum\Settings\SettingsRepositoryInterface;
use MaxMind\Db\Reader;

/**
 * The single place that decides whether a local MaxMind country database is
 * usable.
 *
 * Both the sync job, which performs the lookups, and the dashboard, which
 * tells the admin when country lookup cannot work at all, resolve it through
 * here. Keeping one implementation is the point: a notice that says country
 * lookup is fine while the job quietly disagrees would be worse than no
 * notice.
 */
final class GeoDatabase
{
    /**
     * A reader for the configured database, or null when none is configured,
     * the path is not a file, or the file is not a readable MaxMind database.
     */
    public static function reader(SettingsRepositoryInterface $settings): ?Reader
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

    public static function usable(SettingsRepositoryInterface $settings): bool
    {
        return self::reader($settings) !== null;
    }
}
