<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> the migrations that build the ledger, in the order they ran */
    private const LEDGER = [
        '2026_08_05_000001_create_claude_request_ledger_table',
        '2026_08_05_000002_refine_claude_request_ledger_columns',
    ];

    public function up(): void
    {
        if (Schema::hasTable('claude_request_ledger')) {
            return;
        }

        foreach (self::LEDGER as $name) {
            (require database_path("migrations/{$name}.php"))->up();
        }
    }

    public function down(): void
    {
    }
};
