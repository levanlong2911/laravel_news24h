<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_metrics', function (Blueprint $table) {
            $table->unsignedInteger('haiku_input_tokens')->default(0)->after('used_haiku');
            $table->unsignedInteger('haiku_output_tokens')->default(0)->after('haiku_input_tokens');
            $table->unsignedInteger('sonnet_input_tokens')->default(0)->after('haiku_output_tokens');
            $table->unsignedInteger('sonnet_output_tokens')->default(0)->after('sonnet_input_tokens');
            $table->decimal('total_cost_usd', 10, 6)->default(0)->after('sonnet_output_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('prompt_metrics', function (Blueprint $table) {
            $table->dropColumn([
                'haiku_input_tokens', 'haiku_output_tokens',
                'sonnet_input_tokens', 'sonnet_output_tokens',
                'total_cost_usd',
            ]);
        });
    }
};
