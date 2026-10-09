<?php

namespace LinkRobins\Birdseye\Console;

use Flarum\Console\AbstractCommand;
use LinkRobins\Birdseye\Stats\CountryDownload;
use Symfony\Component\Console\Input\InputOption;

/**
 * Hourly tick (see extend.php) that keeps the downloaded country database
 * current. Almost every run only checks whether a download is due and stops;
 * the fetch itself happens about once a month. --force fetches now, for an
 * admin who does not want to wait for the schedule.
 */
class CountryDatabaseCommand extends AbstractCommand
{
    public function __construct(
        protected CountryDownload $download
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('birdseye:country-database')
            ->setDescription('Download the free DB-IP country database when a new edition is due')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Download now, even if the current edition is already here');
    }

    protected function fire(): int
    {
        if (! $this->download->wanted()) {
            $this->info('Nothing to do: the download is turned off, a database file is set, or IP prefix lookup is off.');

            return 0;
        }

        $now = time();

        if (! $this->input->getOption('force') && ! $this->download->due($now)) {
            $this->info('The country database is up to date.');

            return 0;
        }

        try {
            $edition = $this->download->run($now);
        } catch (\RuntimeException $e) {
            $this->error('Country database download failed: '.$e->getMessage());

            return 1;
        }

        $this->info("Country database {$edition} installed.");

        return 0;
    }
}
