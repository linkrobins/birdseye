<?php

namespace LinkRobins\Birdseye\Buffer;

use Flarum\Database\AbstractModel;

/**
 * A captured event in the short-lived buffer (see the events migration).
 * Rows live at most 72h: the sync command pushes them for processing (or
 * rolls them up locally when unkeyed) and deletes them.
 *
 * @property int $id
 * @property \Carbon\Carbon $occurred_at
 * @property string $type
 * @property string|null $path
 * @property int|null $discussion_id
 * @property string|null $visitor
 * @property string|null $country
 * @property string|null $referrer
 * @property string|null $device
 * @property string|null $ip_prefix
 * @property string|null $search_query
 */
class BufferedEvent extends AbstractModel
{
    protected $table = 'birdseye_events';

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public const TYPE_VIEW = 'view';
    public const TYPE_SEARCH = 'search';
    public const TYPE_POST = 'post';
    public const TYPE_REGISTER = 'register';
}
