<?php

/*
 * This file is part of linkrobins/birdseye.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Birdseye\Tests\integration\api;

use Flarum\Foundation\AbstractServiceProvider;
use LinkRobins\Birdseye\Api\StatusHandler;

class FakeStatusProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(StatusHandler::class, function ($container) {
            return $container->make(FakeStatusHandler::class);
        });
    }
}
