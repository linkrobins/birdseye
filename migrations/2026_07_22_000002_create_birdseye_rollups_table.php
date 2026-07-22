<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// Permanent per-day aggregates — the forum's own analytics history. Scalars
// use key='' (metric='visits'); top-lists use one row per key
// (metric='page', key='/d/123'). key is NOT NULL because unique indexes
// treat NULLs as distinct on MySQL — '' is the "no key" value.
// Kilobytes per month, never pruned.
return [
    'up' => function (Builder $schema) {
        if (!$schema->hasTable('birdseye_rollups')) {
            $schema->create('birdseye_rollups', function (Blueprint $table) {
                $table->increments('id');
                $table->date('date');
                $table->string('metric', 30);
                $table->string('key', 150)->default('');
                $table->unsignedBigInteger('value')->default(0);

                $table->unique(['date', 'metric', 'key']);
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('birdseye_rollups');
    },
];
