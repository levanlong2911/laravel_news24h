<?php

namespace Tests\Feature\Video;

use App\Enums\DesignImageStatus;
use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Form\AnchorImageForm;
use App\Form\ReferenceImageForm;
use App\Models\VideoArtifact;
use App\Models\VideoCostEntry;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Services\Video\DesignImageDirectRenderer;
use App\Services\Video\DesignImageStore;
use App\Services\Video\OpenAiImageClient;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImageQualityByModelTest extends TestCase
{
    use DatabaseTransactions;

    private const PNG_3X5 = 'iVBORw0KGgoAAAANSUhEUgAAAAMAAAAFCAIAAAAPE8H1AAAACXBIWXMAAA7EAAAOxAGVKw4b'
        .'AAAAF0lEQVQImWPkEpFjYGBgYGBgYoAB/CwADFgARjw2UTkAAAAASUVORK5CYII=';

    private VideoProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->project = VideoProject::create(['title' => 'TEST quality '.uniqid()]);
        $this->app->instance(OpenAiImageClient::class, new OpenAiImageClient(
            http: app(HttpFactory::class),
            storage: app(FilesystemFactory::class),
            apiKey: 'test-key',
            baseUrl: 'https://api.openai.test',
            disk: 'local',
            memoryLimit: '512M',
            timeoutSeconds: 30,
        ));
        $this->app->forgetInstance(DesignImageDirectRenderer::class);
    }

    public function test_only_gpt_image_2_5_offers_xhigh_and_max(): void
    {
        $this->assertSame(['low', 'medium', 'high', 'auto'], array_map(
            static fn (ImageQuality $quality): string => $quality->value,
            ImageModel::GPT_IMAGE_2->qualities(),
        ));

        foreach ([ImageModel::GPT_IMAGE_2_5_FLARE, ImageModel::GPT_IMAGE_2_5_SUNBURST] as $model) {
            $this->assertTrue($model->supports(ImageQuality::XHIGH), $model->value);
            $this->assertTrue($model->supports(ImageQuality::MAX), $model->value);
        }

        $this->assertFalse(ImageModel::GPT_IMAGE_2->supports(ImageQuality::XHIGH));
        $this->assertFalse(ImageModel::GPT_IMAGE_2->supports(ImageQuality::MAX));
        $this->assertNull(ImageQuality::XHIGH->estimatedCostUsd(), 'no price is invented for xhigh');
        $this->assertNull(ImageQuality::MAX->estimatedCostUsd(), 'no price is invented for max');
    }

    public function test_the_anchor_form_checks_quality_against_the_chosen_model(): void
    {
        $form = new AnchorImageForm;
        $request = fn (string $model, string $quality): Request => Request::create('/', 'POST', [
            'size' => '1536x1024',
            'model' => $model,
            'quality' => $quality,
            'variations' => 1,
            'prompt_sha256' => str_repeat('a', 64),
        ]);

        $this->assertSame('max', $form->validate($request('gpt-image-2.5-flare', 'max'))['quality']);
        $this->assertSame('xhigh', $form->validate($request('gpt-image-2.5-sunburst', 'xhigh'))['quality']);

        try {
            $form->validate($request('gpt-image-2', 'xhigh'));
            $this->fail('gpt-image-2 must not accept xhigh');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('không dùng được với model', implode(' ', $e->errors()['quality']));
        }

        try {
            $form->validate($request('gpt-image-2', 'ultra'));
            $this->fail('an unknown quality must be refused');
        } catch (ValidationException $e) {
            $this->assertSame(
                [__('messages.anchor_setting_invalid', ['field' => 'Quality'])],
                $e->errors()['quality'],
            );
        }
    }

    public function test_the_reference_form_checks_quality_against_the_chosen_model(): void
    {
        $form = new ReferenceImageForm;
        $request = fn (string $model, string $quality): Request => Request::create('/', 'POST', [
            'reference_prompt_stage_id' => 'stage-id',
            'prompt_sha256' => str_repeat('a', 64),
            'view' => \App\Video\Reference\ReferenceView::cases()[0]->value,
            'environment' => \App\Video\Reference\ReferenceEnvironment::cases()[0]->value,
            'model' => $model,
            'quality' => $quality,
            'size' => '1152x2048',
            'variations' => 1,
        ]);

        $this->assertSame('max', $form->validate($request('gpt-image-2.5-sunburst', 'max'))['quality']);

        try {
            $form->validate($request('gpt-image-2', 'max'));
            $this->fail('gpt-image-2 must not accept max');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('không dùng được với model', implode(' ', $e->errors()['quality']));
        }

        try {
            $form->validate($request('gpt-image-2', 'ultra'));
            $this->fail('an unknown quality must be refused');
        } catch (ValidationException $e) {
            $this->assertSame(
                [__('messages.anchor_setting_invalid', ['field' => 'Quality'])],
                $e->errors()['quality'],
            );
        }
    }

    public function test_a_new_xhigh_cell_is_marked_unpriced_instead_of_borrowing_a_price(): void
    {
        [$cell] = app(DesignImageStore::class)->createCandidate($this->project->id, 'admin', [
            'prompt' => 'A yacht.',
            'operation' => 'generate',
            'model' => 'gpt-image-2.5-flare',
            'quality' => 'xhigh',
            'size' => '1536x1024',
            'variations' => 1,
        ]);

        $this->assertSame('unpriced', $cell->prompt_spec_json['pricing']);
        $this->assertNull($cell->prompt_spec_json['unit_cost_usd']);
    }

    public function test_xhigh_reaches_the_provider_unchanged(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'stopped in test']], 400)]);
        $cell = $this->cell('gpt-image-2.5-flare', 'xhigh', 'generate');

        app(DesignImageDirectRenderer::class)->renderNow($cell->id);

        Http::assertSent(function (ClientRequest $request): bool {
            $this->assertStringEndsWith('/v1/images/generations', $request->url());
            $this->assertSame('xhigh', $request->data()['quality']);
            $this->assertSame('gpt-image-2.5-flare', $request->data()['model']);

            return true;
        });
    }

    public function test_max_reaches_the_edit_endpoint_unchanged(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'stopped in test']], 400)]);

        app(OpenAiImageClient::class)->edit([
            'prompt' => 'Show the stern.',
            'model' => 'gpt-image-2.5-sunburst',
            'quality' => 'max',
            'size' => '1152x2048',
            'variations' => 1,
        ], 'anchor-bytes', 'anchor.png', 30);

        Http::assertSent(function (ClientRequest $request): bool {
            $fields = collect($request->data())
                ->filter(static fn (array $part): bool => ! isset($part['filename']))
                ->mapWithKeys(static fn (array $part): array => [$part['name'] => $part['contents']]);

            $this->assertStringEndsWith('/v1/images/edits', $request->url());
            $this->assertSame('max', $fields['quality']);

            return true;
        });
    }

    public function test_a_stored_quality_the_model_cannot_take_is_refused_before_any_request(): void
    {
        Http::fake();

        foreach (['generate', 'edit'] as $operation) {
            $cell = $this->cell('gpt-image-2', 'max', $operation);

            [$done, $reason] = app(DesignImageDirectRenderer::class)->renderNow($cell->id);

            $this->assertSame('failed', $reason, $operation);
            $this->assertSame(DesignImageStatus::FAILED->value, $done->status);
            $this->assertStringContainsString('khong dung duoc voi model gpt-image-2', (string) $done->render_error);
        }

        $unknown = $this->cell('gpt-image-2.5-flare', 'ultra', 'generate');
        [$done] = app(DesignImageDirectRenderer::class)->renderNow($unknown->id);
        $this->assertStringContainsString('Quality ultra khong hop le', (string) $done->render_error);

        Http::assertNothingSent();
    }

    public function test_a_padded_stored_quality_is_refused_rather_than_sent_as_high(): void
    {
        Http::fake();
        $cell = $this->cell('gpt-image-2.5-flare', ' max ', 'generate');

        [$done, $reason] = app(DesignImageDirectRenderer::class)->renderNow($cell->id);

        $this->assertSame('failed', $reason);
        $this->assertStringContainsString('Quality  max  khong hop le', (string) $done->render_error);
        Http::assertNothingSent();
    }

    public function test_a_max_reference_goes_through_store_renderer_and_edit_unchanged_and_unpriced(): void
    {
        Storage::fake('local');
        Http::fake(['*' => Http::response(['created' => 1, 'data' => [['b64_json' => self::PNG_3X5]]], 200)]);

        $anchorBytes = base64_decode(self::PNG_3X5);
        Storage::disk('local')->put('anchors/anchor.png', $anchorBytes);
        $anchor = $this->cell('gpt-image-2.5-flare', 'medium', 'generate');
        $artifact = VideoArtifact::create([
            'project_id' => $this->project->id,
            'design_image_id' => $anchor->id,
            'artifact_type' => 'image',
            'role' => 'candidate',
            'storage_disk' => 'local',
            'storage_path' => 'anchors/anchor.png',
            'mime_type' => 'image/png',
            'file_size' => strlen($anchorBytes),
            'sha256' => hash('sha256', $anchorBytes),
            'width' => 3,
            'height' => 5,
        ]);

        [$reference] = app(DesignImageStore::class)->createReference($this->project->id, 'admin', [
            'operation' => 'edit',
            'derivation' => 'gpt_edit',
            'source_image_id' => $anchor->id,
            'source_artifact_id' => $artifact->id,
            'source_artifact_sha256' => $artifact->sha256,
            'prompt' => 'Show the stern from the rear three-quarter.',
            'variations' => 1,
            'project_id' => $this->project->id,
            'image_type' => 'reference_view',
            'view_key' => 'rear_three_quarter',
            'environment' => 'studio',
            'slot_index' => 1,
            'identity_lock_id' => null,
            'identity_lock_hash' => null,
            'derivation_version' => \App\Video\Reference\IdentityPreservationPrompt::VERSION,
            'model' => 'gpt-image-2.5-sunburst',
            'quality' => 'max',
            'size' => '1152x2048',
        ]);

        $this->assertSame('unpriced', $reference->prompt_spec_json['pricing']);

        [$done, $reason] = app(DesignImageDirectRenderer::class)->renderNow($reference->id);

        $this->assertSame('rendered', $reason, (string) $done->render_error);
        Http::assertSent(function (ClientRequest $request): bool {
            $fields = collect($request->data())
                ->filter(static fn (array $part): bool => ! isset($part['filename']))
                ->mapWithKeys(static fn (array $part): array => [$part['name'] => $part['contents']]);

            $this->assertStringEndsWith('/v1/images/edits', $request->url());
            $this->assertSame('max', $fields['quality']);
            $this->assertSame('gpt-image-2.5-sunburst', $fields['model']);

            return true;
        });

        $entry = VideoCostEntry::query()->where('entity_id', $reference->id)->sole();
        $this->assertSame('unpriced', $entry->metadata_json['pricing']);
        $this->assertNull($entry->metadata_json['estimated_cost_usd'] ?? null, 'no estimate is borrowed for max');
    }

    private function cell(string $model, string $quality, string $operation): VideoDesignImage
    {
        return VideoDesignImage::create([
            'project_id' => $this->project->id,
            'image_code' => 'quality_'.uniqid(),
            'image_type' => 'identity_anchor',
            'prompt_spec_json' => [
                'prompt' => 'A yacht.',
                'operation' => $operation,
                'model' => $model,
                'quality' => $quality,
                'size' => '1536x1024',
                'variations' => 1,
                'pricing' => 'unpriced',
            ],
            'prompt_sha256' => hash('sha256', uniqid('', true)),
            'status' => 'candidate',
            'revision' => 1,
        ]);
    }
}
