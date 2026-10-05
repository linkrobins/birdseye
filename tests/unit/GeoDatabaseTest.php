<?php

/*
 * This file is part of linkrobins/birdseye.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Birdseye\Tests\unit;

use Flarum\Settings\SettingsRepositoryInterface;
use LinkRobins\Birdseye\Stats\GeoDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each way the country database path can be wrong gets its own status, so the
 * dashboard can say which one it is instead of "not configured".
 */
class GeoDatabaseTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/birdseye-geo-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @chmod($file, 0644);
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function statusOf(string $path): string
    {
        $settings = $this->createStub(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturn($path);

        return GeoDatabase::status($settings);
    }

    private function file(string $name, string $contents): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    #[Test]
    public function an_empty_setting_is_unset(): void
    {
        $this->assertSame(GeoDatabase::UNSET, $this->statusOf(''));
        $this->assertSame(GeoDatabase::UNSET, $this->statusOf('   '));
    }

    #[Test]
    public function relative_paths_and_web_addresses_are_not_full_paths(): void
    {
        $this->assertSame(GeoDatabase::RELATIVE, $this->statusOf('geoip/GeoLite2-Country.mmdb'));
        $this->assertSame(GeoDatabase::RELATIVE, $this->statusOf('https://example.com/GeoLite2-Country.mmdb'));
    }

    #[Test]
    public function a_folder_is_not_the_file(): void
    {
        $this->assertSame(GeoDatabase::FOLDER, $this->statusOf($this->dir));
    }

    #[Test]
    public function nothing_at_the_path_is_missing(): void
    {
        $this->assertSame(GeoDatabase::MISSING, $this->statusOf($this->dir.'/GeoLite2-Country.mmdb'));
    }

    #[Test]
    public function the_archive_as_downloaded_is_compressed(): void
    {
        $this->assertSame(GeoDatabase::COMPRESSED, $this->statusOf($this->file('GeoLite2-Country.tar.gz', gzencode('not extracted'))));
        $this->assertSame(GeoDatabase::COMPRESSED, $this->statusOf($this->file('GeoLite2-Country.zip', "PK\x03\x04rest of a zip")));
    }

    #[Test]
    public function any_other_file_is_not_a_maxmind_database(): void
    {
        $this->assertSame(GeoDatabase::INVALID, $this->statusOf($this->file('GeoLite2-Country.mmdb', 'just some text')));
    }

    #[Test]
    public function a_file_php_cannot_read_is_unreadable(): void
    {
        $path = $this->file('GeoLite2-Country.mmdb', 'contents');
        chmod($path, 0000);

        if (is_readable($path)) {
            $this->markTestSkipped('Running as a user that can read any file (root).');
        }

        $this->assertSame(GeoDatabase::UNREADABLE, $this->statusOf($path));
    }

    #[Test]
    public function an_unusable_database_gives_no_reader(): void
    {
        $settings = $this->createStub(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturn($this->file('GeoLite2-Country.mmdb', 'just some text'));

        $this->assertNull(GeoDatabase::reader($settings));
        $this->assertFalse(GeoDatabase::usable($settings));
    }
}
