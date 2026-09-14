<?php

namespace Tests\Feature\Video;

use App\Enums\DesignImageStatus;
use App\Models\Admin;
use App\Models\Article;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Services\Video\DesignImageStore;
use App\Video\Environment\EnvironmentPlatePrompt;
use App\Video\Media\MediaModelRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnvironmentPlateTest extends TestCase
{
    use DatabaseTransactions;

    private VideoProject $project;

    private Admin $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('video_artifacts');

        config([
            'video.render_mode' => 'direct',
            'video.openai_image.disk' => 'video_artifacts',
            'canonical_concept.openai.api_key' => 'test-key',
            'canonical_concept.openai.base_url' => 'https://api.openai.com',
        ]);

        [$categoryId, $slug] = $this->category();

        config(['video.environment.profiles.'.$slug => 'vessel_v2']);

        $this->owner = $this->admin();
        $this->project = VideoProject::create([
            'title' => 'TEST environment '.uniqid(),
            'article_id' => $this->article($categoryId),
            'admin_id' => $this->owner->id,
        ]);

        $this->actingAs($this->owner);
    }

    public function test_the_plate_prompt_carries_the_frame_the_place_and_all_three_prohibitions(): void
    {
        $text = EnvironmentPlatePrompt::text('A sealed white paint shed.');

        $this->assertStringContainsString('An empty location plate.', $text);
        $this->assertStringContainsString('A sealed white paint shed.', $text);
        $this->assertStringContainsString('no ship, yacht, boat, hull, vessel', $text);
        $this->assertStringContainsString('no vehicle, no crane load', $text);
        $this->assertStringContainsString('No person is the subject', $text);
    }

    public function test_an_empty_place_description_never_becomes_a_prompt(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EnvironmentPlatePrompt::text('   ');
    }

    public function test_the_screen_lists_every_environment_the_profile_declares(): void
    {
        $response = $this->get($this->url());

        $response->assertOk();

        foreach (['Design studio', 'Shipyard hall', 'Paint shed', 'Launch quay', 'Open water'] as $label) {
            $response->assertSee($label, false);
        }

        $response->assertSee('vessel_v2', false);
        $response->assertSee(EnvironmentPlatePrompt::VERSION, false);
    }

    public function test_each_environment_is_its_own_row_carrying_its_own_key(): void
    {
        $html = $this->get($this->url())->assertOk()->getContent();

        $keys = $this->profile()->environmentKeys();

        foreach ($keys as $key) {
            $this->assertStringContainsString(
                '<input type="hidden" name="environment_key" value="'.$key.'">', $html,
            );
            $this->assertStringContainsString('id="envForm_'.$key.'"', $html);
        }

        $this->assertSame(
            count($keys), substr_count($html, 'name="environment_key"'),
            'one environment key input per row, no shared picker',
        );
        $this->assertSame(count($keys), substr_count($html, '>Render Plate<'));
    }

    public function test_a_category_without_an_environment_profile_says_so_instead_of_offering_render(): void
    {
        [$categoryId, $slug] = $this->category();

        $bare = VideoProject::create([
            'title' => 'TEST environment bare '.uniqid(),
            'article_id' => $this->article($categoryId),
            'admin_id' => $this->owner->id,
        ]);

        $response = $this->get(route('video-projects.environment', $bare->id));

        $response->assertOk();
        $response->assertSee(__('messages.environment_no_profile'), false);
        $response->assertDontSee('Render Plate', false);
    }

    public function test_a_project_without_an_environment_profile_cannot_be_made_to_spend(): void
    {
        Http::fake();

        [$categoryId] = $this->category();

        $bare = VideoProject::create([
            'title' => 'TEST environment bare '.uniqid(),
            'article_id' => $this->article($categoryId),
            'admin_id' => $this->owner->id,
        ]);

        $this->post(route('video-projects.environment', $bare->id), $this->settings())
            ->assertSessionHas('error', __('messages.environment_no_profile'));

        $this->assertSame(0, VideoDesignImage::query()
            ->where('project_id', $bare->id)
            ->where('image_type', DesignImageStore::ENVIRONMENT_TYPE)
            ->count());
        Http::assertNothingSent();
    }

    public function test_every_render_operation_name_fits_the_column_that_records_it(): void
    {
        $column = DB::select('SHOW COLUMNS FROM `video_renders` LIKE "render_kind"')[0];

        preg_match('/varchar\((\d+)\)/', (string) $column->Type, $match);

        $limit = (int) ($match[1] ?? 0);
        $source = (string) file_get_contents(
            app_path('Services/Video/DesignImageDirectRenderer.php'),
        );

        preg_match_all("/^\s+'([a-z_]+)' => \\\$this->/m", $source, $arms);

        $this->assertGreaterThan(0, $limit);
        $this->assertNotEmpty($arms[1]);

        foreach ($arms[1] as $operation) {
            $this->assertLessThanOrEqual(
                $limit,
                strlen($operation),
                'render_kind cannot hold operation '.$operation,
            );
        }
    }

    public function test_a_rendered_plate_stores_its_environment_key_and_the_clean_plate_prompt(): void
    {
        $this->fakeGenerateReturns();

        $this->post($this->url(), $this->settings())->assertRedirect($this->url());

        $image = $this->plates()->sole();

        $this->assertSame(DesignImageStore::ENVIRONMENT_TYPE, $image->image_type);
        $this->assertSame('paint_shed', $image->environment_key);
        $this->assertSame(DesignImageStatus::RENDERED->value, $image->status);
        $this->assertSame('environment_plate', $image->prompt_spec_json['operation']);
        $this->assertSame(EnvironmentPlatePrompt::VERSION, $image->prompt_spec_json['spec_version']);
        $this->assertSame('vessel_v2', $image->prompt_spec_json['profile_version']);
        $this->assertStringContainsString(
            'sealed white walls', $image->prompt_spec_json['prompt'],
        );
        $this->assertStringContainsString(
            'no ship, yacht, boat, hull, vessel', $image->prompt_spec_json['prompt'],
        );
    }

    public function test_a_plate_goes_to_the_generate_endpoint_and_never_to_edits(): void
    {
        $this->fakeGenerateReturns();

        $this->post($this->url(), $this->settings());

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/images/generations'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/images/edits'));
    }

    public function test_the_prompt_that_reaches_the_provider_is_the_one_the_screen_showed(): void
    {
        $this->fakeGenerateReturns();

        $this->post($this->url(), $this->settings());

        $expected = EnvironmentPlatePrompt::text(
            (string) $this->profile()->environmentPromptOf('paint_shed'),
        );

        Http::assertSent(fn ($request) => ($request->data()['prompt'] ?? null) === $expected);
    }

    public function test_the_default_format_is_the_one_the_keyframe_chain_uses(): void
    {
        $default = app(MediaModelRegistry::class)->defaultFor(EnvironmentPlatePrompt::TASK);

        $this->assertSame('openai:gpt-image-2', $default['id']);
        $this->assertSame('1152x2048', $default['controls']['default_size']);
        $this->assertSame('low', $default['controls']['default_quality']);
    }

    public function test_every_row_offers_every_choice_the_registry_declares(): void
    {
        $html = $this->get($this->url())->assertOk()->getContent();

        $rows = count($this->profile()->environmentKeys());
        $offered = [];

        foreach (app(MediaModelRegistry::class)->forTask(EnvironmentPlatePrompt::TASK) as $entry) {
            $offered[$entry['id']] = ($offered[$entry['id']] ?? 0) + 1;

            foreach (['sizes', 'qualities', 'aspect_ratios', 'image_sizes'] as $list) {
                foreach ($entry['controls'][$list] ?? [] as $value) {
                    $offered[$value] = ($offered[$value] ?? 0) + 1;
                }
            }
        }

        foreach ($offered as $value => $groups) {
            $this->assertSame(
                $rows * $groups,
                substr_count($html, 'value="'.$value.'"'),
                'every row must offer '.$value,
            );
        }
    }

    public function test_the_old_auto_quality_is_no_longer_offered_or_accepted(): void
    {
        Http::fake();

        $this->get($this->url())->assertOk()->assertDontSee('value="auto"', false);

        $this->post($this->url(), $this->settings(['quality' => 'auto']))
            ->assertSessionHasErrors('quality');

        $this->assertSame(0, $this->plates()->count());
        Http::assertNothingSent();
    }

    public function test_every_setting_arrives_chosen_so_no_row_needs_a_placeholder(): void
    {
        $html = $this->get($this->url())->assertOk()->getContent();

        $rows = count($this->profile()->environmentKeys());

        $this->assertStringNotContainsString('<option value="">', $html);

        foreach ([
            'openai:gpt-image-2' => $rows,
            'low' => $rows,
            '1152x2048' => $rows,
            '9:16' => $rows * 3,
            '1K' => $rows * 3,
        ] as $default => $expected) {
            $this->assertSame($expected, preg_match_all(
                '/value="'.preg_quote($default, '/').'"[^>]*\sselected/',
                $html,
            ), 'default not preselected: '.$default);
        }
    }

    public function test_only_the_default_model_group_is_visible_and_submittable(): void
    {
        $html = $this->get($this->url())->assertOk()->getContent();

        preg_match_all('/<div class="ve-set" data-row="paint_shed" data-group="([^"]+)"[^>]*>/', $html, $groups);

        $this->assertCount(4, $groups[0]);

        foreach ($groups[0] as $index => $tag) {
            $default = $groups[1][$index] === 'openai:gpt-image-2';

            $this->assertSame(! $default, str_contains($tag, ' hidden'), 'group '.$groups[1][$index]);
        }

        $this->assertSame(
            count($this->profile()->environmentKeys()) * 3 * 3,
            substr_count($html, 'required disabled'),
            'each hidden Gemini group ships three disabled selects',
        );
    }

    public function test_each_model_group_offers_only_the_choices_its_own_model_declares(): void
    {
        $html = $this->get($this->url())->assertOk()->getContent();

        foreach (app(MediaModelRegistry::class)->forTask(EnvironmentPlatePrompt::TASK) as $entry) {
            if ($entry['provider'] !== 'gemini') {
                continue;
            }

            $group = $this->groupHtml($html, $entry['id']);

            foreach (['aspect_ratios', 'image_sizes'] as $list) {
                foreach ($entry['controls'][$list] as $value) {
                    $this->assertStringContainsString('value="'.$value.'"', $group, $entry['id'].' thieu '.$value);
                }
            }

            foreach (['7:9', '99:1'] as $never) {
                $this->assertStringNotContainsString('value="'.$never.'"', $group);
            }
        }

        $lite = $this->groupHtml($html, 'gemini:gemini-3.1-flash-lite-image');
        $pro = $this->groupHtml($html, 'gemini:gemini-3-pro-image');

        $this->assertStringNotContainsString('value="1:8"', $pro, 'Pro khong khai ti le cua Flash');
        $this->assertStringNotContainsString('value="2K"', $lite, 'Lite chi co 1K');
        $this->assertStringContainsString('value="4K"', $pro);
    }

    public function test_a_choice_without_a_real_render_behind_it_says_so(): void
    {
        $html = $this->get($this->url())->assertOk()->getContent();

        $lite = $this->groupHtml($html, 'gemini:gemini-3.1-flash-lite-image');
        $pro = $this->groupHtml($html, 'gemini:gemini-3-pro-image');

        $this->assertMatchesRegularExpression(
            '/value="9:16"[^>]*>9:16 · khớp keyframe</u', $lite,
            'ti le mac dinh cua Lite da co canary nen khong duoc dan nhan chua render thu',
        );
        $this->assertMatchesRegularExpression('/value="21:9"[^>]*>21:9 · chưa render thử</u', $lite);
        $this->assertMatchesRegularExpression('/value="1K"[^>]*>1K</u', $lite);
        $this->assertMatchesRegularExpression(
            '/value="9:16"[^>]*>9:16 · khớp keyframe · chưa render thử</u', $pro,
            'Pro chua render that lan nao, ke ca o kho mac dinh',
        );
        $this->assertMatchesRegularExpression('/value="4K"[^>]*>4K · chưa render thử</u', $pro);
    }

    private function groupHtml(string $html, string $id): string
    {
        $start = strpos($html, 'data-group="'.$id.'"');

        $this->assertNotFalse($start, 'khong thay nhom '.$id);

        $end = strpos($html, 'data-group="', $start + 1);

        return substr($html, $start, $end === false ? null : $end - $start);
    }

    public function test_each_row_carries_its_own_cost_box_wired_to_its_own_selects(): void
    {
        $html = $this->get($this->url())->assertOk()->getContent();

        $keys = $this->profile()->environmentKeys();

        foreach ($keys as $key) {
            $this->assertStringContainsString('id="est_'.$key.'"', $html);
            $this->assertSame(1, substr_count($html, 'data-row="'.$key.'" data-role="model"'));
            $this->assertSame(
                4,
                substr_count($html, 'data-row="'.$key.'" data-group="'),
                'one settings group per model must report to row '.$key,
            );
        }

        $this->assertSame(count($keys), substr_count($html, 'id="est_'));
    }

    public function test_the_screen_ships_the_cost_table_the_estimate_is_built_from(): void
    {
        $html = $this->get($this->url())->assertOk()->getContent();

        foreach (\App\Enums\ImageQuality::cases() as $quality) {
            $this->assertStringContainsString(
                '"'.$quality->value.'":'.$quality->estimatedCostUsd(), $html,
            );
        }
    }

    public function test_the_estimate_never_claims_to_account_for_size(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertSee('không theo khổ', false)
            ->assertSee('khổ lớn hơn sẽ đắt hơn', false);
    }

    public function test_a_row_submitted_untouched_renders_at_the_defaults(): void
    {
        $this->fakeGenerateReturns();

        $default = app(MediaModelRegistry::class)->defaultFor(EnvironmentPlatePrompt::TASK);

        $this->post($this->url(), [
            'environment_key' => 'paint_shed',
            'provider_model' => $default['id'],
            'quality' => $default['controls']['default_quality'],
            'size' => $default['controls']['default_size'],
            'variations' => 1,
        ])->assertRedirect($this->url());

        $spec = $this->plates()->sole()->prompt_spec_json;

        $this->assertSame('openai', $spec['provider']);
        $this->assertSame('gpt-image-2', $spec['model']);
        $this->assertSame('estimated', $spec['pricing']);
        $this->assertSame('1152x2048', $spec['size']);
        $this->assertSame('low', $spec['quality']);
        $this->assertSame(1, $spec['variations']);
    }

    public function test_a_row_may_be_rendered_at_any_size_the_registry_allows(): void
    {
        $this->fakeGenerateReturns();

        $this->post($this->url(), $this->settings([
            'size' => '1024x1024', 'quality' => 'high',
        ]))->assertRedirect($this->url());

        $spec = $this->plates()->sole()->prompt_spec_json;

        $this->assertSame('1024x1024', $spec['size']);
        $this->assertSame('high', $spec['quality']);
    }

    public function test_a_size_outside_the_registry_is_refused(): void
    {
        Http::fake();

        $this->post($this->url(), $this->settings(['size' => '999x999']))
            ->assertSessionHasErrors('size');

        $this->assertSame(0, $this->plates()->count());
        Http::assertNothingSent();
    }

    public function test_the_chosen_format_is_what_reaches_the_provider(): void
    {
        $this->fakeGenerateReturns();

        $this->post($this->url(), $this->settings());

        Http::assertSent(fn ($request) => ($request->data()['size'] ?? null) === '1152x2048'
            && ($request->data()['quality'] ?? null) === 'low');

        $image = $this->plates()->sole();

        $this->assertSame('1152x2048', $image->prompt_spec_json['size']);
        $this->assertSame('low', $image->prompt_spec_json['quality']);
    }

    public function test_an_environment_key_the_profile_never_declared_spends_nothing(): void
    {
        Http::fake();

        $this->post($this->url(), $this->settings(['environment_key' => 'dockyard']))
            ->assertSessionHas('error', __('messages.environment_unknown_key'));

        $this->assertSame(0, $this->plates()->count());
        Http::assertNothingSent();
    }

    public function test_a_malformed_environment_key_is_refused_by_the_form(): void
    {
        Http::fake();

        $this->post($this->url(), $this->settings(['environment_key' => 'Paint Shed']))
            ->assertSessionHasErrors('environment_key');

        $this->assertSame(0, $this->plates()->count());
        Http::assertNothingSent();
    }

    public function test_an_openai_plate_hashes_exactly_as_it_did_before_providers_existed(): void
    {
        $store = app(DesignImageStore::class);
        $legacy = $this->storeSpec();

        [$first, $created] = $store->createEnvironment($this->project->id, 'tester', $legacy);
        [$second, $reused] = $store->createEnvironment(
            $this->project->id, 'tester', $legacy + ['provider' => 'openai'],
        );

        $this->assertSame('created', $created);
        $this->assertSame('already_exists', $reused);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            $store->identityHash($legacy, ['operation', 'spec_version', 'environment_key']),
            (string) $first->prompt_sha256,
            'the nine plates already in the database must keep matching their own hash',
        );
    }

    public function test_the_same_request_under_another_provider_is_a_new_row(): void
    {
        $store = app(DesignImageStore::class);
        $openai = $this->storeSpec();
        $gemini = $openai + ['provider' => 'gemini'];

        [$first] = $store->createEnvironment($this->project->id, 'tester', $openai);
        [$second, $reason] = $store->createEnvironment($this->project->id, 'tester', $gemini);

        $this->assertSame('created', $reason);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(
            $store->identityHash($gemini, ['operation', 'spec_version', 'environment_key', 'provider']),
            (string) $second->prompt_sha256,
        );
    }

    public function test_an_unpriced_plate_never_shows_a_dollar_figure(): void
    {
        [$image] = $this->renderedPlate('paint_shed');

        $image->forceFill(['prompt_spec_json' => array_replace(
            $image->prompt_spec_json, ['pricing' => 'unpriced', 'provider' => 'gemini', 'quality' => null],
        )])->save();

        DB::table('video_cost_entries')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $this->project->id,
            'entity_type' => 'design_image',
            'entity_id' => $image->id,
            'provider' => 'gemini',
            'model' => 'gemini-3.1-flash-lite-image',
            'usage_type' => 'environment_plate',
            'quantity' => 1,
            'unit' => 'render',
            'cost_usd' => 0,
            'metadata_json' => json_encode(['pricing' => 'unpriced']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get($this->url())
            ->assertOk()
            ->assertSee('chưa định giá', false)
            ->assertSee('1 ảnh &middot; không áp dụng', false)
            ->assertDontSee('$0.000', false);
    }

    /** @return array<string, mixed> */
    private function storeSpec(): array
    {
        return [
            'project_id' => $this->project->id,
            'operation' => 'environment_plate',
            'spec_version' => EnvironmentPlatePrompt::VERSION,
            'environment_key' => 'paint_shed',
            'prompt' => EnvironmentPlatePrompt::text('A sealed white paint shed.'),
            'model' => 'gpt-image-2',
            'quality' => 'low',
            'size' => '1152x2048',
            'variations' => 1,
        ];
    }

    public function test_the_same_plate_asked_for_twice_reuses_the_row_instead_of_paying_again(): void
    {
        $this->fakeGenerateReturns();

        $this->post($this->url(), $this->settings());
        $this->post($this->url(), $this->settings());

        $this->assertSame(1, $this->plates()->count());
        Http::assertSentCount(1);
    }

    public function test_the_same_environment_at_another_quality_is_a_new_row(): void
    {
        $this->fakeGenerateReturns();

        $this->post($this->url(), $this->settings());
        $this->post($this->url(), $this->settings(['quality' => 'high']));

        $this->assertSame(
            ['high', 'low'],
            $this->plates()->get()
                ->map(fn ($image) => $image->prompt_spec_json['quality'])
                ->sort()->values()->all(),
        );
    }

    public function test_the_same_environment_at_another_size_is_a_new_row(): void
    {
        $this->fakeGenerateReturns();

        $this->post($this->url(), $this->settings());
        $this->post($this->url(), $this->settings(['size' => '1024x1024']));

        $this->assertSame(
            ['1024x1024', '1152x2048'],
            $this->plates()->get()
                ->map(fn ($image) => $image->prompt_spec_json['size'])
                ->sort()->values()->all(),
        );
    }

    public function test_two_different_environments_are_two_different_rows(): void
    {
        $this->fakeGenerateReturns();

        $this->post($this->url(), $this->settings());
        $this->post($this->url(), $this->settings(['environment_key' => 'open_water']));

        $this->assertSame(
            ['open_water', 'paint_shed'],
            $this->plates()->pluck('environment_key')->sort()->values()->all(),
        );
    }

    public function test_a_rendered_plate_can_be_approved_from_its_own_route(): void
    {
        [$image, $artifact] = $this->renderedPlate('paint_shed');

        $this->post($this->approveUrl(), ['artifact_id' => $artifact->id])
            ->assertSessionHas('success', __('messages.reference_approved'));

        $image->refresh();

        $this->assertSame(DesignImageStatus::APPROVED->value, $image->status);
        $this->assertSame($artifact->id, $image->selected_artifact_id);
    }

    public function test_approving_a_plate_never_supersedes_a_different_environment(): void
    {
        [$hall, $hallArtifact] = $this->renderedPlate('shipyard_hall');
        [, $shedArtifact] = $this->renderedPlate('paint_shed');

        $this->post($this->approveUrl(), ['artifact_id' => $hallArtifact->id]);
        $this->post($this->approveUrl(), ['artifact_id' => $shedArtifact->id]);

        $this->assertSame(DesignImageStatus::APPROVED->value, $hall->refresh()->status);
    }

    public function test_approving_a_second_plate_of_the_same_environment_supersedes_the_first(): void
    {
        [$first, $firstArtifact] = $this->renderedPlate('paint_shed');
        [, $secondArtifact] = $this->renderedPlate('paint_shed');

        $this->post($this->approveUrl(), ['artifact_id' => $firstArtifact->id]);
        $this->post($this->approveUrl(), ['artifact_id' => $secondArtifact->id]);

        $this->assertSame(DesignImageStatus::SUPERSEDED->value, $first->refresh()->status);
    }

    public function test_the_environment_route_refuses_an_artifact_that_is_not_a_plate(): void
    {
        $anchor = VideoDesignImage::create([
            'project_id' => $this->project->id,
            'image_code' => 'anchor_'.uniqid(),
            'image_type' => DesignImageStore::ANCHOR_TYPE,
            'prompt_spec_json' => ['prompt' => 'x', 'model' => 'gpt-image-2',
                'quality' => 'low', 'size' => '1152x2048', 'variations' => 1],
            'prompt_sha256' => hash('sha256', uniqid('', true)),
            'status' => DesignImageStatus::RENDERED->value,
            'revision' => 1,
        ]);

        $artifact = $this->artifactFor($anchor);

        $this->post($this->approveUrl(), ['artifact_id' => $artifact->id])
            ->assertSessionHas('error', __('messages.reference_wrong_image_type'));

        $this->assertNull($anchor->refresh()->selected_artifact_id);
    }

    public function test_another_member_cannot_spend_money_on_someone_elses_project(): void
    {
        Http::fake();

        $this->actingAs($this->admin());

        $this->post($this->url(), $this->settings())->assertForbidden();

        $this->assertSame(0, $this->plates()->count());
        Http::assertNothingSent();
    }

    private function profile(): \App\Video\Scene\SceneProfile
    {
        return \App\Video\Scene\SceneProfile::load(
            (string) config('video.scene_plan.profile_dir'), 'vessel_v2',
        );
    }

    private function url(): string
    {
        return route('video-projects.environment', $this->project->id);
    }

    private function approveUrl(): string
    {
        return route('video-projects.environment-approve', $this->project->id);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<VideoDesignImage> */
    private function plates()
    {
        return VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', DesignImageStore::ENVIRONMENT_TYPE);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function settings(array $override = []): array
    {
        return $override + [
            'environment_key' => 'paint_shed',
            'provider_model' => 'openai:gpt-image-2',
            'quality' => 'low',
            'size' => '1152x2048',
            'variations' => 1,
        ];
    }

    /** @return array{0: VideoDesignImage, 1: VideoArtifact} */
    private function renderedPlate(string $key): array
    {
        $image = VideoDesignImage::create([
            'project_id' => $this->project->id,
            'image_code' => 'env_'.uniqid(),
            'image_type' => DesignImageStore::ENVIRONMENT_TYPE,
            'environment_key' => $key,
            'prompt_spec_json' => [
                'operation' => 'environment_plate',
                'spec_version' => EnvironmentPlatePrompt::VERSION,
                'environment_key' => $key,
                'prompt' => EnvironmentPlatePrompt::text('A place called '.$key.'.'),
                'model' => 'gpt-image-2',
                'quality' => 'low',
                'size' => '1152x2048',
                'variations' => 1,
            ],
            'prompt_sha256' => hash('sha256', uniqid('', true)),
            'status' => DesignImageStatus::RENDERED->value,
            'revision' => 1,
        ]);

        return [$image, $this->artifactFor($image)];
    }

    private function artifactFor(VideoDesignImage $image): VideoArtifact
    {
        $bytes = 'plate-'.uniqid();
        $path = 'plates/'.uniqid().'.png';

        Storage::disk('video_artifacts')->put($path, $bytes);

        return VideoArtifact::create([
            'project_id' => $this->project->id,
            'design_image_id' => $image->id,
            'artifact_type' => 'image',
            'role' => 'candidate',
            'storage_disk' => 'video_artifacts',
            'storage_path' => $path,
            'mime_type' => 'image/png',
            'sha256' => hash('sha256', $bytes),
            'width' => 1024,
            'height' => 1024,
        ]);
    }

    private function fakeGenerateReturns(): void
    {
        Http::fake([
            '*/v1/images/generations' => Http::response([
                'created' => 1,
                'size' => '1152x2048',
                'quality' => 'low',
                'output_format' => 'png',
                'usage' => ['total_tokens' => 10],
                'data' => [['b64_json' => base64_encode($this->platePng())]],
            ], 200),
        ]);
    }

    private function platePng(): string
    {
        $canvas = imagecreatetruecolor(8, 8);
        imagefilledrectangle($canvas, 0, 0, 7, 7, imagecolorallocate($canvas, 200, 200, 200));

        ob_start();
        imagepng($canvas);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        return $bytes;
    }

    /** @return array{0: string, 1: string} */
    private function category(): array
    {
        $id = (string) Str::uuid();
        $slug = 'test-env-'.uniqid();

        DB::table('categories')->insert([
            'id' => $id,
            'name' => 'TEST environment category '.uniqid(),
            'slug' => $slug,
        ]);

        return [$id, $slug];
    }

    private function article(string $categoryId): string
    {
        $keywordId = (string) Str::uuid();

        DB::table('keywords')->insert([
            'id' => $keywordId,
            'name' => 'TEST environment keyword '.uniqid(),
            'category_id' => $categoryId,
        ]);

        return (string) Article::create([
            'keyword_id' => $keywordId,
            'category_id' => $categoryId,
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('', true)),
            'source_title' => 'TEST environment source',
            'title' => 'TEST environment article '.uniqid(),
            'slug' => 'test-environment-'.uniqid(),
            'content' => 'A yard begins a new steel motor yacht.',
            'status' => 'pending',
        ])->id;
    }

    private function admin(string $role = 'member'): Admin
    {
        $roleId = (string) Str::uuid();

        DB::table('roles')->insert(['id' => $roleId, 'name' => $role]);

        return Admin::create([
            'name' => 'TEST environment admin '.uniqid(),
            'email' => 'test_environment_'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'role_id' => $roleId,
        ]);
    }
}
