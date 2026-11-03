<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<int> the errors of MariaDB (1901) and MySQL (3823) for a CHECK on a column a foreign key sets to NULL */
    private const CHECK_ON_SET_NULL = [1901, 3823];

    private const CONSTRAINT = 'video_renders_one_owner';

    private const OWNERLESS = 'shot_id IS NULL AND design_image_id IS NULL AND video_session_id IS NULL';

    private const ONE_OWNER = '(shot_id IS NOT NULL) + (design_image_id IS NOT NULL) + (video_session_id IS NOT NULL) = 1';

    private const LEGACY = "created_at < '2026-08-30 11:00:00'";

    public function up(): void
    {
        if (! Schema::hasColumn('video_renders', 'owner_deleted_at')) {
            Schema::table('video_renders', function (Blueprint $table): void {
                $table->timestamp('owner_deleted_at')->nullable()->after('video_session_id');
            });
        }

        $this->dropConstraint();

        DB::table('video_renders')
            ->whereNull('shot_id')
            ->whereNull('design_image_id')
            ->whereNull('video_session_id')
            ->where('created_at', '>=', '2026-08-30 11:00:00')
            ->whereNull('owner_deleted_at')
            ->update(['owner_deleted_at' => now()]);

        $this->addConstraint(
            '('.self::ONE_OWNER.') OR ('.self::OWNERLESS.' AND '.self::LEGACY.') OR ('.self::OWNERLESS.' AND owner_deleted_at IS NOT NULL)'
        );
    }

    public function down(): void
    {
        $this->dropConstraint();

        DB::statement('SET SESSION check_constraint_checks = 0');

        try {
            $this->addConstraint('('.self::ONE_OWNER.') OR ('.self::OWNERLESS.' AND '.self::LEGACY.')');
        } finally {
            DB::statement('SET SESSION check_constraint_checks = 1');
        }

        if (Schema::hasColumn('video_renders', 'owner_deleted_at')) {
            Schema::table('video_renders', function (Blueprint $table): void {
                $table->dropColumn('owner_deleted_at');
            });
        }
    }

    private function dropConstraint(): void
    {
        $exists = DB::table('information_schema.CHECK_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('CONSTRAINT_NAME', self::CONSTRAINT)
            ->exists();

        if ($exists) {
            DB::statement('ALTER TABLE video_renders DROP CONSTRAINT '.self::CONSTRAINT);
        }
    }

    /** @param string $clause the CHECK condition; a database that refuses a CHECK on a column a foreign key sets to NULL keeps the table without it and the rule rests on the code that writes renders */
    private function addConstraint(string $clause): void
    {
        try {
            DB::statement('ALTER TABLE video_renders ADD CONSTRAINT '.self::CONSTRAINT.' CHECK ('.$clause.')');
        } catch (QueryException $e) {
            if (! in_array((int) ($e->errorInfo[1] ?? 0), self::CHECK_ON_SET_NULL, true)) {
                throw $e;
            }

            echo '  '.self::CONSTRAINT.': CSDL tu choi CHECK tren cot co khoa ngoai SET NULL — bo qua ('.$e->errorInfo[2].')'.PHP_EOL;
        }
    }
};
