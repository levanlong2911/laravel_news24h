<?php

namespace Tests\Feature\Video;

use App\Services\PythonRunner;
use App\Services\Video\PythonPromptCompiler;
use Mockery;
use Tests\TestCase;

class PythonPromptCompilerTest extends TestCase
{
    private function compilerReturning(array $payload): PythonPromptCompiler
    {
        config(['video.runner.log_dir' => storage_path('framework/testing')]);

        $runner = Mockery::mock(PythonRunner::class);
        $runner->shouldReceive('runAndWait')->once()->andReturn([true, json_encode($payload)]);

        return new PythonPromptCompiler($runner);
    }

    private function ok(array $override = []): array
    {
        return $override + [
            'ok' => true,
            'prompt' => 'COMPILED',
            'chars' => 8,
            'operation' => 'generate',
            'viewpoint' => 'front_three_quarter',
            'stage' => 'fabrication_geometry_anchor',
            'output_size' => ['width' => 1536, 'height' => 1024],
        ];
    }

    public function test_it_accepts_matching_python_contract_metadata(): void
    {
        [$prompt, $reason] = $this->compilerReturning($this->ok())->compile(
            'yacht',
            ['design_identity' => []],
            'front_three_quarter',
            1536,
            1024,
            'fabrication_geometry_anchor',
        );

        $this->assertSame('COMPILED', $prompt);
        $this->assertSame('ok', $reason);
    }

    /**
     * @dataProvider mismatches
     */
    public function test_it_refuses_python_contract_metadata_that_does_not_match_the_request(
        array $override,
        string $message,
    ): void {
        [$prompt, $reason] = $this->compilerReturning($this->ok($override))->compile(
            'yacht',
            ['design_identity' => []],
            'front_three_quarter',
            1536,
            1024,
            'fabrication_geometry_anchor',
        );

        $this->assertNull($prompt);
        $this->assertStringContainsString($message, $reason);
    }

    public static function mismatches(): array
    {
        return [
            'operation' => [['operation' => 'edit'], 'unexpected image operation'],
            'viewpoint' => [['viewpoint' => 'side'], 'unexpected viewpoint'],
            'stage' => [['stage' => 'finished_identity_anchor'], 'unexpected stage'],
            'width' => [['output_size' => ['width' => 1024, 'height' => 1024]], 'unexpected output_size'],
            'missing size' => [['output_size' => null], 'unexpected output_size'],
        ];
    }
}
