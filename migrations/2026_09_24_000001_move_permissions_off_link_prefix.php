<?php

use Illuminate\Database\Schema\Builder;

/**
 * Move the permission keys off the "link…" prefix.
 *
 * fof/links treats every granted permission that starts with "link" and
 * contains "view" as one of its own link permissions and reads a link id out
 * of the name. "linkrobins-birdseye.viewStats" and its siblings therefore became link
 * ids on any forum running both extensions: MySQL cast the nonsense id to 0
 * silently, PostgreSQL rejects the query and every page fails. Only the key
 * changes — every group keeps exactly the grants it had, and the migration
 * is safe to run twice.
 */
$map = [
    'linkrobins-birdseye.viewStats' => 'lr-birdseye.viewStats',
];

$rename = function (Builder $schema, array $map) {
    $db = $schema->getConnection();
    foreach ($map as $old => $new) {
        // A group that already holds the new key must not end up with both.
        $done = $db->table('group_permission')->where('permission', $new)->pluck('group_id')->all();
        if ($done) {
            $db->table('group_permission')->where('permission', $old)->whereIn('group_id', $done)->delete();
        }
        $db->table('group_permission')->where('permission', $old)->update(['permission' => $new]);
    }
};

return [
    'up'   => fn (Builder $schema) => $rename($schema, $map),
    'down' => fn (Builder $schema) => $rename($schema, array_flip($map)),
];
