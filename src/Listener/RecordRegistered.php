<?php

namespace LinkRobins\Birdseye\Listener;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\Registered;
use LinkRobins\Birdseye\Buffer\BufferedEvent;

/**
 * Forum-layer signal: a member registered. No visitor identity is attached —
 * the count is the datum, not who.
 */
class RecordRegistered
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(Registered $event): void
    {
        try {
            if (!(bool) $this->settings->get('linkrobins-birdseye.collect', true)) {
                return;
            }

            BufferedEvent::query()->insert([
                'occurred_at' => gmdate('Y-m-d H:i:s'),
                'type' => BufferedEvent::TYPE_REGISTER,
            ]);
        } catch (\Throwable) {
            // Best-effort by design.
        }
    }
}
