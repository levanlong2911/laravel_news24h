<?php

namespace App\Services\Video;

use App\Services\PythonRunner;
use Illuminate\Support\Str;

class PythonPromptCompiler
{
    private const SCRIPT = 'compile_image_prompt.py';

    private PythonRunner $pythonRunner;

    public function __construct(PythonRunner $pythonRunner)
    {
        $this->pythonRunner = $pythonRunner;
    }

    /**
     * May quay va khung KHONG co gia tri mac dinh o day. Mot mac dinh trong
     * compiler la cua che loi: nguoi goi quen truyen thi prompt van bien dich
     * ra binh thuong, chi la no mo ta mot khung khac cai se duoc gui di. Loi do
     * da song trong repo nay cho toi 2026-08-23, sot qua moi test, vi mac dinh
     * cua form tinh co trung mac dinh cua compiler.
     *
     * @param  array<string, mixed>  $concept
     * @return array{0: ?string, 1: string}
     */
    public function compile(
        string $category,
        array $concept,
        string $viewpoint,
        int $width,
        int $height,
        ?string $stage = null,
    ): array {
        $dir = (string) config('video.runner.log_dir');

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return [null, "Could not create directory: {$dir}"];
        }

        $file = $dir.DIRECTORY_SEPARATOR.sprintf('imgprompt_%s.json', Str::random(8));
        $request = [
            'viewpoint' => $viewpoint,
            'output_size' => ['width' => $width, 'height' => $height],
        ];

        if ($stage !== null) {
            $request['stage'] = $stage;
        }

        $plan = [
            'category' => $category,
            'creative_concept' => $concept,
            'image_prompt_request' => $request,
        ];

        // 'x' tao moi va THAT BAI neu ten da ton tai. `file_put_contents` de mac
        // dinh se de len, nen hai request trung ten se doc nham concept cua nhau
        // ma khong ai bao. Xac suat rat nho, hau qua thi im lang.
        $handle = @fopen($file, 'xb');

        if ($handle === false) {
            return [null, "Could not create the compile spec file: {$file}"];
        }

        $json = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $written = fwrite($handle, $json);

        // Ghi thieu byte (dia day chang han) cho ra JSON cut duoi. Bat o day thi
        // loi noi dung su that; de Python doc thi no bao "unexpected end of input"
        // va nguoi doc di tim nham cho.
        if ($written !== strlen($json) || ! fclose($handle)) {
            @unlink($file);

            return [null, "Could not write the compile spec file: {$file}"];
        }

        $args = [
            '--render-plan-file='.$file,
            '--viewpoint='.$viewpoint,
            '--width='.$width,
            '--height='.$height,
        ];

        // Stage doi NOI DUNG (asset type, subject state, style, may quay), khong
        // doi khung: khung do nguoi dung chon tren form, va prompt phai noi dung
        // cai khung se duoc gui di.
        if ($stage !== null) {
            $args[] = '--stage='.$stage;
        }

        try {
            [$ran, $output] = $this->pythonRunner->runAndWait(self::SCRIPT, $args, 30);
        } finally {
            @unlink($file);
        }

        if (! $ran) {
            return [null, $output];
        }

        $decoded = json_decode(trim($output), true);

        if (! is_array($decoded) || ! array_key_exists('ok', $decoded)) {
            return [null, 'Python returned something unreadable: '.Str::limit($output, 300)];
        }

        if (($decoded['ok'] ?? false) !== true) {
            return [null, (string) ($decoded['error'] ?? 'unknown error')];
        }

        $mismatch = $this->contractMismatch($decoded, $viewpoint, $width, $height, $stage);

        if ($mismatch !== null) {
            return [null, $mismatch];
        }

        return [(string) $decoded['prompt'], 'ok'];
    }

    /**
     * Python is the source of prompt grammar, but Laravel is the caller that will
     * send the final render size/stage forward. If Python reports that it compiled
     * a different request, fail here before the user pays for an image described by
     * the wrong prompt.
     *
     * @param  array<string, mixed>  $decoded
     */
    private function contractMismatch(
        array $decoded,
        string $viewpoint,
        int $width,
        int $height,
        ?string $stage,
    ): ?string {
        if (($decoded['operation'] ?? null) !== 'generate') {
            return 'Python compiled unexpected image operation: '.(string) ($decoded['operation'] ?? 'missing');
        }

        if (($decoded['viewpoint'] ?? null) !== $viewpoint) {
            return 'Python compiled unexpected viewpoint: '.(string) ($decoded['viewpoint'] ?? 'missing');
        }

        if (($decoded['stage'] ?? null) !== $stage) {
            return 'Python compiled unexpected stage: '.(string) ($decoded['stage'] ?? 'missing');
        }

        $size = $decoded['output_size'] ?? null;

        if (! is_array($size) || ($size['width'] ?? null) !== $width || ($size['height'] ?? null) !== $height) {
            return 'Python compiled unexpected output_size';
        }

        return null;
    }
}
