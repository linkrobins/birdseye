<?php

/*
 * This file is part of linkrobins/birdseye.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Birdseye\Tests\integration;

use Illuminate\Contracts\Mail\Mailer;

/**
 * Stands in for Flarum's mailer and records who each message was addressed to.
 * Counting deliveries is the whole point of DigestCommandTest, and the real
 * mailer would need a working transport to count anything.
 *
 * The callback is handed a small recorder rather than Illuminate's Message on
 * purpose: on the 1.x line Message wraps Swift_Message and on 2.x it wraps a
 * Symfony Email, so touching it would make this double line-specific for no
 * gain. The command only ever calls to() and subject().
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
     * Only illuminate 8 declares this, and only illuminate 13 declares cc()
     * and sendNow(). Implementing the union of the two contracts is what lets
     * one double serve both Flarum lines; the extra methods are inert on
     * whichever line does not ask for them.
     *
     * @return list<string>
     */
    public function failures()
    {
        return [];
    }

    private function record($callback): void
    {
        if (! is_callable($callback)) {
            return;
        }

        $recorder = new RecordedMessage();
        $callback($recorder);

        foreach ($recorder->to as $address) {
            DigestCommandTest::$sentTo[] = $address;
        }
    }
}
