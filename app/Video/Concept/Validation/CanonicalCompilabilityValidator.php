<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

use App\Services\PythonRunner;
use App\Video\Concept\Freeze\FrozenCanonicalConcept;
use App\Video\Concept\Handoff\CanonicalRevisionEnvelopeBuilder;
use Illuminate\Support\Str;

final class CanonicalCompilabilityValidator
{
    private const SCRIPT = 'compile_canonical_prompt.py';

    private const TIMEOUT_SECONDS = 60;

    private const PROBE_VIEWPOINT = 'front_three_quarter';

    private const PROBE_WIDTH = 1536;

    private const PROBE_HEIGHT = 1024;

    public function __construct(
        private readonly PythonRunner $pythonRunner,
        private readonly CanonicalRevisionEnvelopeBuilder $envelopes,
    ) {}

    public function validate(
        FrozenCanonicalConcept $frozen,
        string $revisionId,
        string $projectId,
    ): ValidationResult {
        if (! config('video.python_runner_enabled', true)) {
            return $this->failed(
                'VIDEO_PYTHON_RUNNER dang tat — khong kiem duoc kha nang bien dich.'
            );
        }

        $dir = (string) config('video.runner.log_dir');

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return $this->failed('Khong tao duoc thu muc: '.$dir);
        }

        try {
            $envelope = $this->envelopes->buildCandidate(
                $frozen,
                $revisionId,
                $projectId,
                $this->probeAssetRequest(),
            );
        } catch (\Throwable $e) {
            return $this->failed($e->getMessage());
        }

        $file = $dir.DIRECTORY_SEPARATOR.sprintf('compilability_%s.json', Str::random(8));

        file_put_contents($file, json_encode(
            $envelope,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        try {
            [$ran, $output] = $this->pythonRunner->runAndWait(self::SCRIPT, [
                '--envelope-file='.$file,
                '--viewpoint='.self::PROBE_VIEWPOINT,
                '--width='.self::PROBE_WIDTH,
                '--height='.self::PROBE_HEIGHT,
            ], self::TIMEOUT_SECONDS);
        } finally {
            @unlink($file);
        }

        $decoded = json_decode(trim($output), true);

        if ($ran && is_array($decoded) && ($decoded['ok'] ?? false) === true) {
            return ValidationResult::valid();
        }

        return $this->failed(
            (string) ($decoded['error'] ?? Str::limit(trim($output), 400))
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function probeAssetRequest(): array
    {
        return [
            'asset_id' => 'compilability_probe',
            'asset_type' => 'identity_anchor',
            'view_role' => 'master_geometry',
            'required_paths' => [],
            'optional_paths' => [],
            'excluded_paths' => [],
            'state' => null,
            'camera' => ['view' => self::PROBE_VIEWPOINT],
        ];
    }

    private function failed(string $message): ValidationResult
    {
        return ValidationResult::invalid([
            new ValidationError(
                code: 'canonical_not_compilable',
                path: 'canonical',
                message: $message,
                expected: 'a canonical revision the prompt compiler accepts',
                actual: null,
            ),
        ]);
    }
}
