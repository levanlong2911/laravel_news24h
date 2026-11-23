<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Models\VideoDesignImage;
use App\Video\Locations\LocationReferencePrompt;
use App\Video\Media\MediaModelRegistry;
use App\Video\Moto\MotoLocationImagePlan;
use App\Video\Moto\MotoLocations;
use App\Video\Queue\VideoQueues;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class LocationReferenceService implements QueuedImageService
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
     * @param  ?array{id: string, name: string}  $requester  who sent the render; a queued render waits on that person's image queue
     * @return array{0: ?VideoDesignImage, 1: string} reason queued when the cell and its job were stored together
     */
    public function render(string $projectId, string $creator, string $requestId, array $settings, string $operationId, ?array $shown = null, ?string $reviewerId = null, ?array $requester = null): array
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

        $create = function () use ($projectId, $creator, $spec): array {
            [$image, $reason] = $this->store->createLocationReference($projectId, $creator, $spec);

            return $image === null ? [null, $reason === 'project_not_found' ? 'Không tìm thấy dự án.' : $reason] : [$image, $reason];
        };
        $made = static fn (VideoDesignImage $image, string $reason): bool => $reason === 'already_exists' && in_array($image->status, [DesignImageStatus::RENDERED->value, DesignImageStatus::APPROVED->value], true);

        if (VideoQueues::enabled()) {
            return app(ImageRenderQueue::class)->createQueued('location', $create, function (VideoDesignImage $image, string $reason) use ($projectId, $made): ?array {
                if ($made($image, $reason)) {
                    return [$image, 'already_exists'];
                }

                [$sendable, $problem] = $this->sendable($projectId, (string) $image->id);

                return $sendable === null ? [null, $problem] : null;
            }, $requester);
        }

        [$image, $reason] = $create();

        if ($image === null || $made($image, $reason)) {
            return [$image, $reason];
        }

        return $this->retry($projectId, (string) $image->id);
    }

    /**
     * @param  ?array{id: string, name: string}  $requester  who sent the render; a queued render waits on that person's image queue
     * @return array{0: ?VideoDesignImage, 1: string} a failed or unfinished cell sent again with its own spec, only while its prompt is still the approved one; reason queued when a worker sends it later
     */
    public function retry(string $projectId, string $imageId, ?array $requester = null): array
    {
        [$image, $problem] = $this->sendable($projectId, $imageId);

        if ($image === null) {
            return [null, $problem];
        }

        if (VideoQueues::enabled()) {
            $problem = app(ImageRenderQueue::class)->submit('location', $imageId, $requester);

            return $problem === null ? [$image->fresh() ?? $image, 'queued'] : [null, 'Chưa đưa vào hàng đợi, không render: '.$problem];
        }

        return $this->send($imageId);
    }

    /** @return ?string why a queued cell may no longer be sent: its prompt is no longer the approved one of the set in use, or — in a set rendered under the shared reference — the plan no longer gives its plate the role it was made for, or the image in use for the standard plate is no longer the source the cell carries. The job then stops before the provider is called; the source of today is never put into an old cell */
    public function checkQueued(VideoDesignImage $image): ?string
    {
        [$sendable, $problem] = $this->sendable((string) $image->project_id, (string) $image->id);

        return $sendable === null ? $problem : null;
    }

    /** @return array{0: ?VideoDesignImage, 1: string, 2: ?array<string, mixed>} */
    public function sendQueued(VideoDesignImage $image): array
    {
        return $this->renderer->renderNow((string) $image->id);
    }

    /** @return array{0: ?VideoDesignImage, 1: string} the cell when its prompt is still the approved one of the set in use and, under the shared reference, it still belongs to the image plan with the source it carries (LocationSourceService::referenceOf) and a model that serves its role; or why it may not be sent. Asked before every send: after a cell is made, on a retry and by the worker right before the provider call */
    private function sendable(string $projectId, string $imageId): array
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

        $reference = $this->sources->referenceOf($text, $requestId, $spec);

        if ($reference['problem'] !== null) {
            return [null, $reference['problem']];
        }

        if ($reference['mechanism'] !== null) {
            try {
                $problem = $this->requestProblem($spec, (string) $reference['role'], $requestId, $reference['role'] === MotoLocationImagePlan::VARIANT ? $this->instruction($text, $reference, $requestId) : null);
            } catch (RuntimeException|InvalidArgumentException $e) {
                $problem = $e->getMessage();
            }

            if ($problem !== null) {
                return [null, $problem];
            }
        }

        return [$image, 'ok'];
    }

    /**
     * @param  array<string, mixed>  $spec  the stored spec of a cell of a set rendered under the shared reference
     * @param  string  $role  the role the image plan gives its plate now
     * @param  ?string  $instruction  the words of the mechanism for the plate as they are joined now (instruction()); null for a standard
     * @return ?string why the cell may not be sent as it is stored, checked again before every provider call: its trace and its sources no longer say one thing, its model is gone from the registry or no longer declares what the role needs, or the words of the mechanism for its plate changed since the cell was made. The model, the provider and the settings of the cell are never replaced by others
     */
    private function requestProblem(array $spec, string $role, string $requestId, ?string $instruction): ?string
    {
        if (($problem = MotoLocationImagePlan::specProblem($spec)) !== null) {
            return $problem;
        }

        $entry = $this->registry->find(LocationReferencePrompt::TASK, (string) ($spec['provider'] ?? '').':'.(string) ($spec['model'] ?? ''));

        if ($entry === null) {
            return "Model của ô này không còn trong danh sách của Phase 5 — không gửi lại ô cũ. Bấm Render Plate ở {$requestId} để chọn model; ô cũ vẫn được giữ.";
        }

        if (($problem = MotoLocationImagePlan::modelProblem($entry, $role)) !== null) {
            return $problem;
        }

        return $role === MotoLocationImagePlan::VARIANT && ! hash_equals(CanonicalJson::hashString((string) $instruction), (string) ($spec['reference']['instruction_sha256'] ?? ''))
            ? "Chỉ dẫn cố định của cơ chế ảnh chuẩn đã đổi sau khi tạo ô này — không gửi lại ô cũ. Bấm Render Plate ở {$requestId} để dựng với chỉ dẫn hiện tại; ô cũ vẫn được giữ."
            : null;
    }

    /**
     * @param  array<string, mixed>  $text  approvedText() of the set in use
     * @param  array<string, mixed>  $reference  LocationSourceService::referenceOf() of a variant plate
     * @return string the words sent before the approved prompt of the plate: the fixed sentences of the mechanism with what the dossier lets change between the standard plate of the place and this plate (MotoLocationImagePlan::instruction)
     *
     * @throws RuntimeException when the configuration of the mechanism is not complete
     */
    private function instruction(array $text, array $reference, string $requestId): string
    {
        return MotoLocationImagePlan::instruction(MotoLocations::sharedReference(), (array) ($text['evaluation']['document']['data'] ?? []), (string) $reference['standard_plate'], $requestId);
    }

    /** @return array{0: ?VideoDesignImage, 1: string} */
    private function send(string $imageId): array
    {
        [$done, $reason] = $this->renderer->renderNow($imageId);

        return match ($reason) {
            'rendered' => [$done, 'rendered'],
            'failed' => [null, 'Render lỗi: '.($done?->render_error ?? 'không rõ')],
            default => [null, "Không render được ({$reason})."],
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed> the snapshot a render is stored and sent with. A set rendered plate by plate gets the spec it always got. A set rendered under the shared reference (moto-locations-v4) is held plate by plate by its image plan: the role of the plate is read from the plan, never from the request; a standard is generated from its own approved prompt with no source; a variant carries, as its one source, the image in use for the standard plate of its place, and needs a model the registry declares for edit_sources. The spec then keeps the trace of the request under reference, beside the stage, content hash, approved prompt hash, model, settings and operation id it already keeps
     *
     * @throws RuntimeException when the plate may not be rendered now: every plate of its place is held, its standard has no chosen image or that image cannot be used, or the model does not serve the role — no other model, provider or way of rendering is taken instead
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
        $reference = $this->sources->referenceOf($text, $requestId);

        if ($reference['problem'] !== null) {
            throw new RuntimeException($reference['problem']);
        }

        $role = $reference['role'] ?? MotoLocationImagePlan::STANDARD;
        $entry = $this->registry->find(LocationReferencePrompt::TASK, (string) ($settings['provider_model'] ?? '')) ?? throw new RuntimeException('Model ảnh không có trong danh sách của Phase 5.');

        if (($problem = MotoLocationImagePlan::modelProblem($entry, $role)) !== null) {
            throw new RuntimeException($problem);
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
            'sources' => $reference['source'] === null ? [] : [$reference['source']],
            'attempt' => $operationId,
        ], $render) + ($reference['mechanism'] === null ? [] : [
            'reference' => MotoLocationImagePlan::trace($reference, $role === MotoLocationImagePlan::VARIANT ? $this->instruction($text, $reference, $requestId) : null, (string) $approved['sha256']),
        ]);
    }
}
