<?php

/*
 * This file is part of linkrobins/birdseye.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Birdseye\Tests\integration;

/**
 * The message object RecordingMailer hands to a send callback. Fluent, like
 * the real one, and remembers only what the digest sets.
 */
class RecordedMessage
{
    /** @var list<string> */
    public array $to = [];

    public ?string $subject = null;

    public function to($address, $name = null, $override = false): self
    {
        foreach ((array) $address as $one) {
            $this->to[] = (string) $one;
        }

        return $this;
    }

    public function subject($subject): self
    {
        $this->subject = (string) $subject;

        return $this;
    }
}
