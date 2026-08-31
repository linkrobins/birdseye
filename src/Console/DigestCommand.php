<?php

namespace LinkRobins\Birdseye\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Mail\Mailer;
use LinkRobins\Birdseye\Rollup\Rollup;
use Symfony\Component\Console\Input\InputOption;

/**
 * Weekly plain-text summary of the last full week (Mon–Sun UTC), mailed to
 * confirmed-email admins. Scheduled Monday mornings (see extend.php); a
 * settings row remembers the last week sent, which stops a LATER re-run, and a
 * cache lock is taken before that row is read, which stops a CONCURRENT one --
 * without it the marker is a check-then-act race and two schedulers firing in
 * the same minute both send. Entirely local — reads rollups,
 * never the network. Best-effort like all of Birdseye: a broken mail setup
 * logs a warning per recipient and never throws.
 */
class DigestCommand extends AbstractCommand
{
    protected const SCALARS = ['visitors', 'pageviews', 'posts', 'registrations'];

    /** Whether this run actually put mail on the wire; see whileHoldingWeek(). */
    protected bool $sentThisRun = false;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Mailer $mailer,
        protected Translator $translator,
        protected Cache $cache
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('birdseye:digest')
            ->setDescription('Email admins a summary of last week\'s forum activity')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Send even if this week\'s digest was already sent')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List who would be emailed and send nothing');
    }

    protected function fire(): int
    {
        if (!(bool) $this->settings->get('linkrobins-birdseye.weekly_digest', true)) {
            $this->info('Weekly digest is disabled in settings.');

            return 0;
        }

        $monday = new \DateTimeImmutable('monday this week', new \DateTimeZone('UTC'));
        $weekStart = $monday->modify('-7 days');
        $weekEnd = $monday->modify('-1 day');
        $priorStart = $monday->modify('-14 days');
        $priorEnd = $monday->modify('-8 days');

        $weekKey = $weekStart->format('Y-m-d');
        $force = (bool) $this->input->getOption('force');

        // Answers "who does this actually mail?" without sending anything —
        // the first thing worth checking when someone reports a duplicate,
        // since two admin accounts reaching one inbox looks identical to a
        // double send from the recipient's side.
        if ($this->input->getOption('dry-run')) {
            $recipients = $this->recipients();

            $this->info("Week {$weekKey} digest would go to ".count($recipients).' recipient(s):');

            foreach ($recipients as $recipient) {
                $this->info("  {$recipient->username} <{$recipient->email}>");
            }

            if (!$force && $this->settings->get('linkrobins-birdseye.digest_last_week') === $weekKey) {
                $this->info('(Already marked sent for this week, so a real run would do nothing without --force.)');
            }

            return 0;
        }

        $send = function () use ($weekKey, $force, $weekStart, $weekEnd, $priorStart, $priorEnd) {
            // The durable guard, and the only one that survives a restart or
            // a cache wipe. It cannot be trusted to catch a same-minute double
            // run on its own — settings are read once per process — which is
            // what the lock around this closure is for.
            if (!$force && $this->settings->get('linkrobins-birdseye.digest_last_week') === $weekKey) {
                $this->info("Digest for week {$weekKey} already sent.");

                return 0;
            }

            $week = $this->weekTotals($weekStart, $weekEnd);
            $prior = $this->weekTotals($priorStart, $priorEnd);

            if ($week['rows'] === 0) {
                // Fresh install or collection off all week — no email, and no
                // sent-marker so a late backfill could still go out on --force.
                $this->info('No rollup data for last week; nothing to send.');

                return 0;
            }

            $body = $this->body($weekStart, $weekEnd, $week, $prior);
            $subject = $this->translator->trans('linkrobins-birdseye.email.digest.subject', [
                '{forum}' => (string) $this->settings->get('forum_title'),
                '{visitors}' => number_format($week['visitors']),
            ]);

            $sent = 0;

            foreach ($this->recipients() as $recipient) {
                try {
                    $this->mailer->raw($body, function ($message) use ($recipient, $subject) {
                        $message->to($recipient->email)->subject($subject);
                    });
                    $sent++;
                } catch (\Throwable $e) {
                    $this->error("Digest to {$recipient->email} failed: {$e->getMessage()}");
                }
            }

            if ($sent > 0) {
                $this->sentThisRun = true;
                $this->settings->set('linkrobins-birdseye.digest_last_week', $weekKey);
            }

            $this->info("Digest for week {$weekKey} sent to {$sent} admin(s).");

            return 0;
        };

        // --force is someone deliberately re-sending, so it must not be turned
        // away by a lock that is still holding this week open from earlier.
        return $force ? $send() : $this->whileHoldingWeek($weekKey, $send);
    }

    /**
     * Confirmed-email administrators, one entry per address.
     *
     * group_user is keyed on (user_id, group_id), so the join cannot repeat a
     * user; the dedupe is for the case the database will happily allow, which
     * is two admin accounts holding the same address in different cases on a
     * case-sensitive collation.
     *
     * @return list<\Flarum\User\User>
     */
    protected function recipients(): array
    {
        $rows = User::query()
            ->join('group_user', 'group_user.user_id', '=', 'users.id')
            ->where('group_user.group_id', Group::ADMINISTRATOR_ID)
            ->where('users.is_email_confirmed', true)
            ->whereNotNull('users.email')
            ->get(['users.email', 'users.username']);

        $byAddress = [];

        foreach ($rows as $row) {
            $byAddress[mb_strtolower(trim((string) $row->email))] ??= $row;
        }

        return array_values($byAddress);
    }

    /**
     * Run $callback holding an exclusive lock on this week.
     *
     * extend.php already asks for onOneServer(), but that is advisory: it
     * needs a cache shared by every node, and a per-node or misconfigured one
     * turns it into a silent no-op. Flarum's default file store is a real
     * lock and is enough for the single-server installs this affects.
     *
     * ⚠️ The lock is NOT released after a send, and that is the point. Flarum
     * caches settings in memory for the life of the process, so a second run
     * that booted before the marker was written keeps reading the old value no
     * matter when it looks. Releasing would let exactly that straggler through
     * the guard and send the duplicate this is here to prevent. Holding the
     * lock until it expires blocks it instead, and by then any newer process
     * has loaded the marker for itself.
     *
     * A store with no lock support throws rather than returning false, and
     * Birdseye never lets infrastructure break a best-effort feature, so that
     * case runs unlocked exactly as before.
     *
     * @param callable(): int $callback
     */
    protected function whileHoldingWeek(string $weekKey, callable $callback): int
    {
        try {
            // Long enough to cover a straggling scheduler and a slow SMTP
            // server; short enough that a killed process frees the week again
            // well before there is anything new to send.
            $lock = $this->cache->lock("linkrobins-birdseye.digest.{$weekKey}", 600);
        } catch (\Throwable $e) {
            return $callback();
        }

        if (!$lock->get()) {
            $this->info("Week {$weekKey} is already being sent, or was just sent, by another process.");

            return 0;
        }

        try {
            return $callback();
        } finally {
            // Only hand the week back if nothing went out; see above.
            if (!$this->sentThisRun) {
                $lock->release();
            }
        }
    }

    /**
     * @return array{rows: int, visitors: int, pageviews: int, posts: int, registrations: int, topDiscussion: ?array{label: string, visits: int}, topSearch: ?array{label: string, visits: int}}
     */
    protected function weekTotals(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $rows = Rollup::query()
            ->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->get();

        $totals = array_fill_keys(self::SCALARS, 0);
        $discussions = [];
        $searches = [];

        foreach ($rows as $row) {
            if ($row->key === '' && in_array($row->metric, self::SCALARS, true)) {
                $totals[$row->metric] += $row->value;
            } elseif ($row->metric === 'discussion') {
                $discussions[$row->key] = ($discussions[$row->key] ?? 0) + $row->value;
            } elseif ($row->metric === 'search') {
                $searches[$row->key] = ($searches[$row->key] ?? 0) + $row->value;
            }
        }

        arsort($discussions);
        arsort($searches);

        $topDiscussion = null;

        if ($discussions !== []) {
            $id = (int) array_key_first($discussions);
            // Recipients are admins; no visibility scoping needed here.
            $title = Discussion::query()->whereKey($id)->value('title');
            $topDiscussion = ['label' => (string) ($title ?? "#{$id}"), 'visits' => $discussions[array_key_first($discussions)]];
        }

        $topSearch = $searches === []
            ? null
            : ['label' => (string) array_key_first($searches), 'visits' => $searches[array_key_first($searches)]];

        return [
            'rows' => count($rows),
            'visitors' => $totals['visitors'],
            'pageviews' => $totals['pageviews'],
            'posts' => $totals['posts'],
            'registrations' => $totals['registrations'],
            'topDiscussion' => $topDiscussion,
            'topSearch' => $topSearch,
        ];
    }

    /**
     * @param array<string, mixed> $week
     * @param array<string, mixed> $prior
     */
    protected function body(\DateTimeImmutable $start, \DateTimeImmutable $end, array $week, array $prior): string
    {
        $t = fn (string $key, array $params = []) => $this->translator->trans("linkrobins-birdseye.email.digest.{$key}", $params);

        $lines = [
            $t('heading', [
                '{forum}' => (string) $this->settings->get('forum_title'),
                '{start}' => $start->format('M j'),
                '{end}' => $end->format('M j'),
            ]),
            '',
            $t('visitors_line', ['{count}' => number_format($week['visitors']), '{change}' => $this->change($week['visitors'], $prior['visitors'])]),
            $t('pageviews_line', ['{count}' => number_format($week['pageviews']), '{change}' => $this->change($week['pageviews'], $prior['pageviews'])]),
            $t('posts_line', ['{count}' => number_format($week['posts']), '{change}' => $this->change($week['posts'], $prior['posts'])]),
            $t('registrations_line', ['{count}' => number_format($week['registrations']), '{change}' => $this->change($week['registrations'], $prior['registrations'])]),
        ];

        if ($week['topDiscussion'] !== null) {
            $lines[] = '';
            $lines[] = $t('top_discussion_line', [
                '{title}' => $week['topDiscussion']['label'],
                '{views}' => number_format($week['topDiscussion']['visits']),
            ]);
        }

        if ($week['topSearch'] !== null) {
            $lines[] = $t('top_search_line', [
                '{query}' => $week['topSearch']['label'],
                '{count}' => number_format($week['topSearch']['visits']),
            ]);
        }

        $lines[] = '';
        $lines[] = $t('footer');

        return implode("\n", $lines);
    }

    /** "+23%", "-4%", "±0%" — or an em dash when the prior week has no baseline. */
    protected function change(int $current, int $prior): string
    {
        if ($prior === 0) {
            return '—';
        }

        $pct = (int) round(($current - $prior) / $prior * 100);

        return ($pct > 0 ? '+' : ($pct < 0 ? '' : '±')) . $pct . '%';
    }
}
