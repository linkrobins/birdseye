<?php

/*
 * This file is part of linkrobins/birdseye.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Birdseye\Tests\integration;

use Carbon\Carbon;
use Flarum\Testing\integration\ConsoleTestCase;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Mail\Mailer;
use PHPUnit\Framework\Attributes\Test;

/**
 * The digest must go out exactly once a week however many schedulers are
 * running. A customer received two identical copies moments apart every week
 * for months before this was found, so these pin the guards rather than the
 * happy path.
 */
class DigestCommandTest extends ConsoleTestCase
{
    /** @var list<string> */
    public static array $sentTo = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-birdseye');

        // Last full week (Mon–Sun UTC) — the window the command reads.
        $weekStart = Carbon::parse('monday this week', 'UTC')->subDays(7);

        $rollups = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->copy()->addDays($i)->toDateString();
            foreach (['visitors' => 10, 'pageviews' => 40, 'posts' => 2, 'registrations' => 1] as $metric => $value) {
                $rollups[] = ['date' => $date, 'metric' => $metric, 'key' => '', 'value' => $value];
            }
        }

        // User 1 is the administrator the installer creates; user 3 is a
        // second one, because the digest is the only Birdseye email that goes
        // to EVERY admin and that is what made it the only one to duplicate.
        $this->prepareDatabase([
            'users' => [
                ['id' => 3, 'username' => 'second', 'email' => 'second@example.com', 'is_email_confirmed' => 1, 'password' => 'x'],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => 1],
            ],
            'birdseye_rollups' => $rollups,
        ]);

        self::$sentTo = [];
        $this->app()->getContainer()->instance(Mailer::class, new RecordingMailer());

        // Pin the installer's admin rather than trusting its defaults.
        $this->database()->table('users')->where('id', 1)
            ->update(['email' => 'first@example.com', 'is_email_confirmed' => 1]);
    }

    private function weekKey(): string
    {
        return Carbon::parse('monday this week', 'UTC')->subDays(7)->toDateString();
    }

    private function lockStore(): LockProvider
    {
        $store = $this->app()->getContainer()->make(Cache::class)->getStore();

        // Both illuminate 8 and 13 give file and array stores a real lock; if
        // that ever stops being true these tests would silently pass.
        $this->assertInstanceOf(LockProvider::class, $store, 'the cache store must be able to lock');

        return $store;
    }

    #[Test]
    /** @test */
    public function it_mails_every_confirmed_admin_once(): void
    {
        $this->runCommand(['command' => 'birdseye:digest']);

        $this->assertCount(2, self::$sentTo);
        $this->assertSame(count(self::$sentTo), count(array_unique(self::$sentTo)));
    }

    #[Test]
    /** @test */
    public function it_sends_nothing_while_another_run_holds_the_week(): void
    {
        // Exactly what a second scheduler firing in the same minute walks into.
        $held = $this->lockStore()->lock('linkrobins-birdseye.digest.'.$this->weekKey(), 600);
        $this->assertTrue($held->get(), 'precondition: the lock was free');

        $output = $this->runCommand(['command' => 'birdseye:digest']);

        $this->assertSame([], self::$sentTo, 'a second concurrent run must not send');
        $this->assertStringContainsString('already being sent', $output);
    }

    #[Test]
    /** @test */
    public function it_hands_the_week_back_when_it_sent_nothing(): void
    {
        // No rollups for last week means no email, so the week must stay open
        // for a later backfill rather than being held to the lock's TTL.
        $this->database()->table('birdseye_rollups')->delete();

        $this->runCommand(['command' => 'birdseye:digest']);

        $this->assertSame([], self::$sentTo);

        $lock = $this->lockStore()->lock('linkrobins-birdseye.digest.'.$this->weekKey(), 600);
        $this->assertTrue($lock->get(), 'the lock should have been released');
    }

    #[Test]
    /** @test */
    public function it_sends_one_copy_per_address(): void
    {
        // Two admin accounts, one address. The join cannot produce this, but a
        // case-sensitive collation can.
        $this->database()->table('users')->where('id', 3)->update(['email' => 'FIRST@example.com']);

        $this->runCommand(['command' => 'birdseye:digest']);

        $this->assertCount(1, self::$sentTo, 'one inbox should get one copy');
    }

    #[Test]
    /** @test */
    public function dry_run_reports_recipients_and_sends_nothing(): void
    {
        $output = $this->runCommand(['command' => 'birdseye:digest', '--dry-run' => true]);

        $this->assertSame([], self::$sentTo);
        $this->assertStringContainsString('would go to 2 recipient(s)', $output);
    }
}
