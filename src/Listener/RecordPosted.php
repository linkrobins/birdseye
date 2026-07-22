<?php

namespace LinkRobins\Birdseye\Listener;

use Flarum\Post\Event\Posted;
use Flarum\Settings\SettingsRepositoryInterface;
use LinkRobins\Birdseye\Buffer\BufferedEvent;

/**
 * Forum-layer signal: a post was created. Recorded after the write commits
 * (event listeners run post-save), and best-effort like all capture — per
 * the portability contracts, analytics never joins the write transaction.
 */
class RecordPosted
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(Posted $event): void
    {
        try {
            if (!(bool) $this->settings->get('linkrobins-birdseye.collect', true)) {
                return;
            }

            BufferedEvent::query()->insert([
                'occurred_at' => gmdate('Y-m-d H:i:s'),
                'type' => BufferedEvent::TYPE_POST,
                'discussion_id' => $event->post->discussion_id,
            ]);
        } catch (\Throwable) {
            // Best-effort by design.
        }
    }
}
