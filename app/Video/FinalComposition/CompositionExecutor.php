<?php

namespace App\Video\FinalComposition;

use App\Video\Media\FfmpegCapabilities;
use App\Video\Media\FfmpegRunner;
use Throwable;

/**
 * Chay mot luot ghep: kiem kha nang -> sao nguon -> dung graph -> encode -> do lai.
 *
 * Khong biet gi ve DB, ve `VideoFinal`, ve claim hay recovery. Muc nay chi chung
 * minh duong FFmpeg cua Laravel chay dung; phan so huu luot chay de muc sau.
 */
final class CompositionExecutor
{
    /** Bo loc va encoder graph nay THAT SU dung toi. */
    private const FILTERS = [
        'trim', 'setpts', 'scale', 'pad', 'setsar', 'fps', 'format', 'settb',
        'atrim', 'asetpts', 'aresample', 'aformat', 'apad', 'adelay', 'anullsrc',
        'concat', 'xfade', 'acrossfade', 'anull', 'null',
    ];

    private const ENCODERS = ['libx264', 'aac'];

    public function __construct(
        private readonly FfmpegRunner $ffmpeg,
        private readonly FfmpegCapabilities $capabilities,
        private readonly CompositionInputs $inputs,
        private readonly CompositionPlanBuilder $builder,
        private readonly CompositionOutputVerifier $verifier,
        private readonly string $composeRoot,
        private readonly int $budgetSeconds,
    ) {}

    /**
     * Ngan sach DA BI CHAN TREN boi `max_execution_time` (xem `AppServiceProvider`).
     *
     * Noi goi can so nay de mo dong ho tu dau luong render chu khong tu day; lay
     * thang `config()` o do la lay lai con so CHUA chan, va deadline se dai hon cai
     * han that su cua PHP.
     */
    public function budgetSeconds(): int
    {
        return $this->budgetSeconds;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $sources
     */
    /**
     * @param  Deadline|null  $deadline  ngan sach cua CA LUOT, tinh tu luc nguoi goi
     *                                   bat dau — khong phai tu luc vao day. Noi goi
     *                                   con truy van va bam nguon truoc do, va thoi
     *                                   gian ay cung an vao han cua PHP.
     */
    public function execute(
        array $manifest,
        array $sources,
        ?Deadline $deadline = null,
        ?CompositionRun $run = null,
    ): CompositionResult {
        $deadline = $deadline ?? Deadline::in($this->budgetSeconds);

        $workspace = null;

        // Danh sach giu lai duoc bo sung NGAY khi co thu can giu, chu khong doi ket
        // qua tra ve. Mot exception xay ra sau do — het gio, loi ghi, bat ky — khong
        // duoc phep keo theo viec xoa mat output da do xong va bang chung cua no.
        $keep = [];

        // Duong dan output DA di qua verifier, cua mot luot CO CHU. Day moi la moc
        // phan loai: co no thi luot nay la "chua doi soat", khong co thi la "that
        // bai". Lay moc la "receipt da ghi xong" se xep mot luot ghi receipt hong
        // thanh that bai, trong khi output van nam do va khong ai nhan lai nua.
        $verified = null;

        try {
            // Preflight va tao thu muc deu NAM TRONG day: `remaining()` nem khi het
            // gio, va `create()` nem khi khong tao duoc thu muc. De chung ngoai `try`
            // thi hai loi do thoat thang ra CLI duoi dang exception, trong khi moi
            // loi khac cua lop nay tra ve mot `CompositionResult::refused()`.
            $missing = $this->capabilities->missing(
                self::FILTERS, self::ENCODERS, static fn (): int => $deadline->remaining(),
            );

            if ($missing !== null) {
                return CompositionResult::refused(['ffmpeg thieu: '.$missing]);
            }

            $workspace = CompositionWorkspace::create($this->composeRoot, $run?->finalId);

            return $this->run($manifest, $sources, $workspace, $deadline, $run, $keep, $verified);
        } catch (CompositionRefused $e) {
            return $this->stopped([$e->getMessage()], $verified);
        } catch (Throwable $e) {
            // Khong phan loai duoc: KHONG doan bua no thuoc ve ffmpeg hay ve output.
            return $this->stopped([get_class($e).': '.$e->getMessage()], $verified);
        } finally {
            // Chi don khi thu muc DA tao duoc. Loi khi don duoc bo qua co chu dich:
            // no khong duoc thay the ket qua chinh.
            $workspace?->discard($keep);
        }
    }

    /**
     * Luot dung giua chung: da co output duoc xac minh hay chua?
     *
     * Co thi ket qua la `unresolved` — file van nam do, hang phai o lai `composing`
     * de doi phuc hoi con nhin thay. Receipt co ghi duoc hay khong KHONG doi duoc
     * cau tra loi nay: thieu receipt chi khien doi phuc hoi bao `needs_attention`,
     * chu khong bien mot output da do dat thanh mot luot that bai.
     *
     * @param  list<string>  $reasons
     */
    private function stopped(array $reasons, ?string $verified): CompositionResult
    {
        return $verified === null
            ? CompositionResult::refused($reasons)
            : CompositionResult::unresolved($reasons, $verified);
    }

    /**
     * In graph ma khong encode — VA di qua dung cac buoc kiem dau vao cua luot that.
     *
     * Goi thang builder o lenh CLI se bo qua gioi han thu muc goc, han muc byte va
     * ngan sach thoi gian: luc do `--dry-run` bao hop le cho mot dau vao ma luot
     * chay that se tu choi.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $sources
     * @return array{ok: bool, reasons: list<string>, frames: ?int, samples: ?int,
     *               graph: ?string, arguments: ?list<string>}
     */
    public function describe(array $manifest, array $sources): array
    {
        $deadline = Deadline::in($this->budgetSeconds);
        $workspace = null;

        try {
            $workspace = CompositionWorkspace::create($this->composeRoot);
            [$plan, $command] = $this->prepare($manifest, $sources, $workspace, $deadline);

            return [
                'ok' => true,
                'reasons' => [],
                'frames' => $plan->expectedFrames(),
                'samples' => $plan->expectedAudioSamples(),
                'graph' => $command->graph(),
                'arguments' => $command->arguments('<graph>', '<output>'),
            ];
        } catch (CompositionRefused $e) {
            return [
                'ok' => false, 'reasons' => [$e->getMessage()],
                'frames' => null, 'samples' => null, 'graph' => null, 'arguments' => null,
            ];
        } finally {
            $workspace?->discard();
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $sources
     * @return array{0: CompositionPlan, 1: CompositionCommand}
     */
    private function prepare(
        array $manifest,
        array $sources,
        CompositionWorkspace $workspace,
        Deadline $deadline,
    ): array {
        // Sao truoc, roi PROBE TREN BAN SAO: probe mot ban ma encode mot ban khac la
        // mot khe co that, va `E` tinh tu metadata nen mot file khac noi dung cung
        // thoi luong se di qua em.
        $copies = $this->inputs->materialise($sources, $workspace, $deadline);
        $plan = $this->builder->build($manifest, $copies, $deadline);

        return [$plan, new CompositionCommand($plan)];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $sources
     * @param  list<string>  $keep  duoc bo sung ngay khi co thu can giu, de `finally`
     *                              cua noi goi khong don mat no
     */
    private function run(
        array $manifest,
        array $sources,
        CompositionWorkspace $workspace,
        Deadline $deadline,
        ?CompositionRun $compositionRun,
        array &$keep,
        ?string &$verified,
    ): CompositionResult {
        // Sao truoc, roi PROBE TREN BAN SAO: probe mot ban ma encode mot ban khac la
        // mot khe co that, va `E` tinh tu metadata nen mot file khac noi dung cung
        // thoi luong se di qua em.
        [$plan, $command] = $this->prepare($manifest, $sources, $workspace, $deadline);
        $graphPath = $workspace->path('graph.txt');

        if (@file_put_contents($graphPath, $command->graph()) === false) {
            return CompositionResult::refused(['khong ghi duoc filter graph']);
        }

        $output = $workspace->output();
        $process = $this->ffmpeg->run($command->arguments($graphPath, $output), $deadline->remaining());

        if (! $process->successful) {
            return CompositionResult::refused([$process->tail()]);
        }

        $verdict = $this->verifier->inspect($plan->expectation(), $output, $workspace->path('probe.pcm'), $deadline);

        if ($verdict['reasons'] !== []) {
            return CompositionResult::refused($verdict['reasons']);
        }

        // TU DAY TRO DI output da qua verifier. Dat moc NGAY, truoc ca phep do dung
        // luong: moi loi phia sau deu xay ra khi tren dia da co mot file da dat, va
        // ha `failed` cho no la dong luon duong nhan lai.
        //
        // Duong CLI xem thu khong so huu hang nao nen khong co gi de doi soat — no
        // giu nguyen nghia `refused`.
        $keep = [$output];

        if ($compositionRun !== null) {
            $verified = $output;
        }

        $bytes = @filesize($output);
        $sha256 = ChunkedDigest::sha256($output, $deadline, 'file ket qua');

        if ($bytes === false || $sha256 === null) {
            return $this->stopped(['khong do duoc file da ghep'], $verified);
        }

        if ($deadline->expired()) {
            return $this->stopped(['het ngan sach thoi gian truoc khi ket thuc'], $verified);
        }

        if ($compositionRun !== null) {
            $receipt = $workspace->path(CompositionReceipt::FILE);

            // Nem o day — het dia, khong ghi duoc, `rename` hong — di ra `execute()`
            // va thanh `unresolved`, vi `$verified` da duoc dat tu truoc.
            CompositionReceipt::write($receipt, CompositionReceipt::build(
                $compositionRun, $plan->expectation(), $sha256, $bytes, $this->cuts($manifest, $plan),
            ));

            // Them NGAY, khong doi tra ve: tu day output va receipt di voi nhau, ke ca
            // khi duong tra ve khong con di toi noi.
            $keep[] = $receipt;
        }

        return CompositionResult::composed(
            path: $output,
            frames: (int) $verdict['frames'],
            audioSamples: (int) $verdict['samples'],
            bytes: $bytes,
            sha256: $sha256,
            timeline: $plan->timeline(),
        );
    }

    /**
     * Cat canh cua ban final: danh tinh clip lay tu MANIFEST da chot, vi tri lay tu
     * `CompositionPlan`.
     *
     * Khong lay `render_id` tu dau khac: manifest la thu duoc bam vao `manifest_hash`,
     * nen no la ban duy nhat doi chieu lai duoc.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<array{render_id: string, sequence_no: int, start_ms: int, duration_ms: int}>
     *
     * @throws CompositionRefused
     */
    private function cuts(array $manifest, CompositionPlan $plan): array
    {
        $entries = (array) ($manifest['clips'] ?? []);
        $timeline = $plan->timeline();
        $cuts = [];

        foreach ($timeline as $i => $row) {
            $renderId = $entries[$i]['render_id'] ?? null;

            if (! is_string($renderId) || $renderId === '') {
                throw new CompositionRefused(sprintf('clip %d trong manifest khong co render_id', $i + 1));
            }

            $cuts[] = [
                'render_id' => $renderId,
                'sequence_no' => $i + 1,
                'start_ms' => $row['start_ms'],
                'duration_ms' => $row['duration_ms'],
            ];
        }

        return $cuts;
    }
}
