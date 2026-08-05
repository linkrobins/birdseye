<?php

/*
 * This file is part of linkrobins/birdseye.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Birdseye\Tests\unit;

use Flarum\Settings\SettingsRepositoryInterface;
use LinkRobins\Birdseye\Capture\VisitorHash;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class VisitorHashTest extends TestCase
{
    private function hash(): VisitorHash
    {
        // A fixed salt so the daily hash is deterministic within the test run.
        $settings = $this->createStub(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturn('fixed-test-salt');

        return new VisitorHash($settings);
    }

    /** @test */
    #[Test]
    public function the_same_visitor_hashes_the_same_within_a_day(): void
    {
        $h = $this->hash();

        $this->assertSame($h->make('203.0.113.7', 'UA/1'), $h->make('203.0.113.7', 'UA/1'));
    }

    /** @test */
    #[Test]
    public function a_different_ip_or_user_agent_hashes_differently(): void
    {
        $h = $this->hash();
        $base = $h->make('203.0.113.7', 'UA/1');

        $this->assertNotSame($base, $h->make('203.0.113.8', 'UA/1'));
        $this->assertNotSame($base, $h->make('203.0.113.7', 'UA/2'));
    }

    /** @test */
    #[Test]
    public function the_hash_is_a_short_hex_digest_that_cannot_be_reversed_to_the_ip(): void
    {
        $out = $this->hash()->make('203.0.113.7', 'UA/1');

        $this->assertSame(16, strlen($out));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $out);
        $this->assertStringNotContainsString('203.0.113', $out);
    }

    /** @test */
    #[Test]
    public function ipv4_prefix_zeroes_the_last_octet(): void
    {
        $this->assertSame('203.0.113.0', $this->hash()->ipPrefix('203.0.113.7'));
    }

    /** @test */
    #[Test]
    public function ipv6_prefix_keeps_only_the_first_48_bits(): void
    {
        $prefix = $this->hash()->ipPrefix('2001:db8:abcd:1234::1');

        // /48 keeps 2001:db8:abcd and zeroes the rest.
        $this->assertSame('2001:db8:abcd::', $prefix);
    }

    /** @test */
    #[Test]
    public function a_non_ip_yields_no_prefix(): void
    {
        $this->assertNull($this->hash()->ipPrefix('not-an-ip'));
        $this->assertNull($this->hash()->ipPrefix(''));
    }
}
