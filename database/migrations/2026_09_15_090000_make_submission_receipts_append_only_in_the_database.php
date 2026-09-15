<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bien lai la chung tu chi phi. Chan o tang model chi chan duong Eloquent —
 * `DB::table(...)->update()` va `->delete()` van di qua duoc, va do la duong ma
 * mot doan code voi va nhat dinh se dung.
 *
 * Trigger la cho duy nhat khong ai di vong duoc: no chan o ngay dong ghi.
 *
 * `restrictOnDelete` cua migration truoc bao ve render/attempt CHA khoi bi xoa,
 * khong bao ve chinh hang bien lai — hai chuyen khac nhau.
 */
return new class extends Migration
{
    private const TABLE = 'video_provider_submission_receipts';

    public function up(): void
    {
        $this->drop();

        DB::unprepared(sprintf(
            "CREATE TRIGGER %s_no_update BEFORE UPDATE ON %s FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bien lai submit la append-only: khong duoc sua.'",
            self::TABLE,
            self::TABLE,
        ));

        DB::unprepared(sprintf(
            "CREATE TRIGGER %s_no_delete BEFORE DELETE ON %s FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bien lai submit la append-only: khong duoc xoa.'",
            self::TABLE,
            self::TABLE,
        ));
    }

    public function down(): void
    {
        $this->drop();
    }

    private function drop(): void
    {
        foreach (['no_update', 'no_delete'] as $name) {
            DB::unprepared(sprintf('DROP TRIGGER IF EXISTS %s_%s', self::TABLE, $name));
        }
    }
};
