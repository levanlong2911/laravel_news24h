<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_OPERATION = 'video_session_events_session_operation_unique';

    public function up(): void
    {
        Schema::table('video_session_events', function (Blueprint $table): void {
            $table->uuid('operation_id')->nullable()->after('payload_json');
            $table->char('operation_payload_hash', 64)->nullable()->after('operation_id');
            $table->json('operation_result_json')->nullable()->after('operation_payload_hash');
            $table->unique(['session_id', 'operation_id'], self::UNIQUE_OPERATION);
        });
    }

    public function down(): void
    {
        if (DB::table('video_session_events')->whereNotNull('operation_id')->exists()) {
            throw new RuntimeException(
                'Cannot remove operation identity while recorded session operations exist.'
            );
        }

        Schema::table('video_session_events', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_OPERATION);
            $table->dropColumn([
                'operation_id',
                'operation_payload_hash',
                'operation_result_json',
            ]);
        });
    }
};
