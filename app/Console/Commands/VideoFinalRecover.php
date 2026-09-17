<?php

namespace App\Console\Commands;

use App\Models\VideoFinal;
use App\Services\Video\FinalCompositionReconciler;
use Illuminate\Console\Command;

/**
 * Nhan lai cac ban final da ghep xong nhung chua kip vao DB.
 *
 * Chay DONG BO, khong queue. Lenh nay khong ghep lai gi ca: no doc receipt tren dia,
 * do lai output bang dung phep do cua luot chay that, roi chot hang.
 *
 * KHONG XOA GI. Mot luot khong nhan lai duoc se duoc bao ra kem ly do, va viec xoa
 * la quyet dinh cua nguoi doc ly do do.
 */
class VideoFinalRecover extends Command
{
    protected $signature = 'video:final-recover
        {--final= : chi doi soat mot final ID}
        {--dry-run : chi bao ket qua doi soat, khong chot hang nao}';

    protected $description = 'Doi soat va nhan lai cac ban final da ghep xong nhung chua luu duoc';

    public function handle(FinalCompositionReconciler $reconciler): int
    {
        $pending = $reconciler->pending($this->option('final'));

        if ($pending->isEmpty()) {
            $this->info('khong co luot ghep nao dang cho doi soat');

            return self::SUCCESS;
        }

        $apply = ! $this->option('dry-run');
        $rows = [];
        $attention = 0;

        foreach ($pending as $final) {
            $verdict = $reconciler->reconcile($final, $apply);

            if ($verdict['state'] === 'needs_attention') {
                $attention++;
            }

            $rows[] = [
                (string) $final->id,
                (string) $final->session_id,
                $verdict['state'],
                implode(' | ', $verdict['reasons']) ?: '-',
            ];
        }

        $this->table(['final', 'session', 'ket luan', 'ly do'], $rows);

        if ($attention > 0) {
            $this->warn(sprintf('%d luot can xu ly tay — khong luot nao bi xoa', $attention));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
