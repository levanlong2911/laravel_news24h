<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\AnchorStage;
use App\Enums\ImageModel;
use App\Services\PythonRunner;
use App\Video\Concept\Handoff\CanonicalRevisionEnvelopeBuilder;
use App\Video\Concept\Handoff\CompiledAnchorPrompt;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use Illuminate\Support\Str;

class CanonicalPromptCompiler
{
    private const SCRIPT = 'compile_canonical_prompt.py';

    public function __construct(
        private PythonRunner $pythonRunner,
        private CanonicalRevisionEnvelopeBuilder $envelopes,
    ) {}

    /**
     * @return array{0: ?CompiledAnchorPrompt, 1: string}
     */
    public function compile(
        CanonicalConceptRevision $revision,
        string $viewpoint,
        int $width,
        int $height,
        ?string $stage = null,
        array $excludedPaths = [],
        ?ImageModel $model = null,
        ?string $sessionCode = null,
        ?string $runId = null,
    ): array {
        $model ??= ImageModel::GPT_IMAGE_2;
        $provider = $model->provider();
        $modelKey = $model->value;

        $dir = (string) config('video.runner.log_dir');

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return [null, "Could not create directory: {$dir}"];
        }

        try {
            $envelope = $this->envelopes->build(
                $revision,
                $this->assetRequest($viewpoint, $stage, $excludedPaths),
            );
        } catch (\Throwable $e) {
            return [null, $e->getMessage()];
        }

        $file = $dir.DIRECTORY_SEPARATOR.sprintf('envelope_%s.json', Str::random(8));

        $handle = @fopen($file, 'xb');

        if ($handle === false) {
            return [null, "Could not create the envelope file: {$file}"];
        }

        $json = json_encode(
            $envelope,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $written = fwrite($handle, $json);

        if ($written !== strlen($json) || ! fclose($handle)) {
            @unlink($file);

            return [null, "Could not write the envelope file: {$file}"];
        }

        $args = [
            '--envelope-file='.$file,
            '--viewpoint='.$viewpoint,
            '--width='.$width,
            '--height='.$height,
            '--provider='.$provider,
            '--model='.$modelKey,
        ];

        if ($stage !== null) {
            $args[] = '--stage='.$stage;
        }

        $artifactRoot = (string) config('video.runner.artifact_root');

        if ($artifactRoot !== '' && $sessionCode !== null && $runId !== null) {
            $args[] = '--artifact-root='.$artifactRoot;
            $args[] = '--session-code='.$sessionCode;
            $args[] = '--run-id='.$runId;
        }

        try {
            [$ran, $output] = $this->pythonRunner->runAndWait(self::SCRIPT, $args, 60);
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

        $mismatch = $this->contractMismatch(
            $decoded, $revision, $viewpoint, $width, $height, $stage, $provider, $modelKey,
        );

        if ($mismatch !== null) {
            return [null, $mismatch];
        }

        return [CompiledAnchorPrompt::fromPython($decoded, (string) $revision->id), 'ok'];
    }

    /**
     * AnchorStage names WHICH asset is being rendered, not what physical state
     * the subject is in. A fabrication geometry anchor is a construction
     * reference: §11.11 keeps its permanent geometry and drops finished
     * materials, so the prompt cannot ask for navy paint and bare plating at
     * once. Feeding the stage into asset_request.state instead would put an
     * asset-type name where §11.53 expects a physical state.
     *
     * @param  list<string>  $excludedPaths
     * @return array<string, mixed>
     */
    private function assetRequest(
        string $viewpoint,
        ?string $stage,
        array $excludedPaths,
    ): array {
        return [
            'asset_id' => 'master_'.$viewpoint,
            'asset_type' => $this->assetType($stage),
            'view_role' => 'master_geometry',
            'required_paths' => [],
            'optional_paths' => [],
            'excluded_paths' => array_values($excludedPaths),
            'state' => null,
            'camera' => ['view' => $viewpoint],
        ];
    }

    private function assetType(?string $stage): string
    {
        return $stage === AnchorStage::FABRICATION_GEOMETRY_ANCHOR->value
            ? 'construction_reference'
            : 'identity_anchor';
    }

    /**
     * Python owns prompt grammar, but Laravel is the caller that sends the render
     * size and stage forward. A prompt compiled for a different request, or from a
     * different canonical revision, must fail here rather than reach a paid render.
     *
     * @param  array<string, mixed>  $decoded
     */
    private function contractMismatch(
        array $decoded,
        CanonicalConceptRevision $revision,
        string $viewpoint,
        int $width,
        int $height,
        ?string $stage,
        string $provider,
        string $modelKey,
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

        if (($decoded['provider'] ?? null) !== $provider) {
            return 'Python compiled for a different provider: '.(string) ($decoded['provider'] ?? 'missing');
        }

        if (($decoded['model'] ?? null) !== $modelKey) {
            return 'Python compiled for a different model: '.(string) ($decoded['model'] ?? 'missing');
        }

        $canonicalHash = $decoded['canonical_hash'] ?? null;

        if (! is_string($canonicalHash) || ! hash_equals((string) $revision->canonical_hash, $canonicalHash)) {
            return 'Python compiled a prompt from a different canonical revision.';
        }

        return null;
    }
}
