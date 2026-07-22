<?php

namespace LinkRobins\Birdseye;

final class Permissions
{
    /**
     * Grants read access to the analytics dashboard data (stats + world map)
     * and surfaces the Analytics entry in the forum's session menu. Admins
     * always pass (User::hasPermission short-circuits on isAdmin); no group
     * holds it by default — exposing traffic stats to members is deliberately
     * an operator opt-in, so nothing is seeded in a migration.
     */
    public const VIEW_STATS = 'linkrobins-birdseye.viewStats';
}
