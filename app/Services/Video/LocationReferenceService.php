<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Video\Locations\LocationReferencePrompt;
use App\Video\Media\MediaModelRegistry;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class LocationReferenceService
{
    public function __construct(
        private readonly LocationSourceService $sources,
        private readonly DesignImageStore $store,
        private readonly DesignImageDirectRenderer $renderer,
        private readonly MediaModelRegistry $registry,
    ) {}

    /**
     * @param  array{provider_model?: mixed, quality?: mixed, size?: mixed, variations?: mixed}  $settings
     * @return array{0: ?VideoDesignImage, 1: string}
     */
    public function render(string $projectId, string $creator, string $requestId, array $settings, string $operationId): array
    {
        if (! Str::isUuid($operationId)) {
            return [null, 'Mã thao tác không hợp lệ — tải lại trang.'];
        }

        try {
            $spec = $this->spec($projectId, $requestId, $settings, $operationId);
        } catch (RuntimeException|InvalidArgumentException $e) {
            return [null, $e->getMessage()];
        }

        [$image, $reason] = $this->store->createLocationReference($projectId, $creator, $spec);

        if ($image === null) {
            return [null, $reason === 'project_not_found' ? 'Không tìm thấy dự án.' : $reason];
        }

        if ($reason === 'already_exists' && in_array($image->status, [DesignImageStatus::RENDERED->value, DesignImageStatus::APPROVED->value], true)) {
            return [$image, 'already_exists'];
        }

        return $this->retry($projectId, (string) $image->id);
    }

    /** @return array{0: ?VideoDesignImage, 1: string} a failed or unfinished cell sent again with its own spec */
    public function retry(string $projectId, string $imageId): array
    {
        $image = VideoDesignImage::query()->whereKey($imageId)->where('project_id', $projectId)->where('image_type', DesignImageStore::LOCATION_REFERENCE_TYPE)->first();

        if ($image === null) {
            return [null, 'Không tìm thấy ô ảnh Phase 5 này.'];
        }

        try {
            $text = $this->sources->approvedText($projectId);
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }

        $spec = (array) $image->prompt_spec_json;

        if (($spec['locations_stage_id'] ?? null) !== (string) $text['stage']->id) {
            return [null, 'Ô ảnh này thuộc một bản mô tả khác bản đang duyệt — không render.'];
        }

        $request = $this->sources->request($text, (string) ($spec['request_id'] ?? ''));

        if ($request === null) {
            return [null, 'Bản mô tả đã duyệt không còn yêu cầu ảnh của ô này.'];
        }

        try {
            $current = array_column($this->manifest($text, $request), 'artifact_id');
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }

        if ($current !== array_column((array) ($spec['sources'] ?? []), 'artifact_id')) {
            return [null, "Ảnh nguồn của ô này đã đổi so với ảnh đang chọn — không gửi lại ô cũ. Bấm \"Render biến thể mới\" ở {$request['id']} để dựng với ảnh nguồn hiện tại; ô cũ vẫn được giữ."];
        }

        [$done, $reason] = $this->renderer->renderNow((string) $image->id);

        return match ($reason) {
            'rendered' => [$done, 'rendered'],
            'failed' => [null, 'Render lỗi: '.($done?->render_error ?? 'không rõ')],
            default => [null, "Không render được ({$reason})."],
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function spec(string $projectId, string $requestId, array $settings, string $operationId): array
    {
        $text = $this->sources->approvedText($projectId);
        $request = $this->sources->request($text, $requestId) ?? throw new RuntimeException("Bản mô tả đã duyệt không có yêu cầu ảnh {$requestId}.");
        $entry = $this->registry->find(LocationReferencePrompt::TASK, (string) ($settings['provider_model'] ?? '')) ?? throw new RuntimeException('Model ảnh không có trong danh sách của Phase 5.');
        $sources = $this->manifest($text, $request);
        $capability = $sources === [] ? 'generate' : 'edit_sources';

        if (! in_array($capability, (array) ($entry['capabilities'] ?? []), true)) {
            throw new RuntimeException("Model {$entry['label']} không khai năng lực {$capability} cho Phase 5.");
        }

        $limit = min((int) ($entry['max_sources'] ?? 0), OpenAiImageClient::MAX_SOURCE_IMAGES);

        if (count($sources) > $limit) {
            throw new RuntimeException("Yêu cầu {$requestId} cần ".count($sources)." ảnh nguồn; model {$entry['label']} nhận tối đa {$limit}.");
        }

        $variations = filter_var($settings['variations'] ?? null, FILTER_VALIDATE_INT);
        $size = (string) ($settings['size'] ?? '');
        $quality = (string) ($settings['quality'] ?? '');

        if ($variations === false || $variations < 1 || $variations > $entry['max_variations']
            || ! in_array($size, (array) $entry['controls']['sizes'], true) || ! in_array($quality, (array) $entry['controls']['qualities'], true)) {
            throw new RuntimeException('Thiết lập render không hợp lệ cho model này.');
        }

        $settingsOfFlow = $text['flow']['adapter']->settings();

        return [
            'project_id' => $projectId,
            'operation' => LocationReferencePrompt::OPERATION,
            'task' => LocationReferencePrompt::TASK,
            'spec_version' => LocationReferencePrompt::VERSION,
            'environment_key' => $requestId,
            'request_id' => $requestId,
            'locations_stage_id' => (string) $text['stage']->id,
            'locations_content_hash' => (string) $text['evaluation']['content_hash'],
            'prompt' => LocationReferencePrompt::compose((array) $text['evaluation']['document'], $request, (array) $settingsOfFlow['reference']),
            'provider' => (string) $entry['provider'],
            'model' => (string) $entry['model'],
            'pricing' => (string) $entry['pricing'],
            'variations' => $variations,
            'size' => $size,
            'quality' => $quality,
            'sources' => $sources,
            'reference_manifest_hash' => CanonicalJson::hash($sources),
            'attempt' => $operationId,
        ];
    }

    /**
     * @param  array<string, mixed>  $text
     * @param  array<string, mixed>  $request
     * @return list<array{position: int, artifact_id: string, candidate_id: string, sha256: string, input: string, role: string}>
     */
    private function manifest(array $text, array $request): array
    {
        $chosen = (array) $text['selection']['images'];
        $sheet = (array) ($this->sources->source($text['stage'])['design']['model_sheet'] ?? []);
        $sources = [];

        foreach ((array) $request['inputs'] as $position => $input) {
            if ($input['kind'] === 'model_sheet') {
                [$artifactId, $imageId, $sha] = [(string) ($sheet['master_artifact_id'] ?? ''), (string) ($sheet['master_image_id'] ?? ''), (string) ($sheet['master_artifact_sha256'] ?? '')];
                $label = 'model sheet';
            } else {
                $pick = $chosen[$input['id']] ?? throw new RuntimeException("Chọn ảnh cho {$input['id']} trước — {$request['id']} dựng từ ảnh đó.");
                [$artifactId, $imageId, $sha] = [(string) $pick['artifact_id'], (string) $pick['image_id'], (string) $pick['sha256']];
                $label = (string) $input['id'];
            }

            $artifact = VideoArtifact::query()->whereKey($artifactId)->where('project_id', $text['stage']->project_id)->first();

            if ($artifact === null || (string) $artifact->design_image_id !== $imageId) {
                throw new RuntimeException("Ảnh nguồn {$label} không còn thuộc ô ảnh của nó.");
            }

            if (($problem = LocationSourceService::fileProblem($artifact, $sha)) !== null) {
                throw new RuntimeException("Ảnh nguồn {$label}: {$problem}.");
            }

            $sources[] = ['position' => $position, 'artifact_id' => $artifactId, 'candidate_id' => $imageId, 'sha256' => $sha, 'input' => $label, 'role' => (string) $input['role']];
        }

        if (count($sources) > OpenAiImageClient::MAX_SOURCE_IMAGES) {
            throw new RuntimeException("Yêu cầu {$request['id']} cần ".count($sources).' ảnh nguồn; tối đa '.OpenAiImageClient::MAX_SOURCE_IMAGES.'.');
        }

        return $sources;
    }
}
