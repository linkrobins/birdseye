<?php

/*
 * This file is part of linkrobins/birdseye.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Birdseye\Tests\unit;

use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LinkRobins\Birdseye\Stats\CountryDownload;
use LinkRobins\Birdseye\Stats\GeoDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The monthly download of DB-IP's free country database: when it runs, what
 * it fetches, and that a failed attempt never costs the forum the database
 * it already had.
 */
class CountryDownloadTest extends TestCase
{
    private string $storage;

    private SettingsRepositoryInterface $settings;

    /** @var array<int, array<string, mixed>> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir().'/birdseye-dl-'.bin2hex(random_bytes(4));
        mkdir($this->storage);
        $this->settings = new class implements SettingsRepositoryInterface {
            /** @var array<string, mixed> */
            public array $values = [];

            public function all(): array
            {
                return $this->values;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->values[$key] = $value;
            }

            public function delete(string $keyLike): void
            {
                unset($this->values[$keyLike]);
            }
        };
    }

    protected function tearDown(): void
    {
        $dir = $this->storage.'/birdseye';

        foreach (glob($dir.'/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($dir);
        rmdir($this->storage);
    }

    /** @param array<int, Response> $responses */
    private function download(array $responses = []): CountryDownload
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->requests));

        return new CountryDownload(
            $this->settings,
            $this->paths(),
            new Config(['url' => 'https://forum.example.com']),
            new Client(['handler' => $stack])
        );
    }

    private function paths(): Paths
    {
        return new Paths(['base' => $this->storage, 'public' => $this->storage, 'storage' => $this->storage]);
    }

    private function file(): string
    {
        return CountryDownload::path($this->paths());
    }

    private function database(string $type = 'DBIP-Country-Lite'): Response
    {
        return new Response(200, [], (string) gzencode(MmdbFixture::bytes($type)));
    }

    private function requestedUrl(int $i): string
    {
        return (string) $this->requests[$i]['request']->getUri();
    }

    private function seedFile(string $contents): void
    {
        mkdir(dirname($this->file()), 0755, true);
        file_put_contents($this->file(), $contents);
    }

    /** No leftovers from the download beside the database itself. */
    private function assertNoTempFiles(): void
    {
        $this->assertSame([], array_values(array_filter(
            glob(dirname($this->file()).'/*') ?: [],
            fn ($f) => $f !== $this->file()
        )));
    }

    #[Test]
    public function it_installs_this_months_edition(): void
    {
        $edition = $this->download([$this->database()])->run(gmmktime(12, 0, 0, 10, 9, 2026));

        $this->assertSame('2026-10', $edition);
        $this->assertSame('https://download.db-ip.com/free/dbip-country-lite-2026-10.mmdb.gz', $this->requestedUrl(0));
        $this->assertSame('2026-10', $this->settings->get(CountryDownload::EDITION));
        $this->assertSame('', $this->settings->get(CountryDownload::ERROR));
        $this->assertSame(MmdbFixture::bytes('DBIP-Country-Lite'), file_get_contents($this->file()));
        $this->assertNoTempFiles();
    }

    #[Test]
    public function it_falls_back_to_last_month_until_this_one_is_published(): void
    {
        $edition = $this->download([new Response(404), $this->database()])->run(gmmktime(3, 0, 0, 1, 1, 2027));

        $this->assertSame('2026-12', $edition);
        $this->assertStringEndsWith('-2027-01.mmdb.gz', $this->requestedUrl(0));
        $this->assertStringEndsWith('-2026-12.mmdb.gz', $this->requestedUrl(1));
    }

    #[Test]
    public function a_failed_download_keeps_the_old_database_and_says_why(): void
    {
        $this->seedFile('the old database');

        try {
            $this->download([new Response(503)])->run(time());
            $this->fail('Expected the download to fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 503', $e->getMessage());
        }

        $this->assertSame('the old database', file_get_contents($this->file()));
        $this->assertStringContainsString('HTTP 503', (string) $this->settings->get(CountryDownload::ERROR));
        $this->assertNoTempFiles();
    }

    #[Test]
    public function a_host_blocking_outgoing_connections_is_reported_in_plain_words(): void
    {
        $error = new ConnectException(
            'cURL error 6: Could not resolve host: download.db-ip.com (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://download.db-ip.com/free/x',
            new Request('GET', 'https://download.db-ip.com/free/x')
        );

        try {
            $this->download([$error])->run(time());
            $this->fail('Expected the download to fail.');
        } catch (RuntimeException) {
        }

        $this->assertSame(
            'cURL error 6: Could not resolve host: download.db-ip.com for https://download.db-ip.com/free/x',
            $this->settings->get(CountryDownload::ERROR)
        );
        $this->assertNoTempFiles();
    }

    #[Test]
    public function nothing_published_for_either_month_is_a_failure(): void
    {
        $this->expectException(RuntimeException::class);

        $this->download([new Response(404), new Response(404)])->run(time());
    }

    #[Test]
    public function a_file_that_is_not_a_database_is_never_installed(): void
    {
        $this->seedFile('the old database');

        foreach ([new Response(200, [], 'not gzip at all'), new Response(200, [], (string) gzencode('not a database')), $this->database('DBIP-City-Lite')] as $response) {
            try {
                $this->download([$response])->run(time());
                $this->fail('Expected the download to be rejected.');
            } catch (RuntimeException) {
            }

            $this->assertSame('the old database', file_get_contents($this->file()));
            $this->assertNoTempFiles();
        }
    }

    #[Test]
    public function with_no_database_yet_it_is_due_straight_away_but_backs_off_after_a_failure(): void
    {
        $now = gmmktime(12, 0, 0, 10, 9, 2026);
        $download = $this->download();

        $this->assertTrue($download->due($now));

        $this->settings->set(CountryDownload::ATTEMPTED, (string) ($now - 3600));
        $this->assertFalse($download->due($now));

        $this->settings->set(CountryDownload::ATTEMPTED, (string) ($now - 7 * 3600));
        $this->assertTrue($download->due($now));
    }

    #[Test]
    public function a_new_edition_is_fetched_once_the_month_turns_within_two_days(): void
    {
        $this->seedFile('database');
        $this->settings->set(CountryDownload::EDITION, '2026-10');
        $download = $this->download();

        $this->assertFalse($download->due(gmmktime(12, 0, 0, 10, 20, 2026)), 'Current edition already here.');
        $this->assertFalse($download->due(gmmktime(23, 0, 0, 11, 1, 2026)), 'Too early: DB-IP publishes during the 1st.');
        $this->assertTrue($download->due(gmmktime(0, 0, 0, 11, 4, 2026)), 'Every forum\'s slot has passed by the 4th.');
    }

    #[Test]
    public function it_stands_aside_when_it_would_be_wasted(): void
    {
        $now = time();
        $download = $this->download();
        $this->assertTrue($download->wanted());

        $this->settings->set('linkrobins-birdseye.geoip_db_path', '/home/me/GeoLite2-Country.mmdb');
        $this->assertFalse($download->wanted(), 'The admin\'s own database wins.');
        $this->assertFalse($download->due($now));
        $this->settings->set('linkrobins-birdseye.geoip_db_path', '');

        $this->settings->set('linkrobins-birdseye.geo_ip_prefix', '');
        $this->assertFalse($download->wanted(), 'No prefix is kept, so the database is never consulted.');
        $this->settings->set('linkrobins-birdseye.geo_ip_prefix', '1');

        $this->settings->set(CountryDownload::ENABLED, '');
        $this->assertFalse($download->wanted(), 'Turned off.');
        $this->assertNull(CountryDownload::fallback($this->settings, $this->paths()));
    }

    #[Test]
    public function the_downloaded_database_is_used_only_when_no_path_is_set(): void
    {
        $this->seedFile(MmdbFixture::bytes('DBIP-Country-Lite'));
        $fallback = CountryDownload::fallback($this->settings, $this->paths());

        [$status, $reader] = GeoDatabase::open($this->settings, $fallback);
        $this->assertSame(GeoDatabase::OK, $status);
        $this->assertNotNull($reader);
        $this->assertTrue(GeoDatabase::isDbIp($reader));

        $this->assertSame(GeoDatabase::UNSET, GeoDatabase::status($this->settings, null), 'Download turned off.');

        $this->settings->set('linkrobins-birdseye.geoip_db_path', $this->storage.'/missing.mmdb');
        $this->assertSame(GeoDatabase::MISSING, GeoDatabase::status($this->settings, $fallback), 'A broken path is reported, not papered over.');
    }

    #[Test]
    public function a_maxmind_database_carries_no_dbip_credit(): void
    {
        $this->seedFile(MmdbFixture::bytes('GeoLite2-Country'));

        $reader = GeoDatabase::reader($this->settings, $this->file());

        $this->assertNotNull($reader);
        $this->assertFalse(GeoDatabase::isDbIp($reader));
    }
}
