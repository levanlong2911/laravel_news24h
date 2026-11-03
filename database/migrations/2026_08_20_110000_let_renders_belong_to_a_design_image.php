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

    public function up(): void
    {
        Schema::table('video_renders', function (Blueprint $table) {
            if (! Schema::hasColumn('video_renders', 'design_image_id')) {
                $table->char('design_image_id', 36)->nullable()->after('shot_id');
            }

            if (! $this->foreignKeyExists('video_renders_design_image_id_foreign')) {
                $table->foreign('design_image_id')->references('id')->on('video_design_images')->nullOnDelete();
            }

            // Hai unique cu deu khoa tren `shot_id`. Khi `shot_id` duoc phep NULL
            // thi MariaDB cho lap vo han trong unique chua NULL — nghia la render
            // cua design image mat sach chong-trung. Phai co cap unique rieng, neu
            // khong thi mot lan bam hai lan se tra tien hai lan.
            if (! $this->indexExists('video_renders_design_image_attempt_unique')) {
                $table->unique(['design_image_id', 'attempt_no'], 'video_renders_design_image_attempt_unique');
            }

            if (! $this->indexExists('video_renders_design_image_idempotency_unique')) {
                $table->unique(['design_image_id', 'idempotency_key'], 'video_renders_design_image_idempotency_unique');
            }
        });

        DB::statement('ALTER TABLE video_renders MODIFY shot_id CHAR(36) NULL');
        $this->addCheck('ALTER TABLE video_renders ADD CONSTRAINT video_renders_one_owner
            CHECK ((shot_id IS NULL) <> (design_image_id IS NULL))');
    }

    public function down(): void
    {
        // Mot dong so cai la mot khoan tien DA TIEU. Rollback khong duoc phep xoa
        // no de lay lai NOT NULL — dung lai va de nguoi quyet dinh.
        $orphans = DB::table('video_renders')->whereNull('shot_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException(sprintf(
                'video_renders con %d dong render cua design image (shot_id NULL). '
                .'Rollback se phai xoa lich su chi tieu — hay xu ly tay truoc.',
                $orphans,
            ));
        }

        if ($this->checkExists('video_renders_one_owner')) {
            DB::statement('ALTER TABLE video_renders DROP CONSTRAINT video_renders_one_owner');
        }

        DB::statement('ALTER TABLE video_renders MODIFY shot_id CHAR(36) NOT NULL');

        Schema::table('video_renders', function (Blueprint $table) {
            $table->dropUnique('video_renders_design_image_idempotency_unique');
            $table->dropUnique('video_renders_design_image_attempt_unique');
            $table->dropForeign(['design_image_id']);
            $table->dropColumn('design_image_id');
        });
    }

    /** @param string $statement the ALTER TABLE that adds the CHECK; a database that refuses a CHECK on a column a foreign key sets to NULL keeps the table without it and the rule rests on the code that writes renders */
    private function addCheck(string $statement): void
    {
        try {
            DB::statement($statement);
        } catch (QueryException $e) {
            if (! in_array((int) ($e->errorInfo[1] ?? 0), self::CHECK_ON_SET_NULL, true)) {
                throw $e;
            }

            echo '  video_renders_one_owner: CSDL tu choi CHECK tren cot co khoa ngoai SET NULL — bo qua ('.$e->errorInfo[2].')'.PHP_EOL;
        }
    }

    private function checkExists(string $name): bool
    {
        return DB::selectOne(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME = ?',
            [$name],
        ) !== null;
    }

    private function indexExists(string $name): bool
    {
        return DB::selectOne(
            'SELECT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
             LIMIT 1',
            ['video_renders', $name],
        ) !== null;
    }

    private function foreignKeyExists(string $name): bool
    {
        return DB::selectOne(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
             LIMIT 1',
            ['video_renders', $name],
        ) !== null;
    }
};
