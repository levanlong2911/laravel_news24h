<?php

namespace Tests\Feature\Video;

use App\Enums\DesignImageStatus;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\Admin;
use App\Models\Article;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\PythonRunner;
use App\Services\Video\PythonPromptCompiler;
use App\Services\VideoProjectService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AnchorImageRequestTest extends TestCase
{
    use DatabaseTransactions;

    private VideoProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = VideoProject::create(['title' => 'TEST anchor request '.uniqid()]);
        $this->actingAs(Admin::create([
            'name' => 'TEST anchor admin '.uniqid(),
            'email' => 'test_anchor_'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'role_id' => DB::table('roles')->value('id'),
        ]));
    }

    private function url(): string
    {
        return route('video-projects.anchor-image', $this->project->id);
    }

    /** @return array<string, string|int> */
    private function settings(array $override = []): array
    {
        return $override + [
            'model' => 'gpt-image-2',
            'quality' => 'medium',
            'variations' => 2,
            'prompt_sha256' => hash('sha256', 'CAMERA: front three-quarter.'),
        ];
    }

    public function test_a_setting_outside_the_enum_never_reaches_the_store(): void
    {
        $response = $this->from(route('video-projects.anchor', $this->project->id))
            ->post($this->url(), $this->settings(['quality' => 'ultra']));

        $response->assertSessionHasErrors('quality');
        $this->assertSame(0, VideoDesignImage::where('project_id', $this->project->id)->count());
    }

    public function test_a_missing_setting_is_reported_field_by_field(): void
    {
        $response = $this->from(route('video-projects.anchor', $this->project->id))->post($this->url(), []);

        $response->assertSessionHasErrors(['model', 'quality', 'variations', 'prompt_sha256']);
        $this->assertSame(0, VideoDesignImage::where('project_id', $this->project->id)->count());
    }

    public function test_a_variation_count_outside_the_enum_is_refused(): void
    {
        $response = $this->from(route('video-projects.anchor', $this->project->id))
            ->post($this->url(), $this->settings(['variations' => 5]));

        $response->assertSessionHasErrors('variations');
    }

    private function compilerReturns(string $prompt): void
    {
        $compiler = Mockery::mock(PythonPromptCompiler::class);
        $compiler->shouldReceive('compile')->andReturn([$prompt, '']);
        $this->instance(PythonPromptCompiler::class, $compiler);
        $this->forgetService();
    }

    /** @param array<string, mixed> $payload */
    private function workerReturns(array $payload, int $times = 1): void
    {
        $runner = Mockery::mock(PythonRunner::class);
        $runner->shouldReceive('runAndWait')->times($times)->andReturn([true, json_encode($payload)]);
        $this->instance(PythonRunner::class, $runner);
        $this->forgetService();
    }

    /** @return array<string, mixed> */
    private function renderItem(): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'artifact_sha256' => hash('sha256', 'anh '.uniqid()),
            'storage_path' => '/renders/design-images/'.uniqid().'/1.png',
            'bytes' => 812345,
            'width' => 1024,
            'height' => 1536,
            'cost' => 0.015,
            'render' => [
                'render_kind' => 'image',
                'provider' => 'openai',
                'model' => 'gpt-image-2',
                'sent_prompt' => 'CAMERA: front three-quarter.',
            ],
        ];
    }

    private function projectWithArticle(): VideoProject
    {
        $article = Article::create([
            'keyword_id' => DB::table('keywords')->value('id'),
            'category_id' => DB::table('categories')->value('id'),
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('', true)),
            'source_title' => 'TEST anchor source',
            'title' => 'TEST anchor article '.uniqid(),
            'slug' => 'test-anchor-article-'.uniqid(),
            'content' => 'noi dung test',
            'status' => 'pending',
        ]);

        return VideoProject::create([
            'title' => 'TEST anchor concept '.uniqid(),
            'article_id' => $article->id,
        ]);
    }

    private function projectWithConcept(): VideoProject
    {
        $project = $this->projectWithArticle();

        VideoPlanningStage::create([
            'project_id' => $project->id,
            'planning_revision' => 1,
            'stage' => PlanningStageName::CONCEPT->value,
            'status' => VideoPlanningStageStatus::SUCCEEDED->value,
            'input_json' => [],
            'input_hash' => hash('sha256', ''),
            'output_json' => ['design_identity' => ['subject' => 'a hull']],
        ]);

        return $project;
    }

    /** @return array{prompt:string,stage:string,viewpoint:string,size:string,compiled_at:string} */
    private function storePromptPreview(VideoProject $project, array $override = []): array
    {
        $preview = $override + [
            'prompt' => 'CAMERA: front three-quarter.',
            'stage' => 'fabrication_geometry_anchor',
            'viewpoint' => 'front_three_quarter',
            'size' => '1024x1536',
            'compiled_at' => now()->toIso8601String(),
        ];

        $preview['prompt_sha256'] = hash('sha256', $preview['prompt']);
        $project->metadata_json = ['anchor_prompt_preview' => $preview];
        $project->save();

        return $preview;
    }

    public function test_a_valid_submit_creates_a_cell_and_renders_it(): void
    {
        $project = $this->projectWithConcept();
        $this->storePromptPreview($project);
        $this->workerReturns(['ok' => true, 'error' => null, 'renders' => [$this->renderItem()]]);

        $response = $this->from(route('video-projects.anchor', $project->id))
            ->post(route('video-projects.anchor-image', $project->id), $this->settings());

        $images = VideoDesignImage::where('project_id', $project->id)->get();

        $response->assertRedirect(route('video-projects.anchor', $project->id))
            ->assertSessionHas('success');
        $this->assertCount(1, $images);
        $this->assertSame(DesignImageStatus::RENDERED->value, $images->first()->status);
        $this->assertSame('CAMERA: front three-quarter.', $images->first()->prompt_spec_json['prompt']);
        $this->assertSame('generate', $images->first()->prompt_spec_json['operation']);
    }

    public function test_the_breaker_stops_a_test_from_ever_reaching_the_provider(): void
    {
        // Chot nam o `PythonRunner` — cua DUY NHAT moi duong di qua de sinh tien
        // trinh Python. Gan vao tung renderer da thung hai lan trong hai ngay.
        $project = $this->projectWithConcept();
        $this->storePromptPreview($project);

        $this->assertFalse(config('video.python_runner_enabled'));

        $this->from(route('video-projects.anchor', $project->id))
            ->post(route('video-projects.anchor-image', $project->id), $this->settings())
            ->assertSessionHas('error');

        $cell = VideoDesignImage::where('project_id', $project->id)->firstOrFail();

        $this->assertSame(DesignImageStatus::FAILED->value, $cell->status);
        $this->assertStringContainsString('VIDEO_PYTHON_RUNNER', $cell->render_error);
    }

    public function test_a_failed_render_puts_the_reason_on_the_screen(): void
    {
        $project = $this->projectWithConcept();
        $this->storePromptPreview($project);
        $this->workerReturns([
            'ok' => false,
            'error' => 'openai tra 500 sau 3 lan thu',
            'renders' => [],
        ]);

        $this->from(route('video-projects.anchor', $project->id))
            ->post(route('video-projects.anchor-image', $project->id), $this->settings())
            ->assertSessionHas('error');

        $this->assertStringContainsString('openai tra 500 sau 3 lan thu', session('error'));
    }

    public function test_a_stale_submitted_prompt_is_refused_before_rendering(): void
    {
        $project = $this->projectWithConcept();
        $this->storePromptPreview($project, ['prompt' => 'CURRENT PROMPT']);

        $runner = Mockery::mock(PythonRunner::class);
        $runner->shouldNotReceive('runAndWait');
        $this->instance(PythonRunner::class, $runner);
        $this->forgetService();

        $this->from(route('video-projects.anchor', $project->id))
            ->post(route('video-projects.anchor-image', $project->id), $this->settings([
                'prompt_sha256' => hash('sha256', 'STALE PROMPT'),
            ]))
            ->assertRedirect(route('video-projects.anchor', $project->id))
            ->assertSessionHas('error', __('messages.anchor_prompt_stale'));

        $this->assertSame(0, VideoDesignImage::where('project_id', $project->id)->count());
    }

    public function test_an_unknown_project_is_named_as_such_not_blamed_on_the_category(): void
    {
        [$prompt, $reason] = app(VideoProjectService::class)
            ->compiledAnchorPrompt(
                (string) Str::uuid(),
                \App\Enums\AnchorStage::FINISHED_IDENTITY_ANCHOR,
                \App\Video\Concept\Viewpoint::FrontThreeQuarter,
                \App\Enums\ImageSize::LANDSCAPE,
            );

        $this->assertNull($prompt);
        $this->assertSame('project_not_found', $reason);
    }

    private function forgetService(): void
    {
        app()->forgetInstance(VideoProjectService::class);
    }

    public function test_a_project_without_a_category_is_told_why_instead_of_writing(): void
    {
        $response = $this->from(route('video-projects.anchor', $this->project->id))->post($this->url(), $this->settings());

        $response->assertRedirect(route('video-projects.anchor', $this->project->id));
        $response->assertSessionHas('error', __('messages.anchor_prompt_missing'));
        $this->assertSame(0, VideoDesignImage::where('project_id', $this->project->id)->count());
    }

    public function test_a_project_without_a_concept_is_told_why_instead_of_writing(): void
    {
        $project = $this->projectWithArticle();

        $response = $this->from(route('video-projects.anchor', $project->id))
            ->post(route('video-projects.anchor-image', $project->id), $this->settings());

        $response->assertRedirect(route('video-projects.anchor', $project->id));
        $response->assertSessionHas('error', __('messages.anchor_prompt_missing'));
        $this->assertSame(0, VideoDesignImage::where('project_id', $project->id)->count());
    }

    /**
     * Ghi lai MOI lenh Python thay vi gia lap ket qua: chi nhu vay moi thay duoc
     * tham so that su duoc truyen di, va day dung la cho hai duong tung lech nhau.
     *
     * @param  list<array{script:string,args:list<string>}>  $calls
     */
    private function recordingRunner(array &$calls): void
    {
        $runner = Mockery::mock(PythonRunner::class);
        $runner->shouldReceive('runAndWait')->andReturnUsing(
            function (string $script, array $args, ...$rest) use (&$calls) {
                $call = ['script' => $script, 'args' => $args];

                if ($script === 'compile_image_prompt.py') {
                    $fileArg = collect($args)->first(fn (string $arg) => str_starts_with($arg, '--render-plan-file='));
                    $call['plan'] = json_decode(file_get_contents(substr($fileArg, strlen('--render-plan-file='))), true);
                }

                $calls[] = $call;

                return $script === 'compile_image_prompt.py'
                    ? [true, json_encode([
                        'ok' => true,
                        // Prompt that phu thuoc tham so. Tra hang so lam hai goc
                        // nhin ra cung hash roi dedupe thanh mot.
                        'prompt' => 'COMPILED '.json_encode($call['plan']['image_prompt_request']),
                        'chars' => 8,
                        'operation' => 'generate',
                        'viewpoint' => $call['plan']['image_prompt_request']['viewpoint'],
                        'stage' => $call['plan']['image_prompt_request']['stage'] ?? null,
                        'output_size' => $call['plan']['image_prompt_request']['output_size'],
                    ])]
                    : [true, json_encode([
                        'ok' => true, 'error' => null, 'renders' => [$this->renderItem()],
                    ])];
            });

        $this->instance(PythonRunner::class, $runner);
        $this->forgetService();
    }

    /** @return array{0: list<string>, 1: string} [$compileArgs, $storedSize] */
    private function submitAt(string $size): array
    {
        $project = $this->projectWithConcept();
        $calls = [];
        $this->recordingRunner($calls);

        $this->post(
            route('video-projects.anchor-compile', $project->id),
            [
                'stage' => 'fabrication_geometry_anchor',
                'viewpoint' => 'front_three_quarter',
                'size' => $size,
            ],
        );

        $compile = array_values(array_filter(
            $calls, fn (array $c) => $c['script'] === 'compile_image_prompt.py',
        ));

        $this->assertCount(1, $compile, 'Prompt phai duoc bien dich dung mot lan');

        return [
            $compile[0]['args'],
            (string) $project->refresh()->metadata_json['anchor_prompt_preview']['size'],
        ];
    }

    public function test_the_prompt_is_compiled_for_the_canvas_the_render_will_use(): void
    {
        // Prompt mang mot khoi `OUTPUT CANVAS` ghi ro kich thuoc. Truoc ban va nay
        // khoi do LUON noi 1024x1536 vi compile() viet cung, con request thi gui
        // cai nguoi dung bam — nen chon Landscape la prompt mo ta sai chinh khung
        // no dang duoc ve vao.
        [$args, $size] = $this->submitAt('1536x1024');

        $this->assertContains('--width=1536', $args);
        $this->assertContains('--height=1024', $args);
        $this->assertSame('1536x1024', $size);
    }

    /**
     * Bay lua chon trong enum, khong phai hai. Mot bang anh xa viet tay o dau do
     * se quen dung cai it dung nhat.
     *
     * @dataProvider offeredsizes
     */
    public function test_every_offered_canvas_reaches_the_compiler_unchanged(
        string $value, int $width, int $height,
    ): void {
        [$args, $size] = $this->submitAt($value);

        $this->assertContains('--width='.$width, $args);
        $this->assertContains('--height='.$height, $args);
        $this->assertSame($value, $size);
    }

    /** @return array<string, array{string, int, int}> */
    public static function offeredsizes(): array
    {
        $cases = [];

        foreach (\App\Enums\ImageSize::cases() as $size) {
            $cases[$size->value] = [
                $size->value, $size->width(), $size->height(),
            ];
        }

        return $cases;
    }

    public function test_a_candidate_records_which_stage_built_its_prompt(): void
    {
        // Hai candidate trung model/quality/size van co the la hai anh khac han
        // neu chung duoc dung tu hai stage khac nhau.
        $project = $this->projectWithConcept();
        $this->storePromptPreview($project);
        $this->workerReturns(['ok' => true, 'error' => null, 'renders' => [$this->renderItem()]]);

        $this->post(route('video-projects.anchor-image', $project->id), $this->settings());

        $spec = VideoDesignImage::where('project_id', $project->id)->firstOrFail()->prompt_spec_json;

        $this->assertSame('fabrication_geometry_anchor', $spec['stage']);
    }

    public function test_the_selected_stage_reaches_the_python_command(): void
    {
        [$args] = $this->submitAt('1024x1536');

        $this->assertContains('--stage=fabrication_geometry_anchor', $args);
    }

    public function test_the_render_plan_file_carries_the_image_prompt_request_contract(): void
    {
        $project = $this->projectWithConcept();
        $calls = [];
        $this->recordingRunner($calls);

        $this->post(
            route('video-projects.anchor-compile', $project->id),
            [
                'stage' => 'fabrication_geometry_anchor',
                'viewpoint' => 'front_three_quarter',
                'size' => '1536x1024',
            ],
        );

        $compile = array_values(array_filter(
            $calls, fn (array $c) => $c['script'] === 'compile_image_prompt.py',
        ));

        $this->assertSame([
            'viewpoint' => 'front_three_quarter',
            'output_size' => ['width' => 1536, 'height' => 1024],
            'stage' => 'fabrication_geometry_anchor',
        ], $compile[0]['plan']['image_prompt_request']);
    }

    public function test_nothing_is_compiled_until_the_prompt_settings_are_chosen(): void
    {
        $project = $this->projectWithConcept();
        $calls = [];
        $this->recordingRunner($calls);

        $response = $this->get(route('video-projects.anchor', $project->id));

        $response->assertOk();
        $this->assertSame([], $calls);

        foreach (['model', 'quality', 'stage', 'viewpoint', 'size', 'variations'] as $field) {
            $this->assertMatchesRegularExpression(
                '/name="'.$field.'"[^>]*>\s*<option value=""\s+selected/',
                (string) $response->getContent(),
                $field,
            );
        }
    }

    public function test_the_prompt_compiles_once_stage_viewpoint_and_size_are_chosen(): void
    {
        // Bien dich CHI xay ra o nut Compile. Man hinh khong tu goi Python nua:
        // prompt no bay ra phai la ban da luu, neu khong thi hash gui len khong
        // co doi chung trong DB va moi luot Generate se bi tu choi `stale`.
        $project = $this->projectWithConcept();
        $calls = [];
        $this->recordingRunner($calls);

        $this->from(route('video-projects.anchor', $project->id))
            ->post(route('video-projects.anchor-compile', $project->id), [
                'stage' => 'finished_identity_anchor',
                'viewpoint' => 'side',
                'size' => '1536x1024',
            ]);

        $this->assertCount(1, $calls);
        $this->assertContains('--stage=finished_identity_anchor', $calls[0]['args']);
        $this->assertContains('--viewpoint=side', $calls[0]['args']);
        $this->assertContains('--width=1536', $calls[0]['args']);
        $this->assertContains('--height=1024', $calls[0]['args']);
    }

    public function test_a_compiled_prompt_survives_a_page_reload(): void
    {
        $project = $this->projectWithConcept();
        $calls = [];
        $this->recordingRunner($calls);

        $this->from(route('video-projects.anchor', $project->id))
            ->post(route('video-projects.anchor-compile', $project->id), [
                'stage' => 'finished_identity_anchor',
                'viewpoint' => 'side',
                'size' => '1536x1024',
            ])->assertRedirect(route('video-projects.anchor', $project->id));

        $project->refresh();
        $preview = $project->metadata_json['anchor_prompt_preview'] ?? [];

        $this->assertCount(1, $calls);
        $this->assertSame('finished_identity_anchor', $preview['stage'] ?? null);
        $this->assertSame('side', $preview['viewpoint'] ?? null);
        $this->assertSame('1536x1024', $preview['size'] ?? null);
        $this->assertStringContainsString('COMPILED', $preview['prompt'] ?? '');

        $calls = [];
        $html = $this->get(route('video-projects.anchor', $project->id))
            ->assertOk()
            ->getContent();

        $this->assertSame([], $calls, 'Reload must read the stored preview instead of compiling again.');
        $this->assertStringContainsString('COMPILED', $html);
        $this->assertStringContainsString('Saved compiled preview', $html);
    }

    public function test_generate_anchor_stays_locked_until_render_settings_are_chosen(): void
    {
        $project = $this->projectWithConcept();
        $calls = [];
        $this->recordingRunner($calls);

        $this->from(route('video-projects.anchor', $project->id))
            ->post(route('video-projects.anchor-compile', $project->id), [
                'stage' => 'finished_identity_anchor',
                'viewpoint' => 'front_three_quarter',
                'size' => '1536x1024',
            ])->assertRedirect(route('video-projects.anchor', $project->id));

        $html = $this->get(route('video-projects.anchor', $project->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="generateAnchorButton"', $html);
        $this->assertMatchesRegularExpression('/id="generateAnchorButton"[^>]*disabled/', $html);
    }

    public function test_two_viewpoints_of_one_concept_are_two_different_candidates(): void
    {
        // `IDENTITY_KEYS` KHONG liet ke viewpoint — no bam chinh chuoi prompt, ma
        // prompt chua khoi COMPOSITION / FRAMING. Test nay khoa bat bien do: neu
        // ai do go may quay khoi prompt, hai goc nhin se dedupe vao lam mot.
        $project = $this->projectWithConcept();
        $this->workerReturns(['ok' => true, 'error' => null, 'renders' => [$this->renderItem()]], 2);

        foreach (['front_three_quarter', 'side'] as $viewpoint) {
            $prompt = 'COMPILED '.$viewpoint;
            $this->storePromptPreview($project, [
                'prompt' => $prompt,
                'viewpoint' => $viewpoint,
            ]);

            $this->post(route('video-projects.anchor-image', $project->id),
                $this->settings(['prompt_sha256' => hash('sha256', $prompt)]));
        }

        $images = VideoDesignImage::where('project_id', $project->id)->get();

        $this->assertCount(2, $images);
        $this->assertNotSame($images[0]->prompt_sha256, $images[1]->prompt_sha256);
        $this->assertSame(
            ['front_three_quarter', 'side'],
            $images->pluck('prompt_spec_json.viewpoint')->sort()->values()->all(),
        );
    }

    public function test_a_submit_without_a_viewpoint_never_reaches_the_store(): void
    {
        $project = $this->projectWithConcept();
        $settings = $this->settings();
        unset($settings['viewpoint']);

        $this->from(route('video-projects.anchor', $project->id))
            ->post(route('video-projects.anchor-compile', $project->id), $settings)
            ->assertSessionHasErrors('viewpoint');

        $this->assertSame(0, VideoDesignImage::where('project_id', $project->id)->count());
    }

    public function test_a_junk_viewpoint_in_old_input_compiles_nothing(): void
    {
        $project = $this->projectWithConcept();
        $calls = [];
        $this->recordingRunner($calls);

        $this->withSession(['_old_input' => [
            'stage' => 'finished_identity_anchor',
            'viewpoint' => 'top_down; rm -rf /',
            'size' => '1536x1024',
        ]])->get(route('video-projects.anchor', $project->id))->assertOk();

        $this->assertSame([], $calls);
    }

    public function test_after_a_failed_submit_the_preview_follows_the_box_the_user_left_selected(): void
    {
        // `back()` sau loi validate dua nguoi dung ve dung route GET nay, va `old()`
        // giu lai o ho vua chon. O chon phai di theo, khong quay ve mac dinh —
        // nguoi dung khong phai chon lai tu dau chi vi mot truong khac bi loi.
        $project = $this->projectWithConcept();

        $response = $this->withSession(['_old_input' => [
            'stage' => 'finished_identity_anchor',
            'viewpoint' => 'front_three_quarter',
            'size' => '1536x1024',
        ]])->get(route('video-projects.anchor', $project->id));

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/value="1536x1024"\s+selected/', (string) $response->getContent(),
        );
    }

    public function test_after_a_failed_submit_the_preview_follows_the_stage_the_user_left_selected(): void
    {
        $project = $this->projectWithConcept();

        $response = $this->withSession(['_old_input' => [
            'stage' => 'finished_identity_anchor',
            'viewpoint' => 'front_three_quarter',
            'size' => '1536x1024',
        ]])->get(route('video-projects.anchor', $project->id));

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/value="finished_identity_anchor"\s+selected/', (string) $response->getContent(),
        );
    }

    public function test_the_geometry_stage_is_still_reachable_and_still_opens_landscape(): void
    {
        // Stage nay khong con la mac dinh, nhung no van phai chay duoc: no la o
        // kiem khoi luong than khi can, va khung ngang la ly do no ton tai.
        $project = $this->projectWithConcept();
        $calls = [];
        $this->recordingRunner($calls);

        $this->from(route('video-projects.anchor', $project->id))
            ->post(route('video-projects.anchor-compile', $project->id), [
                'stage' => 'fabrication_geometry_anchor',
                'viewpoint' => 'front_three_quarter',
                'size' => '1536x1024',
            ]);

        $compile = array_values(array_filter(
            $calls, fn (array $c) => $c['script'] === 'compile_image_prompt.py',
        ));

        $this->assertContains('--stage=fabrication_geometry_anchor', $compile[0]['args']);
        $this->assertContains('--width=1536', $compile[0]['args']);
        $this->assertContains('--height=1024', $compile[0]['args']);
    }

    public function test_old_size_wins_over_the_stage_default_canvas(): void
    {
        $project = $this->projectWithConcept();
        $calls = [];
        $this->recordingRunner($calls);

        $this->from(route('video-projects.anchor', $project->id))
            ->post(route('video-projects.anchor-compile', $project->id), [
                'stage' => 'fabrication_geometry_anchor',
                'viewpoint' => 'front_three_quarter',
                'size' => '1024x1536',
            ]);

        $compile = array_values(array_filter(
            $calls, fn (array $c) => $c['script'] === 'compile_image_prompt.py',
        ));

        $this->assertContains('--stage=fabrication_geometry_anchor', $compile[0]['args']);
        $this->assertContains('--width=1024', $compile[0]['args']);
        $this->assertContains('--height=1536', $compile[0]['args']);
    }

    public function test_a_junk_size_in_old_input_never_reaches_the_python_command(): void
    {
        // `old()` la dau vao nguoi dung. Ep kieu thang se day chuoi rac xuong tan
        // tham so dong lenh.
        $project = $this->projectWithConcept();
        $calls = [];
        $this->recordingRunner($calls);

        $this->withSession(['_old_input' => [
            'stage' => 'finished_identity_anchor',
            'viewpoint' => 'front_three_quarter',
            'size' => '99999x1; rm -rf /',
        ]])->get(route('video-projects.anchor', $project->id))->assertOk();

        $this->assertSame([], $calls);
    }

    public function test_a_stage_outside_the_enum_is_refused(): void
    {
        $project = $this->projectWithConcept();

        $response = $this->from(route('video-projects.anchor', $project->id))
            ->post(route('video-projects.anchor-compile', $project->id), $this->settings([
                'stage' => 'fabrication_anchor',
            ]));

        $response->assertSessionHasErrors('stage');
        $this->assertSame(0, VideoDesignImage::where('project_id', $project->id)->count());
    }
}
