<?php

namespace LinkRobins\Birdseye\Capture;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Cookieless visitor identity: sha256(secret . date . ip . ua), truncated.
 * The salt rotates daily, so a visitor is only linkable within one calendar
 * day and the hash can never be joined across days or reversed to an IP —
 * the same scheme log-based analytics tools use.
 */
class VisitorHash
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function make(string $ip, string $userAgent): string
    {
        return substr(hash('sha256', $this->secret() . gmdate('Y-m-d') . $ip . $userAgent), 0, 16);
    }

    /**
     * Anonymized prefix for transient country lookup: v4 truncated to /24,
     * v6 to /48. Never the full address.
     */
    public function ipPrefix(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);

            return "{$parts[0]}.{$parts[1]}.{$parts[2]}.0";
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);

            if ($bin === false) {
                return null;
            }

            // Keep the first 48 bits, zero the rest.
            $masked = substr($bin, 0, 6) . str_repeat("\0", 10);

            return inet_ntop($masked) ?: null;
        }

        return null;
    }

    /**
     * Per-forum random secret, generated once. Not a credential — it only
     * prevents rainbow-tabling the visitor hashes.
     */
    protected function secret(): string
    {
        $secret = (string) $this->settings->get('linkrobins-birdseye.salt_secret');

        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            $this->settings->set('linkrobins-birdseye.salt_secret', $secret);
        }

        return $secret;
    }
}
