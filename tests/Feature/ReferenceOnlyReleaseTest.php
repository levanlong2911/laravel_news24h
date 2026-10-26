<?php

namespace Tests\Feature;

use App\Models\AiJsonSchema;
use App\Models\AiPhase;
use App\Models\AiPhaseSchema;
use App\Video\Superyacht\Import\SpecImporter;
use App\Video\Superyacht\Spec\ReferenceOnlyRelease;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class ReferenceOnlyReleaseTest extends TestCase
{
    use DatabaseTransactions;

    private string $category;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->category = (string) Str::uuid();
        DB::table('categories')->insert(['id' => $this->category, 'name' => 'Yacht', 'slug' => 'yacht', 'created_at' => now(), 'updated_at' => now()]);
        $importer = SpecImporter::fromConfig(config('video.superyacht'), 'yacht');
        $plan = $importer->plan();
        $importer->import($plan, $plan['spec']['sha256']);
    }

    public function test_preview_does_not_write_and_missing_reviewed_hash_is_refused(): void
    {
        $this->legacy();
        $before = $this->counts();
        $this->artisan('video:reference-only-release')->expectsOutputToContain('Preview only')->assertSuccessful();
        $this->artisan('video:reference-only-release', ['--apply' => true])->expectsOutputToContain('Missing --expected-plan')->assertFailed();
        self::assertSame($before, $this->counts());
        self::assertSame([], Storage::disk('local')->allFiles());
        Http::assertNothingSent();
    }

    public function test_release_preserves_history_and_other_jobs_and_is_idempotent(): void
    {
        [$old, $retired] = $this->legacy();
        $history = $old->only(['prompt_content', 'content_sha256', 'needs', 'parallel_with']);
        $oldLinks = $old->schemaLinks()->orderBy('id')->get()->toArray();
        $oldSchema = AiJsonSchema::where('schema_key', 'CompiledShot')->sole()->getRawOriginal('schema_content');
        $job = $this->job('App\\Jobs\\BuildProxyJob');
        $otherJob = $this->job('App\\Jobs\\WriteArticleJob');
        $otherCategory = (string) Str::uuid();
        DB::table('categories')->insert(['id' => $otherCategory, 'name' => 'Other', 'slug' => 'other', 'created_at' => now(), 'updated_at' => now()]);
        $other = AiPhase::create($old->only(['workflow_code', 'phase_code', 'kind', 'name', 'sort_order', 'prompt_content', 'content_sha256']) + [
            'category_id' => $otherCategory, 'revision' => 1, 'status' => AiPhase::PUBLISHED, 'is_current' => true,
        ]);
        $release = app(ReferenceOnlyRelease::class);
        $result = $release->apply($release->plan()['plan_hash']);
        self::assertTrue($result['changed']);
        self::assertFalse($old->fresh()->is_current);
        self::assertSame($history, $old->fresh()->only(array_keys($history)));
        self::assertSame($oldLinks, $old->schemaLinks()->orderBy('id')->get()->toArray());
        self::assertFalse($retired->fresh()->is_current);
        self::assertTrue($other->fresh()->is_current);
        self::assertSame($oldSchema, AiJsonSchema::where('schema_key', 'CompiledShot')->where('version', '6.3.0')->sole()->getRawOriginal('schema_content'));
        $current = AiPhase::currentFor($this->category, config('video.superyacht.workflow_code'), 'phase-3');
        self::assertStringNotContainsString('proxy_geometry', $current->prompt_content);
        self::assertStringContainsString('Keep this custom instruction.', $current->prompt_content);
        self::assertSame('3, 5', $current->needs);
        self::assertFalse($current->schemaLinks()->where('slot_key', 'proxy_geometry')->exists());
        self::assertFalse(DB::table('jobs')->where('id', $job)->exists());
        self::assertTrue(DB::table('jobs')->where('id', $otherJob)->exists());
        $backup = json_decode(Storage::disk('local')->get($result['backup']), true);
        self::assertSame([$job], array_column($backup['jobs'], 'id'));
        self::assertNotEmpty($backup['phases']);
        $before = $this->counts();
        self::assertFalse($release->apply($release->plan()['plan_hash'])['changed']);
        self::assertSame($before, $this->counts());
        self::assertCount(1, Storage::disk('local')->allFiles());
        Http::assertNothingSent();
    }

    public function test_changed_plan_aborts_without_writes(): void
    {
        $this->legacy();
        $release = app(ReferenceOnlyRelease::class);
        $plan = $release->plan();
        $this->job('App\\Jobs\\PrepareProxyJob');
        $before = $this->counts();
        try {
            $release->apply($plan['plan_hash']);
            self::fail('Stale plan was accepted.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('plan changed', $e->getMessage());
        }
        self::assertSame($before, $this->counts());
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_reserved_job_aborts_and_keeps_prompts_and_payload(): void
    {
        [$old] = $this->legacy();
        $job = $this->job('App\\Jobs\\BuildProxyJob', time());
        $before = $this->counts();
        $release = app(ReferenceOnlyRelease::class);
        try {
            $release->apply($release->plan()['plan_hash']);
            self::fail('Reserved job was removed.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('reserved', $e->getMessage());
        }
        self::assertSame($before, $this->counts());
        self::assertTrue($old->fresh()->is_current);
        self::assertTrue(DB::table('jobs')->where('id', $job)->exists());
    }

    public function test_unknown_custom_dependency_requires_review_instead_of_silent_deletion(): void
    {
        $release = app(ReferenceOnlyRelease::class);
        self::assertSame('JUDGE listening proxy', $release->clean('JUDGE listening proxy'));
        $phase = AiPhase::where('category_id', $this->category)->where('phase_code', 'phase-3')->sole();
        $body = $phase->prompt_content."\nCustom proxy_input must be retained.\n";
        DB::table('ai_phases')->where('id', $phase->id)->update(['prompt_content' => $body, 'content_sha256' => hash('sha256', $body)]);
        $this->expectExceptionMessage('Unrecognized retired dependency');
        $release->plan();
    }

    public function test_failed_backup_rolls_back_the_release(): void
    {
        [$old] = $this->legacy();
        $release = app(ReferenceOnlyRelease::class);
        $hash = $release->plan()['plan_hash'];
        $before = $this->counts();
        Storage::shouldReceive('disk')->with('local')->once()->andReturn(new class {
            public function put(...$args): bool { return false; }
        });
        try {
            $release->apply($hash);
            self::fail('Release wrote without a backup.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('backup', $e->getMessage());
        }
        self::assertSame($before, $this->counts());
        self::assertTrue($old->fresh()->is_current);
    }

    public function test_a_conflicting_published_schema_is_not_overwritten(): void
    {
        $schema = AiJsonSchema::where('schema_key', 'CompiledShot')->sole();
        DB::table('ai_json_schemas')->where('id', $schema->id)->update(['schema_content' => '{}', 'schema_sha256' => hash('sha256', '{}')]);
        $before = $this->counts();
        try {
            app(ReferenceOnlyRelease::class)->plan();
            self::fail('A conflicting published schema was accepted.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('do not overwrite history', $e->getMessage());
        }
        self::assertSame($before, $this->counts());
        self::assertSame('{}', $schema->fresh()->schema_content);
    }

    public function test_publish_failure_rolls_back_new_schema_and_revisions(): void
    {
        [$old] = $this->legacy();
        // Simulate an incomplete old installation: do not publish a partial contract.
        DB::table('ai_phase_schemas')->where('phase_id', $old->id)->where('slot_key', 'canonical')->delete();
        $before = $this->counts();
        $release = app(ReferenceOnlyRelease::class);
        try {
            $release->apply($release->plan()['plan_hash']);
            self::fail('An incomplete contract was published.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('canonical', $e->getMessage());
        }
        self::assertSame($before, $this->counts());
        self::assertTrue($old->fresh()->is_current);
        self::assertFalse(AiJsonSchema::where('schema_key', 'CompiledShot')->where('version', '6.3.1')->exists());
    }

    private function legacy(): array
    {
        $base = AiPhase::where('category_id', $this->category)->where('phase_code', 'phase-3')->sole();
        $base->is_current = false;
        $base->save();
        $body = $base->prompt_content."\n### Proxy geometry contract (`canonical.proxy_geometry`, proxy-geometry-v1)\n\ncanonical.proxy_geometry is required.\n\n### Custom instruction\nKeep this custom instruction.\n";
        $old = AiPhase::create($base->only(['category_id', 'workflow_code', 'phase_code', 'kind', 'name', 'sort_order']) + [
            'revision' => 2, 'prompt_content' => $body, 'content_sha256' => hash('sha256', $body), 'needs' => '3, 3P, 5',
            'status' => AiPhase::DRAFT, 'is_current' => false,
        ]);
        foreach ($base->schemaLinks()->get() as $link) AiPhaseSchema::create(['phase_id' => $old->id] + $link->only(['role', 'slot_key', 'schema_id', 'data_pointer', 'validation_mode', 'is_required']));
        $schema = AiJsonSchema::create(['namespace' => 'superyacht', 'schema_key' => 'ProxyGeometry', 'version' => '6.3.0', 'name' => 'Legacy',
            'schema_content' => '{}', 'schema_sha256' => hash('sha256', '{}'), 'dependencies' => [], 'status' => AiJsonSchema::PUBLISHED]);
        AiPhaseSchema::create(['phase_id' => $old->id, 'role' => 'output', 'slot_key' => 'proxy_geometry', 'schema_id' => $schema->id,
            'data_pointer' => '/data/canonical/proxy_geometry', 'validation_mode' => 'whole', 'is_required' => true]);
        $old->status = AiPhase::PUBLISHED;
        $old->is_current = true;
        $old->save();
        $retired = AiPhase::create(['category_id' => $this->category, 'workflow_code' => $base->workflow_code, 'phase_code' => 'phase-3p',
            'revision' => 1, 'kind' => 'phase', 'name' => 'Legacy', 'sort_order' => 9, 'prompt_content' => 'Legacy renderer',
            'content_sha256' => hash('sha256', 'Legacy renderer'), 'status' => AiPhase::PUBLISHED, 'is_current' => true]);
        // Simulate an older install without overwriting published schemas in production code.
        DB::table('ai_json_schemas')->where('schema_key', 'CompiledShot')->update(['version' => '6.3.0']);

        return [$old, $retired];
    }

    private function job(string $name, ?int $reserved = null): int
    {
        return DB::table('jobs')->insertGetId(['queue' => 'video-planning', 'payload' => json_encode(['displayName' => $name, 'data' => ['commandName' => $name, 'command' => 'preserved payload']]),
            'attempts' => 0, 'reserved_at' => $reserved, 'available_at' => time(), 'created_at' => time()]);
    }

    private function counts(): array
    {
        return [AiPhase::count(), AiPhaseSchema::count(), AiJsonSchema::count(), DB::table('jobs')->count()];
    }
}
