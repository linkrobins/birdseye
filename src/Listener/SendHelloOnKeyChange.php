<?php

namespace LinkRobins\Birdseye\Listener;

use Flarum\Settings\Event\Saved;
use Illuminate\Contracts\Bus\Dispatcher;
use LinkRobins\Birdseye\Job\HelloJob;

/**
 * When an admin saves a non-empty license key, fire the first-contact
 * check-in immediately (see HelloJob) instead of leaving the key unbound
 * until the next complete-day sync. Keyed off the saved payload, so a
 * settings save that doesn't touch the key costs nothing.
 */
class SendHelloOnKeyChange
{
    public function __construct(
        protected Dispatcher $bus
    ) {
    }

    public function handle(Saved $event): void
    {
        $key = $event->settings['linkrobins-birdseye.license_key'] ?? null;

        if (is_string($key) && trim($key) !== '') {
            $this->bus->dispatch(new HelloJob());
        }
    }
}
