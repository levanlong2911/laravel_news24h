<?php

namespace App\Video\FinalComposition;

use App\Video\Media\MediaProbe;

/**
 * Doc manifest, do nguon, va tu choi moi thu khong hop le.
 *
 * Moi luat cua muc 2 song o day. `CompositionPlan` khong tu kiem gi: mot ke hoach
 * ton tai la mot ke hoach da hop le, va cho duy nhat dung no ra la lop nay.
 */
final class CompositionPlanBuilder
{
    private const FPS = [24, 25, 30];

    /** Kich thuoc cua PRODUCTION. Fixture muon co nao khac thi tu truyen vao. */
    public const SIZES = ['1920x1080', '1280x720', '1080x1920', '720x1280'];

    private const CRF_MIN = 16;

    private const CRF_MAX = 28;

    /**
     * Ngan sach tam khi nguoi goi khong dua deadline (test goi thang builder).
     *
     * Khong dung `hash_file()` cho truong hop nay: hai duong bam khac nhau la hai
     * hanh vi khac nhau, va cai chay trong test se khong con la cai chay that.
     */
    private const DIGEST_FALLBACK_SECONDS = 300;

    /**
     * @param  list<string>  $sizes  noi rong danh sach nay tu trong lop la de mot co
     *                               chi dung cho fixture chay duoc o production
     */
    public function __construct(
        private readonly MediaProbe $probe,
        private readonly array $sizes = self::SIZES,
        private readonly int $maxClips = 60,
        private readonly int $maxOutputFrames = 90000,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $paths  duong dan da duoc nguoi goi xac thuc
     *
     * @throws CompositionRefused
     */
    public function build(array $manifest, array $paths, ?Deadline $deadline = null): CompositionPlan
    {
        $profile = $this->profile($this->arrayAt($manifest, 'output'));
        $entries = $this->clipEntries($manifest);

        if (count($entries) !== count($paths)) {
            throw new CompositionRefused(sprintf(
                'manifest co %d clip nhung nhan duoc %d duong dan', count($entries), count($paths),
            ));
        }

        $clips = [];
        $transitions = [];
        $lastIndex = count($entries) - 1;

        foreach ($entries as $i => $entry) {
            $clips[] = $this->clip($entry, $paths[$i], $profile, $i, $deadline);

            if ($i < $lastIndex) {
                $transitions[] = $this->transition($entry, $i);

                continue;
            }

            if (array_key_exists('transition_after', $entry)) {
                // Bo qua im lang la nhan mot ban ke hoach ma nguoi viet hieu khac he
                // thong: ho tuong con mot chuyen canh nua o cuoi phim.
                throw new CompositionRefused('clip cuoi khong duoc co `transition_after`');
            }
        }

        $plan = new CompositionPlan($clips, $transitions, $profile);

        $this->assertOverlapsFit($plan);

        if ($plan->expectedFrames() > $this->maxOutputFrames) {
            throw new CompositionRefused(sprintf(
                'timeline %d khung, vuot han muc %d', $plan->expectedFrames(), $this->maxOutputFrames,
            ));
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array<string, mixed>>
     */
    private function clipEntries(array $manifest): array
    {
        $raw = $manifest['clips'] ?? null;

        if (! is_array($raw) || $raw === [] || ! array_is_list($raw)) {
            throw new CompositionRefused('`clips` phai la mot danh sach khong rong');
        }

        foreach ($raw as $i => $entry) {
            if (! is_array($entry)) {
                throw new CompositionRefused(sprintf('clip %d khong phai mot doi tuong', $i + 1));
            }
        }

        if (count($raw) > $this->maxClips) {
            throw new CompositionRefused(sprintf('qua %d clip', $this->maxClips));
        }

        return array_values($raw);
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function profile(array $output): CompositionProfile
    {
        $width = $this->integer($output, 'width');
        $height = $this->integer($output, 'height');
        $fps = $this->integer($output, 'fps');
        $crf = $this->integer($output, 'crf');

        if (! in_array($width.'x'.$height, $this->sizes, true)) {
            throw new CompositionRefused('kich thuoc ngoai danh sach cho phep: '.$width.'x'.$height);
        }

        if (! in_array($fps, self::FPS, true)) {
            throw new CompositionRefused('fps ngoai danh sach cho phep: '.$fps);
        }

        if ($crf < self::CRF_MIN || $crf > self::CRF_MAX) {
            throw new CompositionRefused(sprintf(
                'crf %d ngoai khoang %d..%d', $crf, self::CRF_MIN, self::CRF_MAX,
            ));
        }

        return new CompositionProfile($width, $height, $fps, $crf);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function clip(
        array $entry,
        string $path,
        CompositionProfile $profile,
        int $i,
        ?Deadline $deadline,
    ): CompositionClip {
        $label = 'clip '.($i + 1);

        // Doi chieu BAN SAO voi hash da chot, TRUOC khi probe.
        //
        // Hash ky vong den tu `video_renders.primary_artifact_hash` — thu da ghi lai
        // luc render xong — chu khong phai tu file hien tai. Bam lai file ngay truoc
        // khi ghep la hop thuc hoa mot file co the da bi thay, vi ca hai ve cua phep
        // so deu doc cung mot noi dung.
        //
        // Thieu hash thi TU CHOI, khong bo qua: mot manifest khong co hash la mot ban
        // final khong doi chieu lai duoc voi thu da thuc su ghep.
        $expected = $entry['sha256'] ?? null;

        if (! is_string($expected) || preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
            throw new CompositionRefused($label.': thieu sha256 hop le trong manifest');
        }

        $actual = ChunkedDigest::sha256($path, $deadline ?? Deadline::in(self::DIGEST_FALLBACK_SECONDS), 'ban sao '.$label);

        if ($actual === null || ! hash_equals($expected, $actual)) {
            throw new CompositionRefused(sprintf(
                '%s: noi dung da doi giua luc chot va luc sao chep (cho %s, doc duoc %s)',
                $label, substr($expected, 0, 12), $actual === null ? 'khong bam duoc' : substr($actual, 0, 12),
            ));
        }

        // Probe tung clip an vao ngan sach chung cua ca luot, khong co han muc rieng.
        $measured = $this->probe->inspect($path, $deadline?->remaining());

        if (($measured['ok'] ?? false) !== true) {
            throw new CompositionRefused($label.' khong doc duoc: '.($measured['error'] ?? 'khong ro'));
        }

        if (($measured['video_count'] ?? 0) !== 1) {
            throw new CompositionRefused(sprintf(
                '%s co %d luong hinh — muc nay chi nhan dung mot', $label, $measured['video_count'] ?? 0,
            ));
        }

        if (($measured['audio_count'] ?? 0) > 1) {
            throw new CompositionRefused(sprintf(
                '%s co %d luong tieng — khong biet phai lay luong nao', $label, $measured['audio_count'],
            ));
        }

        $video = (array) $measured['video'];

        // Do dai cua LUONG HINH, khong phai cua container: `format.duration` bam theo
        // luong dai hon, nen mot file hinh 4s tieng 6s se cho phep cat toi giay 6.
        $sourceMs = $this->durationMilliseconds($video);

        if ($sourceMs === null || $sourceMs <= 0) {
            throw new CompositionRefused(
                $label.': luong hinh khong khai bao do dai — khong the kiem khoang cat',
            );
        }

        $start = $this->optionalInteger($entry, 'trim_start_ms', 0);
        $length = $this->optionalInteger($entry, 'duration_ms', $sourceMs - $start);

        if ($start < 0) {
            throw new CompositionRefused($label.': `trim_start_ms` am');
        }

        if ($length <= 0) {
            throw new CompositionRefused($label.': `duration_ms` phai lon hon 0');
        }

        if ($start + $length > $sourceMs) {
            throw new CompositionRefused(sprintf(
                '%s: cat %d..%dms nhung luong hinh chi dai %dms', $label, $start, $start + $length, $sourceMs,
            ));
        }

        // Quy ve khung DAU RA dung MOT lan, o day.
        $frames = (int) round($length * $profile->fps / 1000);

        if ($frames < 1) {
            throw new CompositionRefused(sprintf(
                '%s: %dms ngan hon mot khung o %d fps', $label, $length, $profile->fps,
            ));
        }

        $hasAudio = ($measured['audio_count'] ?? 0) === 1;
        $width = (int) $measured['width'];
        $height = (int) $measured['height'];
        $content = $this->contentSize($video, $width, $height, $profile, $label);

        return new CompositionClip(
            path: $path,
            trimStartMs: $start,
            durationMs: $length,
            frames: $frames,
            width: $width,
            height: $height,
            hasAudio: $hasAudio,
            audioOffsetMs: $hasAudio ? $this->audioOffsetMs($video, (array) $measured['audio'], $label) : 0,
            contentWidth: $content['contentWidth'],
            contentHeight: $content['contentHeight'],
        );
    }

    /**
     * Kich thuoc noi dung sau khi da vua vao khung dau ra, giu TI LE HIEN THI.
     *
     * Ti le hien thi = (be ngang x SAR) / be cao. Bo qua SAR la bop meo hinh; vuong
     * hoa o mot khung trung gian roi scale lan nua la hai lan noi suy va mot khung
     * co the lon hon han dau ra. Tinh thang toi dich giai ca hai.
     *
     * @param  array<string, mixed>  $video
     * @return array{contentWidth: int, contentHeight: int}
     */
    private function contentSize(
        array $video,
        int $width,
        int $height,
        CompositionProfile $profile,
        string $label,
    ): array {
        [$sarNum, $sarDen] = $this->sampleAspect($video, $label);

        $displayWidth = $width * $sarNum / $sarDen;
        $factor = min($profile->width / $displayWidth, $profile->height / $height);

        // Lam tron ve so CHAN, DUNG MOT LAN, o kich thuoc cuoi: h264 voi yuv420p lay
        // mau mau theo khoi 2x2, nen be le bi bo giai ma tu xu ly theo cach rieng.
        $contentWidth = max(2, (int) round($displayWidth * $factor / 2) * 2);
        $contentHeight = max(2, (int) round($height * $factor / 2) * 2);

        // Lam tron len co the vuot khung dau ra mot diem anh; `pad` se tu choi mot
        // kich thuoc am.
        return [
            'contentWidth' => min($contentWidth, $profile->width),
            'contentHeight' => min($contentHeight, $profile->height),
        ];
    }

    /**
     * Ti le pixel dang `[tu so, mau so]`.
     *
     * Khong khai bao thi la `1:1` — day la hanh vi DA DINH NGHIA cua ffmpeg, khong
     * phai suy doan cua ta. Ca ba clip Veo that deu roi vao nhanh nay.
     *
     * @param  array<string, mixed>  $video
     * @return array{0: int, 1: int}
     */
    private function sampleAspect(array $video, string $label): array
    {
        $raw = $video['sample_aspect_ratio'] ?? null;

        if (! is_string($raw) || $raw === '' || $raw === 'N/A') {
            return [1, 1];
        }

        if (preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/', $raw, $m) !== 1) {
            throw new CompositionRefused($label.': khong doc duoc ti le pixel "'.$raw.'"');
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /**
     * Tieng bat dau sau hinh bao nhieu mili giay.
     *
     * Thieu mot trong hai moc thi TU CHOI, khong tra 0. Tra 0 la khang dinh "hai
     * luong dong bo" — mot gia dinh, dung cai ma graph sau do se khac ghi vao file
     * bang cach rebase doc lap. Thieu du lieu khong duoc bien thanh mot ket luan.
     *
     * @param  array<string, mixed>  $video
     * @param  array<string, mixed>  $audio
     */
    private function audioOffsetMs(array $video, array $audio, string $label): int
    {
        $videoStart = $this->startMilliseconds($video);
        $audioStart = $this->startMilliseconds($audio);

        if ($videoStart === null || $audioStart === null) {
            throw new CompositionRefused(
                $label.': co luong tieng nhung thieu `start_time` — khong biet hai luong'
                .' lech nhau bao nhieu, va khong duoc coi nhu bang khong',
            );
        }

        return $audioStart - $videoStart;
    }

    /**
     * Do dai nguon, LAM TRON XUONG.
     *
     * `round()` cho 4.966667s thanh 4967ms — dai hon nguon that, va mot khoang cat
     * toi 4967ms se di qua duoc trong khi no vuot qua cai file thuc su co.
     *
     * @param  array<string, mixed>  $stream
     */
    private function durationMilliseconds(array $stream): ?int
    {
        $value = $stream['duration'] ?? null;

        return is_float($value) || is_int($value) ? (int) floor($value * 1000) : null;
    }

    /**
     * Moc bat dau. O day lam tron GAN NHAT la dung: no khong dinh ra mot bien nao,
     * chi dinh ra do tre se chen lai, va sai so duoi mot mili giay khong doi duoc
     * ket qua nghe thay.
     *
     * @param  array<string, mixed>  $stream
     */
    private function startMilliseconds(array $stream): ?int
    {
        $value = $stream['start_time'] ?? null;

        return is_float($value) || is_int($value) ? (int) round($value * 1000) : null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function transition(array $entry, int $i): CompositionTransition
    {
        $label = 'moi noi '.($i + 1);

        if (! array_key_exists('transition_after', $entry)) {
            return CompositionTransition::cut();
        }

        $raw = $entry['transition_after'];

        if (! is_array($raw)) {
            throw new CompositionRefused($label.': `transition_after` phai la mot doi tuong');
        }

        $type = $raw['type'] ?? null;

        if (! is_string($type)) {
            throw new CompositionRefused($label.': thieu `type`');
        }

        if ($type === CompositionTransition::CUT) {
            // `cut` kem `frames` khac 0 la mot mau thuan, khong phai mot so thua:
            // nguoi viet dang tuong co chuyen canh, va im lang lam tron ve 0 se cho
            // ra mot ban phim khac han cai ho mo ta.
            if ($this->optionalInteger($raw, 'frames', 0) !== 0) {
                throw new CompositionRefused($label.': `cut` khong duoc co so khung khac 0');
            }

            return CompositionTransition::cut();
        }

        if ($type !== CompositionTransition::CROSSFADE) {
            throw new CompositionRefused($label.': loai chuyen canh khong biet "'.$type.'"');
        }

        $frames = $this->integer($raw, 'frames');

        if ($frames < 1) {
            throw new CompositionRefused($label.': `crossfade` phai dai it nhat mot khung');
        }

        return CompositionTransition::crossfade($frames);
    }

    /**
     * Mot clip GIUA chiu ca chong lan vao lan ra. Chi kiem tung moi noi voi clip lien
     * ke la bo sot ca clip bi hai dau an vao nhau.
     */
    private function assertOverlapsFit(CompositionPlan $plan): void
    {
        foreach ($plan->clips as $i => $clip) {
            $in = $i > 0 ? $plan->transitions[$i - 1]->frames : 0;
            $out = $i < count($plan->transitions) ? $plan->transitions[$i]->frames : 0;

            if ($in + $out >= $clip->frames) {
                throw new CompositionRefused(sprintf(
                    'clip %d dai %d khung nhung chong lan %d + %d — khong con gi de chieu',
                    $i + 1, $clip->frames, $in, $out,
                ));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function arrayAt(array $manifest, string $key): array
    {
        $value = $manifest[$key] ?? null;

        if (! is_array($value)) {
            throw new CompositionRefused('`'.$key.'` phai la mot doi tuong');
        }

        return $value;
    }

    /**
     * So nguyen THAT SU, khong phai thu ep duoc thanh so nguyen.
     *
     * `(int) 24.9` ra `24` va `(int) '18abc'` ra `18`: mot manifest sai se im lang
     * bien thanh mot ke hoach KHAC, chay tron tru, va cho ra file khong ai dat.
     *
     * @param  array<string, mixed>  $source
     */
    private function integer(array $source, string $key): int
    {
        $value = $source[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        throw new CompositionRefused(sprintf(
            '`%s` phai la so nguyen, nhan duoc %s', $key, var_export($value, true),
        ));
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function optionalInteger(array $source, string $key, int $default): int
    {
        return array_key_exists($key, $source) ? $this->integer($source, $key) : $default;
    }
}
