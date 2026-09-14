<?php

namespace Tests\Feature\Video;

use App\Video\Environment\EnvironmentPlatePrompt;
use App\Video\Media\MediaModelRegistry;
use InvalidArgumentException;
use Tests\TestCase;

class MediaModelRegistryTest extends TestCase
{
    private MediaModelRegistry $registry;

    private ?string $scratch = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new MediaModelRegistry;
    }

    protected function tearDown(): void
    {
        if ($this->scratch !== null && is_dir($this->scratch)) {
            foreach (glob($this->scratch.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->scratch);
        }

        parent::tearDown();
    }

    public function test_the_environment_task_offers_exactly_four_models_in_order(): void
    {
        $this->assertSame(
            [
                'openai:gpt-image-2',
                'gemini:gemini-3.1-flash-lite-image',
                'gemini:gemini-3.1-flash-image',
                'gemini:gemini-3-pro-image',
            ],
            array_column($this->registry->forTask(EnvironmentPlatePrompt::TASK), 'id'),
        );
    }

    public function test_openai_stays_the_default_until_gemini_has_rendered_for_real(): void
    {
        $this->assertSame('openai:gpt-image-2', $this->registry->defaultFor(EnvironmentPlatePrompt::TASK)['id']);
    }

    public function test_no_legacy_preview_or_alias_model_is_offered(): void
    {
        foreach (array_column($this->registry->forTask(EnvironmentPlatePrompt::TASK), 'model') as $model) {
            $this->assertNotSame('gemini-2.5-flash-image', $model);
            $this->assertStringNotContainsString('-preview', $model);
            $this->assertStringNotContainsString('nano-banana', $model);
        }
    }

    public function test_every_gemini_model_is_visible_to_the_key_at_its_api_version(): void
    {
        foreach ($this->geminiEntries() as $entry) {
            $models = $this->evidence($entry['evidence']['models']);
            $names = array_map(
                static fn (array $m): string => str_replace('models/', '', (string) $m['name']),
                $models['versions'][$entry['api_version']] ?? [],
            );

            $this->assertContains(
                $entry['model'],
                $names,
                "{$entry['model']} is not in {$entry['evidence']['models']} at {$entry['api_version']}",
            );
        }
    }

    public function test_every_gemini_model_uses_a_shape_the_api_accepted(): void
    {
        foreach ($this->geminiEntries() as $entry) {
            $probe = $this->evidence($entry['evidence']['image_config']);

            $this->assertSame('image_config', $entry['shape']);
            $this->assertSame(
                'field_accepted',
                $probe['results'][$entry['api_version']]['imageConfig']['verdict'] ?? null,
                "{$entry['evidence']['image_config']} does not show imageConfig accepted at {$entry['api_version']}",
            );
        }
    }

    public function test_a_missing_evidence_file_fails_the_registry_itself(): void
    {
        $this->withEvidence(1, ['models' => 'gemini_models_1999_01_01.json'], 'thieu file bang chung');
    }

    public function test_a_model_absent_from_the_listing_is_refused(): void
    {
        $dir = $this->scratchEvidence();
        $models = json_decode((string) file_get_contents($dir.'/gemini_models_2026_09_12.json'), true);
        $models['versions']['v1'] = array_values(array_filter(
            $models['versions']['v1'],
            static fn (array $m): bool => $m['name'] !== 'models/gemini-3.1-flash-lite-image',
        ));
        file_put_contents($dir.'/gemini_models_2026_09_12.json', json_encode($models));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('gemini-3.1-flash-lite-image khong co trong');

        $this->registry->forTask(EnvironmentPlatePrompt::TASK);
    }

    public function test_a_shape_the_probe_did_not_accept_is_refused(): void
    {
        $dir = $this->scratchEvidence();
        $probe = json_decode((string) file_get_contents($dir.'/gemini_image_config_2026_09_13.json'), true);
        $probe['results']['v1']['imageConfig']['verdict'] = 'value_rejected';
        file_put_contents($dir.'/gemini_image_config_2026_09_13.json', json_encode($probe));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'value_rejected', khong phai field_accepted");

        $this->registry->forTask(EnvironmentPlatePrompt::TASK);
    }

    public function test_a_choice_the_manifest_never_reviewed_is_refused(): void
    {
        $this->withManifest(
            static function (array $manifest): array {
                unset($manifest['models']['gemini-3.1-flash-lite-image']['aspect_ratios']['21:9']);

                return $manifest;
            },
            'manifest khong khai aspect_ratios.21:9',
        );
    }

    /** @return iterable<string, array{0: string}> */
    public static function reviewFields(): iterable
    {
        yield 'source' => ['source'];
        yield 'reviewed_by' => ['reviewed_by'];
        yield 'reviewed_at' => ['reviewed_at'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reviewFields')]
    public function test_a_choice_without_a_signed_review_is_refused(string $field): void
    {
        $this->withManifest(
            static function (array $manifest) use ($field): array {
                $manifest['models']['gemini-3.1-flash-lite-image']['aspect_ratios']['9:16'][$field] = '  ';

                return $manifest;
            },
            "aspect_ratios.9:16 thieu {$field} trong manifest",
        );
    }

    public function test_a_model_the_manifest_never_mentions_is_refused(): void
    {
        $this->withManifest(
            static function (array $manifest): array {
                unset($manifest['models']['gemini-3-pro-image']);

                return $manifest;
            },
            'khong khai gemini-3-pro-image',
        );
    }

    public function test_a_gemini_entry_without_a_capability_manifest_is_refused(): void
    {
        $entries = config('video.media_models.image.environment_plate');
        unset($entries[1]['evidence']['capabilities']);
        config(['video.media_models.image.environment_plate' => $entries]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('thieu evidence.capabilities');

        $this->registry->forTask(EnvironmentPlatePrompt::TASK);
    }

    public function test_only_a_value_with_a_canary_render_counts_as_proven(): void
    {
        $entries = $this->geminiEntries();

        $this->assertSame(['9:16'], $entries[0]['controls']['proven_aspect_ratios']);
        $this->assertSame(['1K'], $entries[0]['controls']['proven_image_sizes']);
        $this->assertSame(['9:16'], $entries[1]['controls']['proven_aspect_ratios']);
        $this->assertSame([], $entries[2]['controls']['proven_aspect_ratios'], 'Pro chua render that lan nao');
        $this->assertSame([], $entries[2]['controls']['proven_image_sizes']);
    }

    public function test_a_canary_render_id_that_is_blank_proves_nothing(): void
    {
        $dir = $this->scratchEvidence();
        $manifest = json_decode((string) file_get_contents($dir.'/gemini_image_capabilities.json'), true);
        $manifest['models']['gemini-3.1-flash-lite-image']['aspect_ratios']['9:16']['canary_render_id'] = '   ';
        file_put_contents($dir.'/gemini_image_capabilities.json', json_encode($manifest));

        $this->assertSame(
            [],
            (new MediaModelRegistry)->find(EnvironmentPlatePrompt::TASK, 'gemini:gemini-3.1-flash-lite-image')['controls']['proven_aspect_ratios'],
        );
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutate */
    private function withManifest(callable $mutate, string $message): void
    {
        $dir = $this->scratchEvidence();
        $path = $dir.'/gemini_image_capabilities.json';

        file_put_contents($path, json_encode($mutate(
            json_decode((string) file_get_contents($path), true),
        )));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->registry->forTask(EnvironmentPlatePrompt::TASK);
    }

    public function test_an_evidence_file_that_is_not_json_is_refused(): void
    {
        $dir = $this->scratchEvidence();
        file_put_contents($dir.'/gemini_models_2026_09_12.json', 'not json');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('khong phai JSON hop le');

        $this->registry->forTask(EnvironmentPlatePrompt::TASK);
    }

    public function test_an_evidence_name_carrying_a_path_is_refused(): void
    {
        $this->withEvidence(1, ['models' => '../gemini_models_2026_09_12.json'], 'ten file bang chung khong hop le');
    }

    public function test_evidence_that_is_not_an_array_is_refused(): void
    {
        $this->withEntry(1, ['evidence' => 'gemini_models_2026_09_12.json'], 'evidence phai la mang');
    }

    public function test_evidence_is_verified_once_per_registry(): void
    {
        $dir = $this->scratchEvidence();

        $first = $this->registry->forTask(EnvironmentPlatePrompt::TASK);

        unlink($dir.'/gemini_models_2026_09_12.json');

        $this->assertSame($first, $this->registry->forTask(EnvironmentPlatePrompt::TASK));
    }

    public function test_openai_offers_every_size_the_app_knows(): void
    {
        $openai = $this->registry->defaultFor(EnvironmentPlatePrompt::TASK);

        $this->assertEqualsCanonicalizing(\App\Enums\ImageSize::values(), $openai['controls']['sizes']);
        $this->assertSame('1152x2048', $openai['controls']['default_size']);
    }

    public function test_openai_offers_low_medium_high_and_never_auto(): void
    {
        $openai = $this->registry->defaultFor(EnvironmentPlatePrompt::TASK);

        $this->assertSame(['low', 'medium', 'high'], $openai['controls']['qualities']);
        $this->assertSame('low', $openai['controls']['default_quality']);
        $this->assertSame(2, $openai['max_variations']);
    }

    /** @param array<string, mixed> $controls */
    private function withControls(int $index, array $controls, string $message): void
    {
        $entries = config('video.media_models.image.environment_plate');
        $entries[$index]['controls'] = array_replace($entries[$index]['controls'], $controls);
        config(['video.media_models.image.environment_plate' => $entries]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->registry->forTask(EnvironmentPlatePrompt::TASK);
    }

    /** @param array<string, string> $evidence */
    private function withEvidence(int $index, array $evidence, string $message): void
    {
        $entries = config('video.media_models.image.environment_plate');
        $entries[$index]['evidence'] = array_replace($entries[$index]['evidence'], $evidence);
        config(['video.media_models.image.environment_plate' => $entries]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->registry->forTask(EnvironmentPlatePrompt::TASK);
    }

    private function scratchEvidence(): string
    {
        $this->scratch = storage_path('framework/testing/registry_evidence_'.uniqid());
        mkdir($this->scratch, 0775, true);

        foreach (['gemini_models_2026_09_12.json', 'gemini_image_config_2026_09_13.json', 'gemini_image_capabilities.json'] as $file) {
            copy(resource_path('ai/providers/'.$file), $this->scratch.'/'.$file);
        }

        config(['video.gemini.evidence_dir' => $this->scratch]);

        return $this->scratch;
    }

    public function test_building_the_registry_never_reads_a_broken_config(): void
    {
        config(['video.media_models.image.environment_plate' => 'broken']);

        $this->assertInstanceOf(MediaModelRegistry::class, new MediaModelRegistry);
        $this->assertInstanceOf(MediaModelRegistry::class, app(MediaModelRegistry::class));
    }

    public function test_an_unknown_task_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry->forTask('scene_keyframe');
    }

    public function test_two_defaults_are_refused(): void
    {
        $this->withEntry(1, ['default' => true]);
    }

    public function test_a_repeated_id_is_refused(): void
    {
        $entries = config('video.media_models.image.environment_plate');
        $entries[] = $entries[1];
        config(['video.media_models.image.environment_plate' => $entries]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('id lap lai');

        $this->registry->forTask(EnvironmentPlatePrompt::TASK);
    }

    public function test_an_id_that_is_not_provider_colon_model_is_refused(): void
    {
        $this->withEntry(0, ['id' => 'gpt-image-2'], 'provider:model');
    }

    public function test_an_unknown_provider_is_refused(): void
    {
        $this->withEntry(0, ['provider' => 'fal', 'id' => 'fal:gpt-image-2'], 'provider la fal');
    }

    public function test_a_default_size_outside_the_sizes_is_refused(): void
    {
        $entries = config('video.media_models.image.environment_plate');
        $entries[0]['controls']['default_size'] = '999x999';
        config(['video.media_models.image.environment_plate' => $entries]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('default_size');

        $this->registry->forTask(EnvironmentPlatePrompt::TASK);
    }

    public function test_a_choice_list_with_an_empty_value_is_refused(): void
    {
        $this->withControls(0, ['sizes' => [''], 'default_size' => ''], 'gia tri rong');
    }

    public function test_a_choice_list_that_is_not_a_list_is_refused(): void
    {
        $this->withControls(0, ['qualities' => ['a' => 'low']], 'thieu controls.qualities');
    }

    public function test_a_choice_list_with_a_non_string_value_is_refused(): void
    {
        $this->withControls(1, ['image_sizes' => [1], 'default_image_size' => 1], 'khong phai chuoi');
    }

    public function test_a_choice_list_with_a_repeated_value_is_refused(): void
    {
        $this->withControls(0, ['qualities' => ['low', 'low']], 'gia tri lap');
    }

    public function test_gemini_may_not_claim_an_estimated_price(): void
    {
        $this->withEntry(1, ['pricing' => 'estimated'], 'chua co bang gia');
    }

    public function test_gemini_may_not_offer_more_than_one_variation(): void
    {
        $this->withEntry(1, ['max_variations' => 2], '1 anh moi luot');
    }

    public function test_gemini_may_not_use_a_shape_without_evidence(): void
    {
        $this->withEntry(1, ['shape' => 'response_format_image'], 'image_config');
    }

    /** @param array<string, mixed> $override */
    private function withEntry(int $index, array $override, string $message = ''): void
    {
        $entries = config('video.media_models.image.environment_plate');
        $entries[$index] = array_replace($entries[$index], $override);
        config(['video.media_models.image.environment_plate' => $entries]);

        $this->expectException(InvalidArgumentException::class);

        if ($message !== '') {
            $this->expectExceptionMessage($message);
        }

        $this->registry->forTask(EnvironmentPlatePrompt::TASK);
    }

    /** @return list<array<string, mixed>> */
    private function geminiEntries(): array
    {
        $entries = array_values(array_filter(
            $this->registry->forTask(EnvironmentPlatePrompt::TASK),
            static fn (array $entry): bool => $entry['provider'] === 'gemini',
        ));

        $this->assertCount(3, $entries);

        return $entries;
    }

    /** @return array<string, mixed> */
    private function evidence(string $file): array
    {
        $path = resource_path('ai/providers/'.$file);

        $this->assertFileExists($path, 'evidence file is missing: '.$file);

        $data = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($data, 'evidence file is not JSON: '.$file);

        return $data;
    }
}
