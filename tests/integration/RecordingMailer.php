<?php

/*
 * This file is part of linkrobins/birdseye.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Birdseye\Tests\integration;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;
use Symfony\Component\Mime\Email;

/**
 * Stands in for Flarum's mailer and records who each message was addressed to.
 * Counting deliveries is the whole point of DigestCommandTest, and Flarum's
 * real mailer would need a working transport to count anything.
 */
class RecordingMailer implements Mailer
{
    public function to($users)
    {
        return $this;
    }

    public function cc($users)
    {
        return $this;
    }

    public function bcc($users)
    {
        return $this;
    }

    public function raw($text, $callback)
    {
        return $this->record($callback);
    }

    public function send($view, array $data = [], $callback = null)
    {
        return $this->record($callback);
    }

    public function sendNow($mailable, array $data = [], $callback = null)
    {
        return $this->record($callback);
    }

    /**
     * Run the caller's callback against a real Message so the recorded address
     * is the one it actually set, not one this double guessed.
     */
    private function record($callback): void
    {
        if (! is_callable($callback)) {
            return;
        }

        $message = new Message(new Email());
        $callback($message);

        foreach ($message->getSymfonyMessage()->getTo() as $address) {
            DigestCommandTest::$sentTo[] = $address->getAddress();
        }
    }
}
