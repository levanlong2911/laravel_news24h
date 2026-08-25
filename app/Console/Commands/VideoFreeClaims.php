<?php

namespace App\Console\Commands;

use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use Illuminate\Console\Command;

/**
 * Go claim dang giu de khong phai doi het lease khi debug.
 *
 * Vi sao can: `dd()` giet script giua chung, nen `finishSucceeded()` khong chay
 * va `claim_token` nam lai het 180 giay (`PlanningStageStore::LEASE_SECONDS`).
 * Trong khoang do moi lan bam chi nhan "dang co mot luot chay" — code khong bao
 * gio toi duoc cho dat `dd()`.
 *
 * Chi cham hang DANG GIU CLAIM. Hang da `succeeded` co `claim_token = null` nen
 * lenh nay khong the xoa mot ket qua da tra tien.
 */
class VideoFreeClaims extends Command
{
    protected $signature = 'video:free-claims
                            {--project= : chi go claim cua mot du an (khop tien to id)}
                            {--stage= : chi go mot stage (inspiration|concept)}
                            {--force : cho phep chay ngoai moi truong local}';

    protected $description = 'Release in-flight planning-stage claims so a debug run does not have to wait out the lease';

    public function handle(): int
    {
        // Cat ngang mot luot dang chay that la lam mat ket qua ma nguoi dung da
        // tra tien cho no. Tren may dev thi khong ai chay that.
        if (! app()->environment(['local', 'testing']) && ! $this->option('force')) {
            $this->error('Chi chay o moi truong local/testing. Dung --force neu that su can.');

            return self::FAILURE;
        }

        $query = VideoPlanningStage::query()->whereNotNull('claim_token');

        if ($project = $this->option('project')) {
            $query->where('project_id', 'like', $project.'%');
        }

        if ($stage = $this->option('stage')) {
            $query->where('stage', $stage);
        }

        $rows = $query->get(['id', 'project_id', 'stage', 'planning_revision']);

        if ($rows->isEmpty()) {
            $this->info('No claim is being held.');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $this->line(sprintf(
                '  %s  %-12s rev%d',
                substr($row->project_id, 0, 8), $row->stage, $row->planning_revision,
            ));
        }

        // Ghi ly do, dung nhu `releaseClaim()`: mot hang `failed` khong noi vi sao
        // la mot cau hoi khong tra loi duoc sau vai thang.
        $freed = VideoPlanningStage::query()
            ->whereKey($rows->pluck('id'))
            ->update([
                'status' => VideoPlanningStageStatus::FAILED->value,
                'error_message' => 'Claim released by video:free-claims (debug)',
                'claim_token' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
                'finished_at' => now(),
            ]);

        $this->info("Released {$freed} claim(s).");

        return self::SUCCESS;
    }
}
