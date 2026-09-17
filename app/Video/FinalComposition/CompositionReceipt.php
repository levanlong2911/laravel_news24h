<?php

namespace App\Video\FinalComposition;

/**
 * Bang chung rang mot output DA duoc do va da dat, ghi ra dia truoc khi cham vao DB.
 *
 * Giua luc verifier noi "dat" va luc hang DB thanh `ready` co mot khoang ma tien
 * trinh co the chet: het `max_execution_time`, may chu khoi dong lai. Khong co
 * receipt thi phia sau chi con mot file `final.mp4` khong gan voi luot nao, va cach
 * duy nhat de dung lai no la ghep lai tu dau.
 *
 * Ghi ra file tam roi `rename()`: doc phai mot receipt viet do dang con te hon la
 * khong co receipt nao, vi no trong nhu mot bang chung.
 */
final class CompositionReceipt
{
    /** Doi hinh dang receipt thi TANG so nay — doi phuc hoi tu choi ban la. */
    public const VERSION = 1;

    public const FILE = 'receipt.json';

    /**
     * Ten file output, KHONG phai duong dan.
     *
     * Receipt nam cung thu muc voi output. Luu duong dan day du la mo mot duong cho
     * phep doi phuc hoi doc va xoa mot file bat ky tren dia neu receipt bi sua.
     */
    public const OUTPUT_NAME = 'final.mp4';

    /**
     * @param  list<array{render_id: string, sequence_no: int, start_ms: int, duration_ms: int}>  $clips
     * @return array<string, mixed>
     */
    public static function build(
        CompositionRun $run,
        CompositionExpectation $expected,
        string $sha256,
        int $bytes,
        array $clips,
    ): array {
        return [
            'version' => self::VERSION,
            'engine' => $run->engine,
            'final_id' => $run->finalId,
            'manifest_hash' => $run->manifestHash,
            'verified_at' => gmdate('c'),
            'output' => ['name' => self::OUTPUT_NAME, 'sha256' => $sha256, 'bytes' => $bytes],
            'expected' => [
                'width' => $expected->profile->width,
                'height' => $expected->profile->height,
                'fps' => $expected->profile->fps,
                'crf' => $expected->profile->crf,
                'sample_rate' => $expected->profile->sampleRate,
                'channels' => $expected->profile->channels,
                'frames' => $expected->frames,
                'audio_samples' => $expected->audioSamples,
            ],
            'clips' => $clips,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws CompositionRefused khi khong ghi duoc — luot chay KHONG duoc bao thanh
     *                            cong ma khong de lai bang chung
     */
    public static function write(string $path, array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new CompositionRefused('khong dung duoc noi dung receipt');
        }

        $temporary = $path.'.tmp';

        if (@file_put_contents($temporary, $json) !== strlen($json)) {
            @unlink($temporary);

            throw new CompositionRefused('khong ghi duoc receipt tam: '.$temporary);
        }

        // `rename()` de len file dang co tren Windows se that bai, nen xoa truoc. Mot
        // receipt cu o day chi co the la rac: thu muc mang ten final ID va duoc tao
        // doc quyen, nen khong luot nao khac tung ghi vao day.
        @unlink($path);

        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new CompositionRefused('khong dat duoc receipt vao cho: '.$path);
        }
    }

    /**
     * @return array<string, mixed>|null `null` khi khong co, khong doc duoc, hoac
     *                                   khong phai JSON dang doi tuong
     */
    public static function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $payload = json_decode($raw, true);

        return is_array($payload) && ! array_is_list($payload) ? $payload : null;
    }
}
