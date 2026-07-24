<?php

namespace LinkRobins\Birdseye\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Mail\Mailer;
use LinkRobins\Birdseye\Rollup\Rollup;
use Symfony\Component\Console\Input\InputOption;

/**
 * Weekly plain-text summary of the last full week (Mon–Sun UTC), mailed to
 * confirmed-email admins. Scheduled Monday mornings (see extend.php); a
 * settings row remembers the last week sent so a re-run or a second server
 * firing the scheduler can't double-send. Entirely local — reads rollups,
 * never the network. Best-effort like all of Birdseye: a broken mail setup
 * logs a warning per recipient and never throws.
 */
class DigestCommand extends AbstractCommand
{
    protected const SCALARS = ['visitors', 'pageviews', 'posts', 'registrations'];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Mailer $mailer,
        protected Translator $translator
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('birdseye:digest')
            ->setDescription('Email admins a summary of last week\'s forum activity')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Send even if this week\'s digest was already sent');
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

        if (!$this->input->getOption('force')
            && $this->settings->get('linkrobins-birdseye.digest_last_week') === $weekKey) {
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

        $recipients = User::query()
            ->join('group_user', 'group_user.user_id', '=', 'users.id')
            ->where('group_user.group_id', Group::ADMINISTRATOR_ID)
            ->where('users.is_email_confirmed', true)
            ->whereNotNull('users.email')
            ->get(['users.email', 'users.username']);

        $sent = 0;

        foreach ($recipients as $recipient) {
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
            $this->settings->set('linkrobins-birdseye.digest_last_week', $weekKey);
        }

        $this->info("Digest for week {$weekKey} sent to {$sent} admin(s).");

        return 0;
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
