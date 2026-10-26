<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AiPhase;
use App\Models\VideoPlanningStage;
use App\Services\Video\ModelSheetQaService;
use App\Services\Video\PlanningStageStore;
use App\Video\Concept\Claude\AnthropicStructuredOutputClient;
use App\Video\Concept\Exceptions\AnthropicRequestException;
use App\Video\Concept\Exceptions\AnthropicTruncatedOutputException;
use App\Video\ModelSheet\ModelSheetFacts;
use App\Video\ModelSheet\ModelSheetQaSchema;
use App\Video\ModelSheet\ModelSheetQaValidator;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\TestCase;

/**
 * QA model sheet: cach gui request (khong ep grammar mac dinh), kiem cau truc bang Opis truoc kiem
 * nghiep vu, dieu kien ready va khoa cache. HTTP gia, du lieu trong bo nho; khong DB, khong API that.
 */
final class ModelSheetQaTransportTest extends TestCase
{
    private ?Container $previous = null;

    private array $design;

    private array $snapshot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        $this->useConfig(false);
        $this->design = self::design();
        $this->snapshot = $this->facts()->snapshot();
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
        parent::tearDown();
    }

    // ---- Request ----

    public function test_default_request_sends_no_grammar_but_keeps_effort(): void
    {
        [$client, $http] = $this->client(self::ok('{}'), 'medium');
        $client->create('claude-sonnet-5-5', 'system', [['role' => 'user', 'content' => 'x']], [], 100);
        $body = $this->body($http);
        self::assertArrayNotHasKey('format', $body['output_config'] ?? []);
        self::assertSame('medium', $body['output_config']['effort'] ?? null);
    }

    public function test_default_request_without_effort_sends_no_output_config(): void
    {
        [$client, $http] = $this->client(self::ok('{}'), null);
        $client->create('claude-sonnet-5-5', 'system', [['role' => 'user', 'content' => 'x']], [], 100);
        self::assertArrayNotHasKey('output_config', $this->body($http));
    }

    public function test_request_with_schema_still_sends_the_schema(): void
    {
        [$client, $http] = $this->client(self::ok('{}'), 'medium');
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['a'], 'properties' => ['a' => ['type' => 'string']]];
        $client->create('claude-sonnet-5-5', 'system', [['role' => 'user', 'content' => 'x']], $schema, 100);
        $body = $this->body($http);
        self::assertSame(['type' => 'json_schema', 'schema' => $schema], $body['output_config']['format']);
        self::assertSame('medium', $body['output_config']['effort']);
    }

    public function test_truncated_answer_keeps_usage_and_sends_one_request(): void
    {
        [$client, $http] = $this->client(['content' => [['type' => 'text', 'text' => '{"shared"']], 'stop_reason' => 'max_tokens', 'model' => 'claude-sonnet-5-5', 'usage' => ['input_tokens' => 1200, 'output_tokens' => 32000]], 'medium');

        try {
            $client->create('claude-sonnet-5-5', 'system', [['role' => 'user', 'content' => 'x']], [], 32000);
            self::fail('Truncation must throw.');
        } catch (AnthropicTruncatedOutputException $e) {
            self::assertSame(1200, $e->response?->inputTokens);
            self::assertSame(32000, $e->response?->outputTokens);
        }

        self::assertCount(1, $http->recorded());
    }

    public function test_api_error_is_reported_and_not_retried(): void
    {
        [$client, $http] = $this->client(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'The compiled grammar is too large']], 'medium', 400);

        try {
            $client->create('claude-sonnet-5-5', 'system', [['role' => 'user', 'content' => 'x']], [], 100);
            self::fail('A 400 must throw.');
        } catch (AnthropicRequestException $e) {
            self::assertStringContainsString('400', $e->getMessage());
        }

        self::assertCount(1, $http->recorded());
    }

    public function test_system_prompt_carries_the_exact_server_schema(): void
    {
        $phase = new AiPhase;
        $phase->prompt_content = '## Phase 3 — Model sheet QA';
        $system = ModelSheetQaService::system($phase);
        self::assertStringContainsString(json_encode(ModelSheetQaValidator::SCHEMA, JSON_UNESCAPED_SLASHES), $system);
        self::assertStringContainsString('no text or code fence', $system);
    }

    public function test_enforced_mode_sends_the_prompt_without_the_schema_section(): void
    {
        $this->useConfig(true);
        $phase = new AiPhase;
        $phase->prompt_content = '## Phase 3 — Model sheet QA';
        self::assertSame('## Phase 3 — Model sheet QA', ModelSheetQaService::system($phase));
    }

    // ---- Cau truc (Opis) truoc nghiep vu ----

    public function test_broken_json_is_blocked_without_a_prompt(): void
    {
        $out = $this->qaOutput('{"shared": [');
        self::assertSame('failed', $out['checks']['parse']);
        $this->assertNotReady($out);
    }

    public function test_root_array_is_blocked(): void
    {
        $out = $this->qaOutput('[]');
        self::assertStringContainsString('JSON object', implode("\n", $out['validation']['errors']));
        $this->assertNotReady($out);
    }

    /** @return iterable<string, array{0: callable, 1: string}> */
    public static function structuralDefects(): iterable
    {
        yield 'where is a string' => [static function (\stdClass $q): void { $q->coverage[0]->where = 'shared'; }, 'coverage[0].where'];
        yield 'refs holds a number' => [static function (\stdClass $q): void { $q->findings[] = (object) ['severity' => 'note', 'kind' => 'gap', 'refs' => [12], 'message' => 'x']; }, 'findings[0].refs[0]'];
        yield 'severity outside enum' => [static function (\stdClass $q): void { $q->findings[] = (object) ['severity' => 'warning', 'kind' => 'gap', 'refs' => [], 'message' => 'x']; }, 'findings[0].severity'];
        yield 'extra property' => [static function (\stdClass $q): void { $q->shared[0]->comment = 'x'; }, 'shared[0]'];
        yield 'findings is an object' => [static function (\stdClass $q): void { $q->findings = new \stdClass; }, 'findings'];
        yield 'missing numbers' => [static function (\stdClass $q): void { unset($q->shared[1]->numbers); }, 'shared[1]'];
        yield 'decision missing basis' => [static function (\stdClass $q): void { unset($q->views[0]->decisions[0]->basis); }, 'views[0].decisions[0]'];
    }

    /** @dataProvider structuralDefects */
    public function test_structural_defects_are_blocked_with_the_field_path(callable $mutate, string $field): void
    {
        $qa = json_decode(json_encode($this->validQa()), false);
        $mutate($qa);
        $out = $this->qaOutput(json_encode($qa));
        self::assertSame('failed', $out['checks']['schema']);
        self::assertSame('skipped', $out['checks']['business'], 'Business checks run only after the schema passes.');
        self::assertStringContainsString('Cấu trúc: '.$field, implode("\n", $out['validation']['errors']));
        $this->assertNotReady($out);
    }

    public function test_valid_shape_with_a_business_error_is_still_blocked(): void
    {
        $qa = $this->validQa();
        $qa['coverage'][0]['where'] = ['imaginary block'];
        $out = $this->qaOutput(json_encode($qa));
        self::assertSame('passed', $out['checks']['schema']);
        self::assertSame('failed', $out['checks']['business']);
        $this->assertNotReady($out);
    }

    public function test_blocking_finding_keeps_ready_false(): void
    {
        $qa = $this->validQa();
        $qa['findings'][] = ['severity' => 'blocking', 'kind' => 'gap', 'refs' => ['N:N1'], 'message' => 'Missing geometry.'];
        $out = $this->qaOutput(json_encode($qa));
        self::assertSame('blocking', $out['checks']['findings']);
        $this->assertNotReady($out);
    }

    public function test_fully_valid_answer_compiles_with_matching_sha(): void
    {
        $out = $this->qaOutput(json_encode($this->validQa()));
        self::assertTrue($out['ready'], implode("\n", $out['validation']['errors']));
        self::assertSame(['parse' => 'passed', 'schema' => 'passed', 'business' => 'passed', 'findings' => 'clear', 'compile' => 'passed', 'ready' => true], $out['checks']);
        self::assertSame(hash('sha256', $out['prompt']), $out['prompt_sha256']);
        self::assertSame('prompt-json', $out['schema_transport']);
        self::assertSame(ModelSheetQaSchema::sha256(), $out['schema_sha256']);
    }

    public function test_a_single_fenced_json_block_is_read_but_surrounding_text_is_not(): void
    {
        self::assertTrue($this->qaOutput("```json\n".json_encode($this->validQa())."\n```")['ready']);
        $this->assertNotReady($this->qaOutput('Here is the JSON: '.json_encode($this->validQa())));
    }

    /** @return iterable<string, array{0: string}> */
    public static function referenceCodes(): iterable
    {
        yield 'landmark id' => ['The stem L:canyon_port rises.'];
        yield 'innovation id' => ['N:N1 is a slot.'];
        yield 'envelope id' => ['Behind P:hull.'];
        yield 'bare fact id' => ['A size of F12.'];
        yield 'design path' => ['As in data.canonical.landmarks.'];
    }

    /** @dataProvider referenceCodes */
    public function test_reference_codes_cannot_reach_the_image_prompt(string $text): void
    {
        $qa = $this->validQa();
        $qa['views'][0]['sentences'][0]['text'] = $text;
        $out = $this->qaOutput(json_encode($qa));
        self::assertStringContainsString('mã tham chiếu', implode("\n", $out['validation']['errors']));
        $this->assertNotReady($out);
    }

    public function test_quoted_prose_size_belongs_to_its_innovation_and_prints_verbatim(): void
    {
        $this->design['data']['yacht_bible']['innovations'][0]['geometry'] = 'Flat facets approximately 2 by 3 m sit behind shields.';
        $this->snapshot = $this->facts()->snapshot();
        $quoted = collect($this->snapshot['facts'])->firstWhere('unit', 'quoted');
        self::assertSame('approximately 2 by 3 m', $quoted['render']);
        $qa = $this->validQa();
        $qa['shared'][] = ['text' => 'Each facet is {{'.$quoted['id'].'}}.', 'refs' => ['N:N1'], 'numbers' => [['fact' => $quoted['id'], 'subject' => 'N:N1', 'property' => 'quoted']]];
        $out = $this->qaOutput(json_encode($qa));
        self::assertTrue($out['ready'], implode("\n", $out['validation']['errors']));
        self::assertStringContainsString('Each facet is approximately 2 by 3 m.', $out['prompt']);
    }

    // ---- Cache ----

    public function test_cache_key_changes_with_transport_and_schema(): void
    {
        $set = new VideoPlanningStage;
        $set->id = 'set-1';
        $set->output_json = ['facts_sha256' => str_repeat('a', 64), 'qa_phase' => ['id' => 'p', 'revision' => 12, 'sha256' => str_repeat('b', 64)]];
        $service = new ModelSheetQaService(new PlanningStageStore);
        $prompt = $service->input($set);
        $this->useConfig(true);
        $grammar = $service->input($set);
        self::assertSame('prompt-json', $prompt['schema_transport']);
        self::assertSame('api-grammar', $grammar['schema_transport']);
        self::assertNotEquals($prompt, $grammar);
        self::assertSame(ModelSheetQaSchema::sha256(), $prompt['schema_sha256']);
        self::assertSame(ModelSheetQaSchema::VERSION, $prompt['structure_check']);
    }

    // ---- Ho tro ----

    private function assertNotReady(array $out): void
    {
        self::assertFalse($out['ready']);
        self::assertNull($out['prompt']);
        self::assertNull($out['prompt_sha256']);
        self::assertFalse($out['checks']['ready']);
    }

    private function qaOutput(string $raw): array
    {
        $set = new VideoPlanningStage;
        $set->id = 'set-1';
        $set->output_json = ['facts' => $this->snapshot, 'views' => self::views(), 'layout_guide' => false];

        return (new ModelSheetQaService(new PlanningStageStore))->output($set, $raw, $this->design, []);
    }

    /** @return array{0: AnthropicStructuredOutputClient, 1: HttpFactory} */
    private function client(array $response, ?string $effort, int $status = 200): array
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response($response, $status)]);

        return [new AnthropicStructuredOutputClient($http, 'test-key', 'https://api.anthropic.test', '2023-06-01', 30, 0, 0, false, $effort), $http];
    }

    private function body(HttpFactory $http): array
    {
        $recorded = $http->recorded();
        self::assertCount(1, $recorded);

        return json_decode($recorded[0][0]->body(), true);
    }

    private static function ok(string $text): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn', 'model' => 'claude-sonnet-5-5', 'usage' => ['input_tokens' => 10, 'output_tokens' => 2]];
    }

    private function useConfig(bool $enforce): void
    {
        $container = new Container;
        $container->instance('config', new Repository(['video' => ['superyacht' => ['model_sheet' => self::config($enforce)]]]));
        Container::setInstance($container);
    }

    private function facts(): ModelSheetFacts
    {
        return new ModelSheetFacts($this->design, [], array_map(static fn (array $v): array => ['id' => $v['id'], 'view' => $v['view']], self::views()), self::config(false)['qa']['priority']);
    }

    private function validQa(): array
    {
        $loa = collect($this->snapshot['facts'])->firstWhere('path', 'data.canonical.dimensions.loa.value');
        $entities = array_column($this->snapshot['entities'], 'id');
        $qa = [
            'shared' => [
                ['text' => 'A yacht {{'.$loa['id'].'}} long.', 'refs' => ['data.canonical.dimensions.loa'], 'numbers' => [['fact' => $loa['id'], 'subject' => 'yacht', 'property' => 'loa']]],
                ['text' => 'A canyon and an aft pool shape the exterior.', 'refs' => $entities, 'numbers' => []],
            ],
            'surfaces' => [['text' => 'Blue exterior paint.', 'refs' => ['data.yacht_bible.colors.0'], 'numbers' => []]],
            'display_state' => [], 'views' => [],
            'ms8_target' => ['innovation' => 'N1', 'entities' => ['L:canyon_port'], 'refs' => ['data.yacht_bible.innovations.0.position']],
            'critical_absences' => [], 'coverage' => [], 'changes' => [], 'findings' => [],
        ];

        foreach ($this->snapshot['candidates'] as $id => $candidates) {
            $qa['views'][] = ['view_id' => $id, 'sentences' => [['text' => 'The continuous outer envelope is visible.', 'refs' => ['data.yacht_bible.exterior.silhouette'], 'numbers' => []]],
                'decisions' => array_map(static fn (array $c): array => ['entity' => $c['entity'], 'decision' => 'shared', 'basis' => null, 'note' => ''], $candidates)];
        }

        foreach ($this->snapshot['coverage'] as $item) {
            $qa['coverage'][] = ['item' => $item['id'], 'status' => 'used', 'where' => ['shared'], 'note' => ''];
        }

        return $qa;
    }

    private static function views(): array
    {
        $out = [];

        foreach (range(1, 8) as $i) {
            $out[] = ['id' => 'MS'.$i, 'view' => 'View '.$i, 'row' => intdiv($i - 1, 4) + 1, 'column' => ($i - 1) % 4 + 1,
                'x' => (($i - 1) % 4) * 960, 'y' => intdiv($i - 1, 4) * 1080, 'width' => 960, 'height' => 1080];
        }

        return $out;
    }

    private static function config(bool $enforce): array
    {
        return [
            'master' => ['size' => '3840x2160', 'rows' => 2, 'columns' => 4, 'cell' => ['width' => 960, 'height' => 1080], 'detail_views' => ['MS8'],
                'prompt' => ['layout' => 'ONE {width}x{height} image.', 'panel' => 'Row {row}, column {column}: {id}, {view}. {camera}', 'framing' => 'Whole yacht per panel.',
                    'detail_framing' => '{id} is a detail.', 'rules' => ['Same yacht.'], 'presentation' => 'Out of water.', 'guide_caption' => 'Layout only.', 'max_chars' => 20000]],
            'cameras' => array_replace(array_fill_keys(array_column(self::views(), 'id'), 'Fixed camera.'), ['MS8' => 'Close-up of {target}.']),
            'occlusion' => 'Do not reveal hidden parts.',
            'suffix' => 'neutral studio',
            'qa' => ['execution' => 'test-qa', 'model' => 'claude-sonnet-5-5', 'effort' => 'medium', 'max_tokens' => 32000, 'negatives_target' => 5, 'enforce_schema' => $enforce,
                'priority' => ['MS2_max_x' => 0.6, 'MS3_min_x' => 0.4, 'MS4_max_x' => 0.33, 'MS5_min_x' => 0.67, 'MS8_near_loa' => 0.1]],
        ];
    }

    private static function design(): array
    {
        return ['data' => ['canonical' => [
            'coordinate_system' => ['x' => 'fraction of LOA from bow', 'y' => 'metres from the centreline, positive port', 'z' => 'metres above the design waterline'],
            'dimensions' => ['loa' => ['value' => 150, 'unit' => 'm'], 'beam' => ['value' => 20, 'unit' => 'm'], 'draft' => ['value' => 5, 'unit' => 'm']],
            'decks' => [['name' => 'Main', 'floor_z_m' => 3, 'ceiling_z_m' => 7]],
            'profile_curve' => [[0, 3], [0.5, 7], [1, 3]], 'plan_outline' => [[0, 0], [0.5, 10], [1, 4], [1, -4]],
            'landmarks' => ['canyon_port' => ['x' => 0.5, 'y' => 3, 'z' => 5], 'pool' => ['x' => 0.8, 'y' => 0, 'z' => 3]],
            'model_sheet' => ['views' => [['id' => 'MS8', 'view' => 'close-up of innovation N1']]],
        ], 'yacht_bible' => ['colors' => [['name' => 'Blue']], 'exterior' => ['silhouette' => 'A continuous outer envelope.'],
            'innovations' => [['id' => 'N1', 'name' => 'Canyon', 'position' => 'The canyon at x=0.4 to x=0.6 includes canyon_port.'],
                ['id' => 'N2', 'name' => 'Pool', 'position' => 'The pool at x=0.8 lies on the aft deck.']]]]];
    }
}
