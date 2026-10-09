<?php

namespace LinkRobins\Birdseye\Stats;

use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use MaxMind\Db\Reader;
use RuntimeException;

/**
 * Keeps a free country database on this server without the admin having to
 * fetch one: DB-IP's "IP to Country Lite", a MaxMind-format file published
 * on the 1st of every month under CC BY 4.0, with no account or key.
 *
 * Before this, visitor countries needed either a proxy header or a GeoLite2
 * file the admin downloaded from MaxMind under their own account and placed
 * by hand, and most forums ended up with neither (d/39605/78-96). The
 * download is the only thing that leaves the server: no visitor data goes
 * with it, and every lookup still happens locally.
 *
 * It stands aside whenever it would be wasted: when the admin has set their
 * own database path, which always wins, and when the anonymized IP prefix
 * the database needs is not being kept.
 *
 * A new file only replaces the old one after it has been unpacked and opened
 * cleanly, so a failed or partial download never leaves the forum worse off
 * than before; the reason is kept for the dashboard to show.
 */
class CountryDownload
{
    public const ENABLED = 'linkrobins-birdseye.geoip_auto_download';

    /** The monthly edition on disk, as YYYY-MM. */
    public const EDITION = 'linkrobins-birdseye.geoip_download_edition';

    /** Why the last attempt failed; empty once one succeeds. */
    public const ERROR = 'linkrobins-birdseye.geoip_download_error';

    /** Unix time of the last attempt, successful or not. */
    public const ATTEMPTED = 'linkrobins-birdseye.geoip_download_attempted';

    protected const URL = 'https://download.db-ip.com/free/dbip-country-lite-%s.mmdb.gz';

    protected const FILE = 'birdseye/dbip-country-lite.mmdb';

    /** Wait this long after a failed attempt before trying again. */
    protected const RETRY_AFTER = 6 * 3600;

    /**
     * The unpacked database is about 8 MB; anything far past that is not
     * the file we asked for, and must not be allowed to fill the disk.
     */
    protected const MAX_BYTES = 64 * 1024 * 1024;

    protected ClientInterface $http;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Paths $paths,
        protected Config $config,
        ?ClientInterface $http = null
    ) {
        $this->http = $http ?? new Client();
    }

    public static function path(Paths $paths): string
    {
        return $paths->storage.'/'.self::FILE;
    }

    /**
     * The downloaded database's path when the download is turned on, for
     * {@see GeoDatabase}; null when it is off.
     */
    public static function fallback(SettingsRepositoryInterface $settings, Paths $paths): ?string
    {
        return (bool) $settings->get(self::ENABLED, true) ? self::path($paths) : null;
    }

    /**
     * Whether the download has anything to do, given the settings: it is
     * turned on, no database path is set, and the IP prefix is being kept.
     */
    public function wanted(): bool
    {
        return (bool) $this->settings->get(self::ENABLED, true)
            && trim((string) $this->settings->get('linkrobins-birdseye.geoip_db_path')) === ''
            && (bool) $this->settings->get('linkrobins-birdseye.geo_ip_prefix', true);
    }

    /**
     * Whether to download now. Checked hourly by the scheduler, and cheap:
     * it only reads settings and looks for the file.
     *
     * With no file yet, now. Otherwise once the month has turned, from this
     * forum's own slot in the two days after the 1st, so that forums do not
     * all fetch at the same moment and DB-IP has certainly published. After
     * a failure it waits RETRY_AFTER before the next try.
     */
    public function due(int $now): bool
    {
        if (! $this->wanted()) {
            return false;
        }

        $sinceAttempt = $now - (int) $this->settings->get(self::ATTEMPTED, 0);

        if (! is_file(self::path($this->paths))) {
            return $sinceAttempt >= self::RETRY_AFTER;
        }

        if ($this->settings->get(self::EDITION) === gmdate('Y-m', $now)) {
            return false;
        }

        $slot = gmmktime(0, 0, 0, (int) gmdate('n', $now), 2, (int) gmdate('Y', $now)) + $this->offset();

        return $now >= $slot && $sinceAttempt >= self::RETRY_AFTER;
    }

    /**
     * Download this month's edition, or last month's if this month's is not
     * out yet. Returns the edition now on disk.
     *
     * @throws RuntimeException when no edition could be installed; the
     *                          reason is also stored for the dashboard
     */
    public function run(int $now): string
    {
        $this->settings->set(self::ATTEMPTED, (string) $now);

        $editions = [
            gmdate('Y-m', $now),
            gmdate('Y-m', gmmktime(0, 0, 0, (int) gmdate('n', $now) - 1, 1, (int) gmdate('Y', $now))),
        ];

        try {
            foreach ($editions as $edition) {
                if ($this->install($edition)) {
                    $this->settings->set(self::EDITION, $edition);
                    $this->settings->set(self::ERROR, '');

                    return $edition;
                }
            }

            throw new RuntimeException('DB-IP has no country database for '.implode(' or ', $editions).'.');
        } catch (\Throwable $e) {
            // cURL's messages end in a pointer to its docs, which helps
            // nobody reading the dashboard.
            $message = (string) preg_replace('# \(see https?://curl\.[^)]*\)#', '', $e->getMessage());
            $this->settings->set(self::ERROR, mb_substr($message, 0, 500));

            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Fetch, unpack, check and swap in one edition. False when DB-IP has no
     * file for it (not published yet, or long gone).
     */
    protected function install(string $edition): bool
    {
        $final = self::path($this->paths);
        $dir = dirname($final);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create the folder {$dir}. Check that Flarum's storage folder is writable.");
        }

        $gz = $dir.'/download.mmdb.gz';
        $tmp = $dir.'/download.mmdb';

        try {
            $response = $this->http->request('GET', sprintf(self::URL, $edition), [
                'sink' => $gz,
                'http_errors' => false,
                'connect_timeout' => 15,
                'timeout' => 120,
                'headers' => ['User-Agent' => 'Birdseye for Flarum'],
            ]);

            $code = $response->getStatusCode();

            if ($code === 404) {
                return false;
            }

            if ($code !== 200) {
                throw new RuntimeException("The download from DB-IP failed with HTTP {$code}.");
            }

            $this->gunzip($gz, $tmp);

            try {
                $reader = new Reader($tmp);
                $type = (string) $reader->metadata()->databaseType;
                $reader->close();
            } catch (\Throwable) {
                throw new RuntimeException('The downloaded file is not a usable country database.');
            }

            if (! str_contains($type, 'Country')) {
                throw new RuntimeException("The downloaded file is a {$type} database, not a country one.");
            }

            // Same folder, so this replaces the old file in one step: a
            // lookup running meanwhile sees the old database or the new one.
            if (! @rename($tmp, $final)) {
                throw new RuntimeException("Could not save the country database to {$final}.");
            }

            return true;
        } finally {
            @unlink($gz);
            @unlink($tmp);
        }
    }

    protected function gunzip(string $from, string $to): void
    {
        $in = @gzopen($from, 'rb');
        $out = @fopen($to, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException('Could not unpack the downloaded country database.');
        }

        $written = 0;

        try {
            while (! gzeof($in)) {
                $chunk = gzread($in, 1 << 16);

                if ($chunk === false) {
                    throw new RuntimeException('The downloaded country database is damaged.');
                }

                $written += strlen($chunk);

                if ($written > self::MAX_BYTES) {
                    throw new RuntimeException('The downloaded country database is far larger than expected.');
                }

                fwrite($out, $chunk);
            }
        } finally {
            gzclose($in);
            fclose($out);
        }
    }

    /**
     * Seconds into the two-day window when this forum fetches. Taken from
     * the forum's address, so it stays the same month after month.
     */
    protected function offset(): int
    {
        return crc32((string) $this->config->url()) % (48 * 3600);
    }
}
