<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Models\VideoDesignImage;
use App\Video\Locations\LocationReferencePrompt;
use App\Video\Media\MediaModelRegistry;
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
     * @param  array{provider_model?: mixed, quality?: mixed, size?: mixed, aspect_ratio?: mixed, image_size?: mixed, variations?: mixed}  $settings
     * @param  ?array{stage_id: string, content_hash: string, prompts_hash: string, prompt_sha256: string, selection_version: int}  $shown  what the page showed: the description, its prompt set and the prompt of this image; a description that is not in use yet, or whose prompts changed, is put in use with exactly these prompts before the render
     * @return array{0: ?VideoDesignImage, 1: string}
     */
    public function render(string $projectId, string $creator, string $requestId, array $settings, string $operationId, ?array $shown = null, ?string $reviewerId = null): array
    {
        if (! Str::isUuid($operationId)) {
            return [null, 'Mã thao tác không hợp lệ — tải lại trang.'];
        }

        if ($shown !== null) {
            $text = $this->sources->selection($projectId)['text'];

            if (($text['stage_id'] ?? null) !== $shown['stage_id'] || ($text['prompts_hash'] ?? null) !== $shown['prompts_hash']) {
                [$decision, $reason] = $this->sources->approveText($projectId, $shown['stage_id'], $shown['content_hash'], $shown['prompts_hash'], $shown['selection_version'], $operationId, $reviewerId);

                if ($decision === null) {
                    return [null, $reason];
                }
            }
        }

        try {
            $spec = $this->spec($projectId, $requestId, $settings, $operationId);

            if ($shown !== null && $spec['prompt_sha256'] !== $shown['prompt_sha256']) {
                return [null, "Prompt của {$requestId} trên trang khác prompt sẽ gửi — tải lại trang để xem prompt mới."];
            }
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

    /** @return array{0: ?VideoDesignImage, 1: string} a failed or unfinished cell sent again with its own spec, only while its prompt is still the approved one */
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
        $requestId = (string) ($spec['request_id'] ?? '');

        if (($spec['locations_stage_id'] ?? null) !== (string) $text['stage']->id) {
            return [null, 'Ô ảnh này thuộc một bộ bối cảnh khác bộ đang dùng — không render.'];
        }

        $request = $this->sources->request($text, $requestId);

        if ($request === null) {
            return [null, 'Bộ bối cảnh đang dùng không còn bối cảnh của ô này.'];
        }

        if ($request['blocked']) {
            return [null, "Bối cảnh {$requestId} còn xung đột kịch bản / thiết kế chưa giải quyết — không render."];
        }

        if (in_array($requestId, $text['stale'], true)) {
            return [null, "Prompt của {$requestId} đã đổi sau lần render này — bấm Render Plate để render với prompt mới."];
        }

        if (($spec['prompt_sha256'] ?? null) !== ($text['prompts']['images'][$requestId]['sha256'] ?? null)) {
            return [null, "Ô này mang prompt cũ của {$requestId} — không gửi lại ô cũ. Bấm Render Plate ở {$requestId} để dựng với prompt hiện tại; ô cũ vẫn được giữ."];
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
        $request = $this->sources->request($text, $requestId) ?? throw new RuntimeException("Bộ bối cảnh đang dùng không có bối cảnh {$requestId}.");

        if ($request['blocked']) {
            throw new RuntimeException("Bối cảnh {$requestId} còn xung đột kịch bản / thiết kế chưa giải quyết — không render.");
        }

        if (in_array($requestId, $text['stale'], true)) {
            throw new RuntimeException("Prompt của {$requestId} đã đổi so với prompt đang dùng (bộ ghép hoặc cấu hình đổi) — tải lại trang rồi bấm Render Plate.");
        }

        $approved = $text['prompts']['images'][$requestId] ?? throw new RuntimeException("Chưa có prompt đang dùng cho {$requestId} — tải lại trang rồi bấm Render Plate.");
        $entry = $this->registry->find(LocationReferencePrompt::TASK, (string) ($settings['provider_model'] ?? '')) ?? throw new RuntimeException('Model ảnh không có trong danh sách của Phase 5.');

        if (! in_array('generate', (array) ($entry['capabilities'] ?? []), true)) {
            throw new RuntimeException("Model {$entry['label']} không khai năng lực generate cho Phase 5.");
        }

        $variations = filter_var($settings['variations'] ?? null, FILTER_VALIDATE_INT);
        $controls = $entry['controls'];
        $choice = static fn (string $field, string $list): ?string => is_string($settings[$field] ?? null) && in_array($settings[$field], (array) ($controls[$list] ?? []), true) ? $settings[$field] : null;

        if ($variations === false || $variations < 1 || $variations > $entry['max_variations']) {
            throw new RuntimeException('Thiết lập render không hợp lệ cho model này.');
        }

        if ($entry['provider'] === 'openai') {
            $size = $choice('size', 'sizes');
            $quality = $choice('quality', 'qualities');
            $render = ['size' => $size, 'quality' => $quality];
            $invalid = $size === null || $quality === null;
        } else {
            $aspect = $choice('aspect_ratio', 'aspect_ratios');
            $imageSize = $choice('image_size', 'image_sizes');
            $render = [
                'api_version' => $entry['api_version'],
                'shape' => $entry['shape'],
                'aspect_ratio' => $aspect,
                'image_size' => $imageSize,
                'size' => $aspect.'@'.$imageSize,
                'quality' => null,
                'unit_cost_usd' => $controls['prices'][$imageSize] ?? null,
                'pricing_version' => $entry['pricing_version'] ?? null,
            ];
            $invalid = $aspect === null || $imageSize === null;
        }

        if ($invalid) {
            throw new RuntimeException('Thiết lập render không hợp lệ cho model này.');
        }

        return array_replace([
            'project_id' => $projectId,
            'operation' => LocationReferencePrompt::OPERATION,
            'task' => LocationReferencePrompt::TASK,
            'spec_version' => (string) $text['prompts']['composer'],
            'environment_key' => $requestId,
            'request_id' => $requestId,
            'locations_stage_id' => (string) $text['stage']->id,
            'locations_content_hash' => (string) $text['evaluation']['content_hash'],
            'text_approval_id' => (string) $text['selection']['text']['approval_id'],
            'prompt' => (string) $approved['prompt'],
            'prompt_sha256' => (string) $approved['sha256'],
            'provider' => (string) $entry['provider'],
            'model' => (string) $entry['model'],
            'pricing' => (string) $entry['pricing'],
            'variations' => $variations,
            'size' => null,
            'quality' => null,
            'sources' => [],
            'attempt' => $operationId,
        ], $render);
    }
}
