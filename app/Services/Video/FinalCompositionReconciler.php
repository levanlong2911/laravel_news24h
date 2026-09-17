<?php

namespace App\Services\Video;

use App\Models\VideoFinal;
use App\Models\VideoFinalRender;
use App\Video\FinalComposition\ChunkedDigest;
use App\Video\FinalComposition\CompositionExpectation;
use App\Video\FinalComposition\CompositionOutputVerifier;
use App\Video\FinalComposition\CompositionProfile;
use App\Video\FinalComposition\CompositionReceipt;
use App\Video\FinalComposition\CompositionRefused;
use App\Video\FinalComposition\CompositionTimeline;
use App\Video\FinalComposition\CompositionWorkspace;
use App\Video\FinalComposition\Deadline;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Nhan lai mot output da ghep xong nhung chua kip vao DB.
 *
 * Giua luc verifier noi "dat" va luc hang thanh `ready` co mot khoang ma tien trinh
 * co the chet. Lop nay doc bang chung tren dia, do lai output, va chot hang — KHONG
 * ghep lai. Ghep lai la tra tien lan hai cho mot viec da xong.
 *
 * Va lop nay KHONG XOA GI. Doc file that bai, het gio, hay ffprobe khong chay duoc
 * deu khong chung minh output la rac; hash lech cung khong noi duoc ben nao hong.
 * Xoa la mot thao tac rieng, do nguoi quyet dinh sau khi doc ly do.
 *
 * CHUA DUOC KIEM BANG CHAY THAT, tinh den 2026-09-17:
 *
 *   - `reconcile()` voi `$apply = true` — tuc la duong phuc hoi GHI vao DB. Cac phep
 *     kiem deu chay o che do khong ghi. Nhanh thanh cong cua `complete()` thi da chay
 *     that (duong render binh thuong), va cuts dung tu manifest da duoc doi chieu,
 *     nen cho chua kiem la buoc noi giua hai thu do.
 *   - Nhanh thua CAS (`$claimed !== 1`).
 *   - Rollback khi loi xay ra SAU khi da ghi mot cut.
 *   - Hai request cung cap luot mot luc, duoi khoa hang session.
 *
 * Ba cai sau deu hong ve phia an toan: giu file, giu hang, khong ghi gi. Muon kiem
 * thi phai co database kiem thu rieng cung loai production — `DatabaseTransactions`
 * cua bo test hien tai khong cho hai tien trinh nhin thay du lieu cua nhau.
 */
final class FinalCompositionReconciler
{
    public function __construct(
        private readonly CompositionOutputVerifier $verifier,
        private readonly string $composeRoot,
        private readonly int $budgetSeconds,
    ) {}

    /**
     * Chot mot ban final: `ready` + cac cat canh, trong CUNG mot transaction.
     *
     * Duong chay that va doi phuc hoi deu goi day. Hai duong tu viet phep hoan tat
     * cua rieng minh la hai co hoi de mot ban final co `ready` ma khong co cut nao,
     * hoac co cut ghi hai lan.
     *
     * CAS: dung hang, dung manifest, CON `composing`, va CHUA co duong dan. Hang
     * `failed` khong tu dong duoc nhan lai ket qua — mot luot da bi ket luan hong thi
     * phai co nguoi doc ly do truoc.
     *
     * @param  list<array{render_id: string, sequence_no: int, start_ms: int, duration_ms: int}>  $cuts
     *
     * @throws RuntimeException khi khong gianh duoc quyen hoan tat
     */
    public function complete(VideoFinal $final, array $cuts, string $absolutePath, int $frames): VideoFinal
    {
        $root = realpath($this->composeRoot);
        $relative = $root === false
            ? $absolutePath
            : str_replace('\\', '/', substr($absolutePath, strlen(rtrim($root, '/\\')) + 1));

        return DB::transaction(function () use ($final, $cuts, $relative, $frames): VideoFinal {
            $claimed = VideoFinal::query()
                ->whereKey($final->id)
                ->where('status', 'composing')
                ->where('manifest_hash', (string) $final->manifest_hash)
                ->whereNull('video_path')
                ->update([
                    'status' => 'ready',
                    'video_path' => $relative,
                    'duration_seconds' => (int) round(
                        $frames / (int) data_get($final->plan_json, 'output.fps', 24),
                    ),
                    // Ghep chay bang ffmpeg CUC BO nen khong ton dong nao. Cong chi phi
                    // cua cac clip vao day la dem lai mot khoan da tra tu truoc, va ghep
                    // lai lan thu hai se dem no lan nua.
                    'cost_total' => 0,
                ]);

            if ($claimed !== 1) {
                throw new RuntimeException('luot ghep khong con o trang thai nhan duoc ket qua');
            }

            foreach ($cuts as $cut) {
                VideoFinalRender::query()->create([
                    'final_id' => $final->id,
                    'render_id' => $cut['render_id'],
                    'sequence_no' => $cut['sequence_no'],
                    'start_ms' => $cut['start_ms'],
                    'duration_ms' => $cut['duration_ms'],
                ]);
            }

            return $final->refresh();
        });
    }

    /**
     * Cac luot dang cho doi soat: `composing`, chua co duong dan.
     *
     * Khong loc theo thoi gian o day — mot luot vua bat dau cung nam trong danh sach
     * nay, va lenh goi den se thay no chua co output. Loc theo "qua han" o truy van
     * la tu dat mot nguong thu hai ben canh nguong cua `blockingComposition()`.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, VideoFinal>
     */
    public function pending(?string $finalId = null): \Illuminate\Database\Eloquent\Collection
    {
        return VideoFinal::query()
            ->where('status', 'composing')
            ->whereNull('video_path')
            ->when($finalId !== null, fn ($query) => $query->whereKey($finalId))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return array{state: string, reasons: list<string>, final?: VideoFinal}
     *         state: `recovered` | `pending` | `needs_attention`
     */
    public function reconcile(VideoFinal $final, bool $apply = true, ?Deadline $deadline = null): array
    {
        $deadline = $deadline ?? Deadline::in($this->budgetSeconds);

        try {
            return $this->examine($final, $apply, $deadline);
        } catch (CompositionRefused $e) {
            return ['state' => 'needs_attention', 'reasons' => [$e->getMessage()]];
        } catch (Throwable $e) {
            return ['state' => 'needs_attention', 'reasons' => [get_class($e).': '.$e->getMessage()]];
        }
    }

    /**
     * @return array{state: string, reasons: list<string>, final?: VideoFinal}
     */
    private function examine(VideoFinal $final, bool $apply, Deadline $deadline): array
    {
        $directory = $this->runDirectory($final);

        if ($directory === null) {
            return ['state' => 'pending', 'reasons' => ['chua co thu muc luot chay']];
        }

        $output = $directory.DIRECTORY_SEPARATOR.CompositionReceipt::OUTPUT_NAME;

        if (! is_file($output)) {
            return ['state' => 'pending', 'reasons' => ['chua co file ket qua trong thu muc luot chay']];
        }

        $receipt = CompositionReceipt::read($directory.DIRECTORY_SEPARATOR.CompositionReceipt::FILE);

        // KHOANG TRONG truoc receipt: tien trinh chet sau khi encode nhung truoc khi
        // ghi bang chung. Co `final.mp4` KHONG chung minh no da di qua verifier — no
        // co the la file ffmpeg dang ghi do dang luc bi giet.
        //
        // Khong nhan, cung khong xoa. Bao ra de nguoi xem xet.
        if ($receipt === null) {
            return ['state' => 'needs_attention', 'reasons' => [
                'co file ket qua nhung khong co receipt doc duoc — khong ket luan duoc no da do dat hay chua',
            ]];
        }

        $reasons = $this->identity($final, $receipt);

        if ($reasons !== []) {
            return ['state' => 'needs_attention', 'reasons' => $reasons];
        }

        // NGUON KY VONG DOC LAP: dung lai dong thoi gian tu manifest da chot — thu da
        // di vao `manifest_hash` — chu khong lay tu receipt. Receipt chi duoc dung de
        // DOI CHIEU. Lay moc thoi gian tu receipt roi kiem no bang chinh no la mot
        // phep kiem khong kiem gi: sua moc bat dau cua clip giua van di qua duoc.
        $line = CompositionTimeline::fromManifest((array) $final->plan_json);
        $expected = $this->expectation($final, $receipt, $line);
        $cuts = $this->cuts($final, $receipt, $line);
        $reasons = $this->matchesOutput($output, $receipt, $deadline);

        if ($reasons !== []) {
            return ['state' => 'needs_attention', 'reasons' => $reasons];
        }

        $reasons = $this->verify($output, $expected, $deadline);

        if ($reasons !== []) {
            return ['state' => 'needs_attention', 'reasons' => $reasons];
        }

        if (! $apply) {
            return ['state' => 'recovered', 'reasons' => ['(thu) du dieu kien nhan lai']];
        }

        return ['state' => 'recovered', 'reasons' => [], 'final' => $this->complete(
            $final, $cuts, $output, $expected->frames,
        )];
    }

    /**
     * Receipt nay co phai cua dung luot nay khong.
     *
     * @param  array<string, mixed>  $receipt
     * @return list<string>
     */
    private function identity(VideoFinal $final, array $receipt): array
    {
        $reasons = [];

        if (($receipt['version'] ?? null) !== CompositionReceipt::VERSION) {
            $reasons[] = sprintf(
                'receipt phien ban %s, doi phuc hoi nay chi doc duoc phien ban %d',
                json_encode($receipt['version'] ?? null), CompositionReceipt::VERSION,
            );
        }

        if (($receipt['engine'] ?? null) !== VideoFinal::ENGINE_LARAVEL) {
            $reasons[] = 'receipt khong phai cua duong ghep Laravel';
        }

        if (($receipt['final_id'] ?? null) !== (string) $final->id) {
            $reasons[] = 'receipt mang final ID khac';
        }

        $hash = $receipt['manifest_hash'] ?? null;

        if (! is_string($hash) || ! hash_equals((string) $final->manifest_hash, $hash)) {
            $reasons[] = 'receipt mang manifest hash khac voi ban da chot';
        }

        // Ten file, KHONG phai duong dan. Mot receipt bi sua thanh `../../..` se cho
        // doi phuc hoi doc va chot mot file bat ky tren dia.
        if (($receipt['output']['name'] ?? null) !== CompositionReceipt::OUTPUT_NAME) {
            $reasons[] = 'receipt tro toi mot ten file khac '.CompositionReceipt::OUTPUT_NAME;
        }

        return $reasons;
    }

    /**
     * Cat canh dung tu MANIFEST DA CHOT; receipt chi duoc doi chieu.
     *
     * Moc thoi gian ghi vao `video_final_renders` lay tu `$line` — dung lai tu manifest
     * — chu khong lay tu receipt. Hash video khop chi chung minh file dung; no khong
     * noi gi ve viec receipt khai dung nhung clip nao, o nhung moc nao.
     *
     * @param  array<string, mixed>  $receipt
     * @return list<array{render_id: string, sequence_no: int, start_ms: int, duration_ms: int}>
     *
     * @throws CompositionRefused
     */
    private function cuts(VideoFinal $final, array $receipt, CompositionTimeline $line): array
    {
        $frozen = array_values((array) data_get($final->plan_json, 'clips', []));
        $claimed = array_values((array) ($receipt['clips'] ?? []));
        $rows = $line->rows();

        if (count($claimed) !== count($frozen) || $claimed === []) {
            throw new CompositionRefused(sprintf(
                'receipt khai %d clip, manifest da chot co %d', count($claimed), count($frozen),
            ));
        }

        $cuts = [];

        foreach ($frozen as $i => $entry) {
            $cut = (array) ($claimed[$i] ?? []);
            $renderId = (string) (is_array($entry) ? ($entry['render_id'] ?? '') : '');

            if ($renderId === '') {
                throw new CompositionRefused(sprintf('clip %d: manifest da chot khong co render_id', $i + 1));
            }

            if ((string) ($cut['render_id'] ?? '') !== $renderId) {
                throw new CompositionRefused(sprintf(
                    'clip %d: receipt khai render_id khac voi manifest da chot', $i + 1,
                ));
            }

            if ((int) ($cut['sequence_no'] ?? 0) !== $i + 1) {
                throw new CompositionRefused(sprintf('clip %d: receipt khai sai thu tu', $i + 1));
            }

            // Doi chieu voi nguon doc lap, khong voi chinh receipt.
            if ((int) ($cut['start_ms'] ?? -1) !== $rows[$i]['start_ms']
                || (int) ($cut['duration_ms'] ?? -1) !== $rows[$i]['duration_ms']) {
                throw new CompositionRefused(sprintf(
                    'clip %d: receipt khai moc %d..+%d, manifest da chot suy ra %d..+%d',
                    $i + 1,
                    (int) ($cut['start_ms'] ?? -1), (int) ($cut['duration_ms'] ?? -1),
                    $rows[$i]['start_ms'], $rows[$i]['duration_ms'],
                ));
            }

            $cuts[] = [
                'render_id' => $renderId,
                'sequence_no' => $i + 1,
                'start_ms' => $rows[$i]['start_ms'],
                'duration_ms' => $rows[$i]['duration_ms'],
            ];
        }

        return $cuts;
    }

    /**
     * Ky vong dung tu MANIFEST DA CHOT; receipt chi duoc doi chieu.
     *
     * @param  array<string, mixed>  $receipt
     *
     * @throws CompositionRefused
     */
    private function expectation(VideoFinal $final, array $receipt, CompositionTimeline $line): CompositionExpectation
    {
        $output = (array) data_get($final->plan_json, 'output', []);

        foreach (['width', 'height', 'fps', 'crf'] as $key) {
            if (! is_int($output[$key] ?? null) || $output[$key] <= 0) {
                throw new CompositionRefused('manifest da chot thieu `output.'.$key.'` hop le');
            }
        }

        $expected = new CompositionExpectation(
            new CompositionProfile($output['width'], $output['height'], $output['fps'], $output['crf']),
            $line->total(),
            $line->audioSamples(),
        );

        $claimed = (array) ($receipt['expected'] ?? []);
        $mustMatch = [
            'width' => $expected->profile->width,
            'height' => $expected->profile->height,
            'fps' => $expected->profile->fps,
            'crf' => $expected->profile->crf,
            'sample_rate' => $expected->profile->sampleRate,
            'channels' => $expected->profile->channels,
            'frames' => $expected->frames,
            'audio_samples' => $expected->audioSamples,
        ];

        foreach ($mustMatch as $key => $want) {
            if (($claimed[$key] ?? null) !== $want) {
                throw new CompositionRefused(sprintf(
                    'receipt khai %s=%s, manifest da chot suy ra %d',
                    $key, json_encode($claimed[$key] ?? null), $want,
                ));
            }
        }

        return $expected;
    }

    /**
     * File tren dia co con dung la file da duoc do khong.
     *
     * @param  array<string, mixed>  $receipt
     * @return list<string>
     */
    private function matchesOutput(string $output, array $receipt, Deadline $deadline): array
    {
        $bytes = @filesize($output);
        $claimed = $receipt['output'] ?? [];

        if (! is_int($claimed['bytes'] ?? null) || ! is_string($claimed['sha256'] ?? null)) {
            return ['receipt thieu kich thuoc hoac sha256 cua output'];
        }

        if ($bytes === false) {
            return ['khong doc duoc kich thuoc file ket qua'];
        }

        if ($bytes !== $claimed['bytes']) {
            return [sprintf('file ket qua %d byte, receipt ghi %d', $bytes, $claimed['bytes'])];
        }

        $actual = ChunkedDigest::sha256($output, $deadline, 'file ket qua khi doi soat');

        if ($actual === null) {
            return ['khong bam duoc file ket qua'];
        }

        if (! hash_equals($claimed['sha256'], $actual)) {
            return [sprintf(
                'file ket qua da doi noi dung: receipt ghi %s, doc duoc %s',
                substr($claimed['sha256'], 0, 12), substr($actual, 0, 12),
            )];
        }

        return [];
    }

    /**
     * Do lai output bang DUNG phep do cua luot chay that.
     *
     * Hash khop chi chung minh file khop receipt; no khong chung minh file dat yeu
     * cau dung phim. Chay lai verifier moi tra loi duoc cau thu hai.
     *
     * @return list<string>
     */
    private function verify(string $output, CompositionExpectation $expected, Deadline $deadline): array
    {
        $workspace = CompositionWorkspace::create($this->composeRoot);

        try {
            return $this->verifier->inspect($expected, $output, $workspace->path('probe.pcm'), $deadline)['reasons'];
        } finally {
            $workspace->discard();
        }
    }

    /**
     * Thu muc luot chay, suy tu final ID, chan trong thu muc goc.
     */
    private function runDirectory(VideoFinal $final): ?string
    {
        $root = realpath($this->composeRoot);

        if ($root === false) {
            return null;
        }

        $root = rtrim($root, '/\\').DIRECTORY_SEPARATOR;
        $directory = realpath($root.(string) $final->id);

        return $directory !== false && str_starts_with($directory, $root) ? $directory : null;
    }
}
