<?php

namespace LinkRobins\Birdseye\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use LinkRobins\Birdseye\Permissions;

/**
 * Adds birdseyeCanViewStats to the forum payload so the forum frontend knows
 * whether to offer the Analytics entry in the session menu. Fail-closed: this
 * ships on every forum response, so any error must read as "no", never 500
 * the boot payload.
 */
class ForumFields
{
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('birdseyeCanViewStats')
                ->get(function ($model, Context $context) {
                    try {
                        return $context->getActor()->hasPermission(Permissions::VIEW_STATS);
                    } catch (\Throwable) {
                        return false;
                    }
                }),
        ];
    }
}
