<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// Short-lived capture buffer (pruned at 72h). No foreign keys on purpose:
// rows must survive the referenced discussion/user being deleted, and the
// table is append-then-drop, never joined.
return [
    'up' => function (Builder $schema) {
        if (!$schema->hasTable('birdseye_events')) {
            $schema->create('birdseye_events', function (Blueprint $table) {
                $table->increments('id');
                $table->timestamp('occurred_at')->useCurrent()->index();
                $table->string('type', 20);
                $table->string('path', 191)->nullable();
                $table->unsignedInteger('discussion_id')->nullable();
                // Daily-salted hash of IP+UA, truncated. Never reversible.
                $table->string('visitor', 16)->nullable();
                $table->char('country', 2)->nullable();
                $table->string('referrer', 191)->nullable();
                $table->string('device', 10)->nullable();
                // Anonymized prefix for transient country lookup (optional,
                // see the geo_ip_prefix setting). Dropped with the row.
                $table->string('ip_prefix', 45)->nullable();
                $table->string('search_query', 191)->nullable();
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('birdseye_events');
    },
];
