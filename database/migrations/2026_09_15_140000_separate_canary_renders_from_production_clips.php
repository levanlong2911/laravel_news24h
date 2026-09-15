<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mot luot render de THU hop dong provider khong duoc dung chung o voi clip that.
 *
 * Man hinh lay clip cua mot shot bang `orderBy('attempt_no')` roi `keyBy('shot_id')`,
 * ma `keyBy` giu phan tu CUOI — nghia la o luon hien attempt cao nhat. Mot luot thu
 * dung chung `shot_id` se co attempt_no lon hon va CHIEM o cua clip that, ke ca khi
 * clip that da succeeded.
 *
 * CHECK o day khong phai trang tri. Thieu no thi mot gia tri go nham — `'canary '`
 * co dau cach — lam hang do BIEN MAT khoi man hinh vinh vien ma khong bao gi, vi no
 * khong bang `'production'`. Co CHECK thi do la loi ngay luc ghi.
 *
 * KHONG them index: `video_renders_shot_id_attempt_no_unique (shot_id, attempt_no)`
 * da dan dau bang dung cot ma truy van UI loc, va so render tren moi shot bi chan boi
 * so lan retry. Them index chi ton chi phi ghi ma chua do duoc loi ich nao.
 */
return new class extends Migration
{
    private const CHECK = 'video_renders_execution_purpose_ck';

    public function up(): void
    {
        if (! Schema::hasColumn('video_renders', 'execution_purpose')) {
            Schema::table('video_renders', function (Blueprint $table): void {
                $table->string('execution_purpose', 16)
                    ->default('production')
                    ->after('render_kind');
            });
        }

        if (! $this->checkExists(self::CHECK)) {
            DB::statement(
                'ALTER TABLE video_renders ADD CONSTRAINT '.self::CHECK
                ." CHECK (execution_purpose IN ('production', 'canary'))",
            );
        }
    }

    public function down(): void
    {
        if ($this->checkExists(self::CHECK)) {
            DB::statement('ALTER TABLE video_renders DROP CONSTRAINT '.self::CHECK);
        }

        if (Schema::hasColumn('video_renders', 'execution_purpose')) {
            Schema::table('video_renders', function (Blueprint $table): void {
                $table->dropColumn('execution_purpose');
            });
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
};
