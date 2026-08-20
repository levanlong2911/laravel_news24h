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
     * @param  array<string, mixed>  $concept
     * @return array{0: ?string, 1: string}
     */
    public function compile(
        string $category,
        array $concept,
        string $viewpoint = 'front_three_quarter',
        int $width = 1024,
        int $height = 1536,
    ): array {
        $dir = (string) config('video.runner.log_dir');

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return [null, "Khong tao duoc thu muc: {$dir}"];
        }

        $file = $dir.DIRECTORY_SEPARATOR.sprintf('imgprompt_%s.json', Str::random(8));
        $plan = ['category' => $category, 'creative_concept' => $concept];

        if (@file_put_contents($file, json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) {
            return [null, "Khong ghi duoc file: {$file}"];
        }

        try {
            [$ran, $output] = $this->pythonRunner->runAndWait(self::SCRIPT, [
                '--render-plan-file='.$file,
                '--viewpoint='.$viewpoint,
                '--width='.$width,
                '--height='.$height,
            ], 30);
        } finally {
            @unlink($file);
        }

        if (! $ran) {
            return [null, $output];
        }

        $decoded = json_decode(trim($output), true);

        if (! is_array($decoded) || ! array_key_exists('ok', $decoded)) {
            return [null, 'Python tra ve thu khong doc duoc: '.Str::limit($output, 300)];
        }

        return ($decoded['ok'] ?? false) === true
            ? [(string) $decoded['prompt'], 'ok']
            : [null, (string) ($decoded['error'] ?? 'khong ro loi')];
    }
}
