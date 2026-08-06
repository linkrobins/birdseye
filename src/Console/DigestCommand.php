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
 * Weekly summary of the last full week (Mon–Sun UTC), mailed to
 * confirmed-email admins as multipart HTML + plain text — the HTML part rides
 * core's branded email layout so it matches the forum's other mail. Scheduled Monday mornings (see extend.php); a
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
        $viewData = $this->viewData($weekStart, $weekEnd, $week, $prior, $body);
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
                $this->mailer->send(
                    [
                        'html' => 'linkrobins-birdseye::email.digest-html',
                        'text' => 'linkrobins-birdseye::email.digest-plain',
                    ],
                    $viewData,
                    function ($message) use ($recipient, $subject) {
                        $message->to($recipient->email)->subject($subject);
                    }
                );
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

    /**
     * Structured data for the HTML email view: stat rows (label / count /
     * change with a sentiment colour) and the top-content lines. The plain
     * part reuses the classic text body verbatim.
     *
     * @param array<string, mixed> $week
     * @param array<string, mixed> $prior
     * @return array<string, mixed>
     */
    protected function viewData(\DateTimeImmutable $start, \DateTimeImmutable $end, array $week, array $prior, string $plainBody): array
    {
        $t = fn (string $key, array $params = []) => $this->translator->trans("linkrobins-birdseye.email.digest.{$key}", $params);

        $stat = function (string $labelKey, string $metric) use ($t, $week, $prior): array {
            $change = $this->change($week[$metric], $prior[$metric]);

            return [
                'label' => $t($labelKey),
                'count' => number_format($week[$metric]),
                'change' => $change,
                'color' => str_starts_with($change, '+') ? '#2e7d32'
                    : (str_starts_with($change, '-') ? '#c62828' : '#999999'),
            ];
        };

        return [
            'title' => $t('heading', [
                '{forum}' => (string) $this->settings->get('forum_title'),
                '{start}' => $start->format('M j'),
                '{end}' => $end->format('M j'),
            ]),
            'stats' => [
                $stat('visitors_label', 'visitors'),
                $stat('pageviews_label', 'pageviews'),
                $stat('posts_label', 'posts'),
                $stat('registrations_label', 'registrations'),
            ],
            'topDiscussionLabel' => $t('top_discussion_label'),
            'topSearchLabel' => $t('top_search_label'),
            'topDiscussion' => $week['topDiscussion'] === null ? null : [
                'label' => $week['topDiscussion']['label'],
                'suffix' => $t('views_suffix', ['{views}' => number_format($week['topDiscussion']['visits'])]),
            ],
            'topSearch' => $week['topSearch'] === null ? null : [
                'label' => $week['topSearch']['label'],
                'suffix' => $t('searches_suffix', ['{count}' => number_format($week['topSearch']['visits'])]),
            ],
            'footerText' => $t('footer'),
            'plainBody' => $plainBody,
        ];
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
