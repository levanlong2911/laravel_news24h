<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Bon nhom cot ma Phan 10 doi nhung bang dung ra chua co.
 *
 * Chung deu la cot BO SUNG cho bang dang co du lieu that (82 dong ledger,
 * 25 revision), nen khong cot nao duoc phep NOT NULL tran: moi cot di kem
 * mot default de dong cu con hop le, roi backfill gia tri that ngay sau do.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * §10.56 + §10.57 — lineage cua revision.
         *
         * So revision chi noi "lan thu may", khong noi "sinh ra tu dau".
         * Vision QA bac Revision 3 thi Revision 4 phai chi nguoc ve 3, neu
         * khong chuoi sua doi thanh mot day so roi rac.
         */
        Schema::table(
            'canonical_concept_revisions',
            function (Blueprint $table): void {
                $table
                    ->uuid('parent_revision_id')
                    ->nullable()
                    ->after('revision');

                $table
                    ->string('revision_reason', 80)
                    ->nullable()
                    ->after('parent_revision_id');

                $table
                    ->foreign(
                        'parent_revision_id',
                        'canonical_revision_parent_fk'
                    )
                    ->references('id')
                    ->on('canonical_concept_revisions')
                    ->nullOnDelete();
            }
        );

        /*
         * §10.77 — noi attempt voi so chi tieu toan cuc.
         *
         * cost_usd tren attempt chi la ban chup telemetry; nguon tien that
         * van la cost_entries. Co tham chieu nay thi doi soat duoc, khong
         * phai cong hai lan.
         */
        Schema::table(
            'canonical_concept_attempts',
            function (Blueprint $table): void {
                $table
                    ->string('usage_reference', 120)
                    ->nullable()
                    ->after('cost_usd');

                $table->index(
                    'usage_reference',
                    'canonical_attempt_usage_reference_idx'
                );
            }
        );

        /*
         * §10.13 — hai cot Decision Ledger con thieu.
         *
         * value_hash cho phep so hai revision ma khong phai so tung JSON;
         * ordinal giu lai thu tu extractor da sap, vi thu tu do la mot phan
         * cua ket qua chu khong phai chuyen tinh co cua database.
         */
        Schema::table(
            'canonical_decisions',
            function (Blueprint $table): void {
                $table
                    ->char('value_hash', 64)
                    ->default('')
                    ->after('relationship_ids');

                $table
                    ->unsignedInteger('ordinal')
                    ->default(0)
                    ->after('value_hash');
            }
        );

        $this->backfillDecisionColumns();
    }

    public function down(): void
    {
        Schema::table(
            'canonical_concept_revisions',
            function (Blueprint $table): void {
                $table->dropForeign(
                    'canonical_revision_parent_fk'
                );

                $table->dropColumn([
                    'parent_revision_id',
                    'revision_reason',
                ]);
            }
        );

        Schema::table(
            'canonical_concept_attempts',
            function (Blueprint $table): void {
                $table->dropIndex(
                    'canonical_attempt_usage_reference_idx'
                );

                $table->dropColumn('usage_reference');
            }
        );

        Schema::table(
            'canonical_decisions',
            function (Blueprint $table): void {
                $table->dropColumn([
                    'value_hash',
                    'ordinal',
                ]);
            }
        );
    }

    /**
     * Dong ledger cu duoc ghi truoc khi hai cot nay ton tai. Chung van la
     * du lieu that, nen tinh lai gia tri cho chung thay vi de trong.
     *
     * value_hash tinh dung cong thuc cua DecisionLedgerWriter; ordinal lay
     * theo target_path — dung thu tu ma CanonicalDecisionExtractor sap xep.
     */
    private function backfillDecisionColumns(): void
    {
        $revisionIds = DB::table('canonical_decisions')
            ->distinct()
            ->pluck('canonical_concept_revision_id');

        foreach ($revisionIds as $revisionId) {
            $rows = DB::table('canonical_decisions')
                ->where(
                    'canonical_concept_revision_id',
                    $revisionId
                )
                ->orderBy('target_path')
                ->get(['id', 'value']);

            foreach ($rows as $ordinal => $row) {
                DB::table('canonical_decisions')
                    ->where('id', $row->id)
                    ->update([
                        'value_hash' => hash(
                            'sha256',
                            (string) $row->value
                        ),

                        'ordinal' => $ordinal,
                    ]);
            }
        }
    }
};
