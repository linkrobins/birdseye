<?php

/*
 * This file is part of linkrobins/birdseye.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Birdseye\Tests\unit;

/**
 * Builds the smallest valid MaxMind database: one search-tree node whose
 * records both mean "not found", and metadata naming the database type.
 * Enough for the reader to open it and report its type, without shipping
 * a real (and licensed) 8 MB database in the repository.
 */
final class MmdbFixture
{
    public static function bytes(string $databaseType): string
    {
        // One node, two 24-bit records, each 1 (= node_count = no data).
        $tree = "\x00\x00\x01\x00\x00\x01";

        $metadata = self::map([
            'binary_format_major_version' => self::uint16(2),
            'binary_format_minor_version' => self::uint16(0),
            'build_epoch' => self::uint64(1759276800),
            'database_type' => self::utf8($databaseType),
            'description' => self::map(['en' => self::utf8('Birdseye test fixture')]),
            'ip_version' => self::uint16(4),
            'languages' => self::array([self::utf8('en')]),
            'node_count' => self::uint32(1),
            'record_size' => self::uint16(24),
        ]);

        return $tree.str_repeat("\x00", 16)."\xAB\xCD\xEFMaxMind.com".$metadata;
    }

    private static function control(int $type, int $size): string
    {
        return $type <= 7
            ? chr(($type << 5) | $size)
            : chr($size).chr($type - 7);
    }

    private static function utf8(string $value): string
    {
        return self::control(2, strlen($value)).$value;
    }

    private static function uint16(int $value): string
    {
        $bytes = ltrim(pack('n', $value), "\x00");

        return self::control(5, strlen($bytes)).$bytes;
    }

    private static function uint32(int $value): string
    {
        $bytes = ltrim(pack('N', $value), "\x00");

        return self::control(6, strlen($bytes)).$bytes;
    }

    private static function uint64(int $value): string
    {
        $bytes = ltrim(pack('J', $value), "\x00");

        return self::control(9, strlen($bytes)).$bytes;
    }

    /** @param array<int, string> $items */
    private static function array(array $items): string
    {
        return self::control(11, count($items)).implode('', $items);
    }

    /** @param array<string, string> $pairs */
    private static function map(array $pairs): string
    {
        $out = self::control(7, count($pairs));

        foreach ($pairs as $key => $value) {
            $out .= self::utf8($key).$value;
        }

        return $out;
    }
}
