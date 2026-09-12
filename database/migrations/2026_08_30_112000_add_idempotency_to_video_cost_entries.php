<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_cost_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('video_cost_entries', 'cost_idempotency_key')) {
                $table->string('cost_idempotency_key', 190)->nullable();
                $table->unique('cost_idempotency_key', 'video_cost_entry_idempotency_uq');
            }
        });
    }

    public function down(): void
    {
        Schema::table('video_cost_entries', function (Blueprint $table): void {
            if (Schema::hasColumn('video_cost_entries', 'cost_idempotency_key')) {
                $table->dropUnique('video_cost_entry_idempotency_uq');
                $table->dropColumn('cost_idempotency_key');
            }
        });
    }
};

