<?php

namespace LinkRobins\Birdseye\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Contracts\Bus\Dispatcher;
use LinkRobins\Birdseye\Buffer\BufferedEvent;
use LinkRobins\Birdseye\Job\SyncBatchJob;

/**
 * Hourly tick (see extend.php). Thin by contract: dispatches the real work
 * as a queued job so it behaves the same under sync and redis/database
 * queue drivers, then prunes buffer rows that outlived the push window
 * (missed syncs beyond 72h are accepted data loss, documented).
 */
class SyncCommand extends AbstractCommand
{
    protected $signature = 'birdseye:sync';

    protected $description = 'Push buffered analytics events for processing (or roll up locally when unkeyed) and prune the buffer';

    public function __construct(
        protected Dispatcher $bus
    ) {
        parent::__construct();
    }

    protected function fire(): int
    {
        $this->bus->dispatch(new SyncBatchJob());

        // Prune anything the sync window never consumed. Chunked so a
        // backlog can't hold a table lock.
        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-72 hours'));

        do {
            $deleted = BufferedEvent::query()
                ->where('occurred_at', '<', $cutoff)
                ->limit(5000)
                ->delete();
        } while ($deleted > 0);

        $this->info('Birdseye sync dispatched.');

        return 0;
    }
}
