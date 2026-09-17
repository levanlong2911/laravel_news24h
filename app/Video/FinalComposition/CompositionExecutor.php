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
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $sources
     */
    public function execute(array $manifest, array $sources): CompositionResult
    {
        // Dat ngan sach TRUOC khi cham vao bat ky file nao: sao chep va probe cung
        // tieu thoi gian, va mot dong ho bat dau o giua luot khong dung duoc gi.
        $deadline = Deadline::in($this->budgetSeconds);

        $workspace = null;
        $keep = [];

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

            $workspace = CompositionWorkspace::create($this->composeRoot);
            $result = $this->run($manifest, $sources, $workspace, $deadline);

            if ($result->successful && $result->path !== null) {
                $keep[] = $result->path;
            }

            return $result;
        } catch (CompositionRefused $e) {
            return CompositionResult::refused([$e->getMessage()]);
        } catch (Throwable $e) {
            // Khong phan loai duoc: KHONG doan bua no thuoc ve ffmpeg hay ve output.
            return CompositionResult::refused([get_class($e).': '.$e->getMessage()]);
        } finally {
            // Chi don khi thu muc DA tao duoc. Loi khi don duoc bo qua co chu dich:
            // no khong duoc thay the ket qua chinh.
            $workspace?->discard($keep);
        }
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
     * Bam theo KHOI, kiem gio giua chung.
     *
     * `hash_file()` tren mot file hang tram MB chay toi cung roi moi tra ve — kiem
     * het gio sau do la biet muon. Vong nay dung ngay khi ngan sach can.
     *
     * @throws CompositionRefused
     */
    private function hash(string $path, Deadline $deadline): ?string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $context = hash_init('sha256');

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 1 << 20);

                if ($chunk === false) {
                    return null;
                }

                if ($chunk !== '') {
                    hash_update($context, $chunk);
                }

                if ($deadline->expired()) {
                    throw new CompositionRefused('het ngan sach thoi gian khi dang bam file ket qua');
                }
            }
        } finally {
            fclose($handle);
        }

        return hash_final($context);
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
     */
    private function run(
        array $manifest,
        array $sources,
        CompositionWorkspace $workspace,
        Deadline $deadline,
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
        $run = $this->ffmpeg->run($command->arguments($graphPath, $output), $deadline->remaining());

        if (! $run->successful) {
            return CompositionResult::refused([$run->tail()]);
        }

        $verdict = $this->verifier->inspect($plan, $output, $workspace->path('probe.pcm'), $deadline);

        if ($verdict['reasons'] !== []) {
            return CompositionResult::refused($verdict['reasons']);
        }

        $bytes = @filesize($output);
        $sha256 = $this->hash($output, $deadline);

        if ($bytes === false || $sha256 === null) {
            return CompositionResult::refused(['khong do duoc file da ghep']);
        }

        if ($deadline->expired()) {
            return CompositionResult::refused(['het ngan sach thoi gian truoc khi ket thuc']);
        }

        return CompositionResult::composed(
            path: $output,
            frames: (int) $verdict['frames'],
            audioSamples: (int) $verdict['samples'],
            bytes: $bytes,
            sha256: $sha256,
        );
    }
}
