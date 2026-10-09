<?php

namespace LinkRobins\Birdseye\Stats;

use Flarum\Settings\SettingsRepositoryInterface;
use MaxMind\Db\Reader;

/**
 * The single place that decides whether a local MaxMind country database is
 * usable, and if not, why.
 *
 * Both the sync job, which performs the lookups, and the dashboard, which
 * tells the admin when country lookup cannot work, resolve it through here.
 * Keeping one implementation is the point: a notice that says country lookup
 * is fine while the job quietly disagrees would be worse than no notice.
 *
 * The reasons exist because "not configured" was all the dashboard could say,
 * and an admin who had set a path that did not work was sent looking for a
 * setting they had already filled in (d/39605/90).
 *
 * A path the admin sets always wins. Only when none is set does the database
 * Birdseye downloads for itself ({@see CountryDownload}) come in, passed as
 * $fallback; it is null when that download is turned off.
 */
final class GeoDatabase
{
    /** A readable MaxMind database. */
    public const OK = 'ok';
    /** No path set. */
    public const UNSET = 'unset';
    /** Not a full server path: relative, or a web address. */
    public const RELATIVE = 'relative';
    /** The path names a folder, not the file inside it. */
    public const FOLDER = 'folder';
    /** Nothing at the path, or PHP is not allowed to look there. */
    public const MISSING = 'missing';
    /** The file exists but PHP cannot read it. */
    public const UNREADABLE = 'unreadable';
    /** The download as it arrives: a .tar.gz or .zip, not the .mmdb inside. */
    public const COMPRESSED = 'compressed';
    /** A readable file that is not a MaxMind database. */
    public const INVALID = 'invalid';

    /**
     * A reader for the configured database, or null when it is not usable.
     */
    public static function reader(SettingsRepositoryInterface $settings, ?string $fallback = null): ?Reader
    {
        return self::open($settings, $fallback)[1];
    }

    public static function usable(SettingsRepositoryInterface $settings, ?string $fallback = null): bool
    {
        return self::reader($settings, $fallback) !== null;
    }

    /**
     * One of the constants above.
     */
    public static function status(SettingsRepositoryInterface $settings, ?string $fallback = null): string
    {
        return self::open($settings, $fallback)[0];
    }

    /**
     * Whether a database is DB-IP's, whose free licence (CC BY 4.0) asks for
     * a credit wherever its results are shown.
     */
    public static function isDbIp(Reader $reader): bool
    {
        return str_starts_with((string) $reader->metadata()->databaseType, 'DBIP');
    }

    /**
     * @return array{0: string, 1: ?Reader}
     */
    public static function open(SettingsRepositoryInterface $settings, ?string $fallback = null): array
    {
        $path = trim((string) $settings->get('linkrobins-birdseye.geoip_db_path'));

        if ($path === '') {
            $reader = $fallback === null ? null : self::downloaded($fallback);

            return [$reader === null ? self::UNSET : self::OK, $reader];
        }

        // A Unix path starts at /, a Windows one at a drive letter. Anything
        // else would resolve against whatever directory PHP happens to be
        // running in, which is not something an admin can know.
        if (! str_starts_with($path, '/') && ! preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            return [self::RELATIVE, null];
        }

        // Silenced: under open_basedir these warn as well as returning false,
        // and the answer is the same either way, so it is reported as missing.
        if (@is_dir($path)) {
            return [self::FOLDER, null];
        }

        if (! @is_file($path)) {
            return [self::MISSING, null];
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return [self::UNREADABLE, null];
        }

        $magic = (string) fread($handle, 4);
        fclose($handle);

        // gzip (MaxMind's .tar.gz) or zip.
        if (str_starts_with($magic, "\x1f\x8b") || str_starts_with($magic, "PK\x03\x04")) {
            return [self::COMPRESSED, null];
        }

        try {
            return [self::OK, new Reader($path)];
        } catch (\Throwable) {
            // A malformed database must not stop the day's stats; it just
            // means "no country fallback".
            return [self::INVALID, null];
        }
    }

    /**
     * The downloaded database, which only ever lands after it has opened
     * cleanly, so anything wrong with it reads as "none yet" rather than as
     * a setting the admin has to fix.
     */
    private static function downloaded(string $path): ?Reader
    {
        if (! @is_file($path)) {
            return null;
        }

        try {
            return new Reader($path);
        } catch (\Throwable) {
            return null;
        }
    }
}
