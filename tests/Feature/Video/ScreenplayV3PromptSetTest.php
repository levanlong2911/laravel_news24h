<?php

namespace Tests\Feature\Video;

use App\Video\Concept\Contracts\StructuredOutputLlmClient;
use App\Video\Screenplay\ScreenplayAuthor;
use App\Video\Screenplay\ScreenplayValidator;
use Mockery;
use Tests\TestCase;

class ScreenplayV3PromptSetTest extends TestCase
{
    /** @var array<string, string> */
    private const V2_BASELINE = [
        '00_writer_contract.md' => 'c453ae283bc8ccfc4b05e60f89135aa50f1d85ac45930c0e8420a2d3678e41ff',
        '04_worked_example.input.json' => '74a29631db4ccdd55ac4053d9b5526c18c286ff8fa0f594f3dd729808386594d',
        '04_worked_example.json' => '47c346a2b6488474cfd92171dcf90ce1152a7f2972be942915e9c63a7ba01f94',
        '04_worked_example.md' => 'd714ebf24d80d2019345c5eef431da276e88c534310859543e1a9036b087bb62',
    ];

    /** @return array<string, mixed> */
    private function read(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function example(): array
    {
        return $this->read(resource_path('ai/screenplay/v3/04_worked_example.json'));
    }

    /** @return array<string, mixed> */
    private function exampleInput(): array
    {
        return $this->read(resource_path('ai/screenplay/v3/04_worked_example.input.json'));
    }

    /** @dataProvider v2Assets */
    public function test_the_v2_prompt_set_is_frozen(string $name, string $sha256): void
    {
        $path = resource_path("ai/screenplay/v2/{$name}");

        $this->assertFileExists($path);
        $this->assertSame(
            $sha256,
            hash_file('sha256', $path),
            "the v2 prompt asset {$name} changed; v3 must live beside it, never edit it",
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function v2Assets(): array
    {
        $rows = [];

        foreach (self::V2_BASELINE as $name => $sha256) {
            $rows[$name] = [$name, $sha256];
        }

        return $rows;
    }

    public function test_the_v3_example_satisfies_the_contract_it_teaches(): void
    {
        $profile = $this->exampleInput()['profile'];
        $validator = new ScreenplayValidator;

        $this->assertSame([], $validator->profileViolations($profile, 'screenplay_v3'));
        $this->assertSame([], $validator->structural($this->example(), $profile, 'screenplay_v3'));
        $this->assertSame([], $validator->editorial($this->example(), $profile));
    }

    public function test_the_v3_example_also_satisfies_the_schema_the_validator_does_not_check(): void
    {
        $schema = $this->read(resource_path('ai/screenplay/schemas/screenplay_v3.json'));
        $example = $this->example();
        $failures = [];

        $check = static function (mixed $value, array $spec, string $path) use (&$failures): void {
            if (isset($spec['minLength']) && is_string($value) && mb_strlen($value) < $spec['minLength']) {
                $failures[] = "{$path}: shorter than minLength {$spec['minLength']}";
            }

            if (isset($spec['maxLength']) && is_string($value) && mb_strlen($value) > $spec['maxLength']) {
                $failures[] = "{$path}: longer than maxLength {$spec['maxLength']}";
            }

            if (isset($spec['pattern']) && is_string($value)
                && preg_match('/'.str_replace('/', '\\/', $spec['pattern']).'/D', $value) !== 1) {
                $failures[] = "{$path}: does not match {$spec['pattern']}";
            }

            if (isset($spec['minItems']) && is_array($value) && count($value) < $spec['minItems']) {
                $failures[] = "{$path}: fewer than minItems {$spec['minItems']}";
            }
        };

        $check($example['logline'], $schema['properties']['logline'], 'logline');

        foreach (['design_thesis', 'premise'] as $key) {
            foreach ($example[$key] as $field => $value) {
                $check($value, $schema['properties'][$key]['properties'][$field], "{$key}.{$field}");
            }
        }

        foreach (['characters', 'locations', 'scenes', 'coverage'] as $collection) {
            $check($example[$collection], $schema['properties'][$collection], $collection);
            $properties = $schema['properties'][$collection]['items']['properties'];

            foreach ($example[$collection] as $index => $row) {
                foreach ($row as $field => $value) {
                    if (isset($properties[$field])) {
                        $check($value, $properties[$field], "{$collection}[{$index}].{$field}");
                    }
                }
            }
        }

        $this->assertSame([], $failures);
    }

    public function test_the_v3_example_teaches_the_shapes_the_contract_introduces(): void
    {
        $example = $this->example();
        $kinds = array_column($example['characters'], 'kind');

        $this->assertContains('object', $kinds);
        $this->assertContains('person', $kinds);
        $this->assertContains('group', $kinds, 'the example must show that participants can be declared collectively');

        $states = array_column($example['scenes'], 'build_state');

        $this->assertContains(null, $states, 'the example must show a scene where build state does not apply');
        $this->assertGreaterThan(1, count(array_filter($states)), 'and several scenes that record one');

        foreach ($example['scenes'] as $scene) {
            $this->assertArrayHasKey('build_state', $scene, "{$scene['id']} must carry the field even when it is null");
        }

        $modes = array_count_values(array_column($example['coverage'], 'mode'));

        $this->assertArrayHasKey('shown', $modes);
        $this->assertArrayHasKey('transition', $modes, 'the example must show how omitted work is accounted for');
    }

    public function test_every_coverage_item_the_example_profile_declares_is_answered_once(): void
    {
        $declared = array_column($this->exampleInput()['profile']['coverage'], 'id');
        $answered = array_column($this->example()['coverage'], 'coverage_id');

        sort($declared);
        sort($answered);

        $this->assertSame($declared, $answered);
    }

    public function test_the_author_can_assemble_the_v3_prompt_without_touching_the_v2_set(): void
    {
        $author = new ScreenplayAuthor(
            client: Mockery::mock(StructuredOutputLlmClient::class),
            promptDir: resource_path('ai/screenplay/v3'),
            schemaPath: resource_path('ai/screenplay/schemas/screenplay_v3.json'),
            promptVersion: 'screenplay-v3',
            model: 'claude-sonnet-5',
            maxTokens: 32000,
            contractVersion: 'screenplay_v3',
        );

        $author->assertSchemaMatchesContract();

        $rules = (new \ReflectionClass(ScreenplayAuthor::class))->getMethod('rules');
        $rules->setAccessible(true);
        $prompt = (string) $rules->invoke($author);

        foreach ([
            'BUILD STATE',
            'COVERAGE',
            'PEOPLE AS PARTICIPANTS',
            'object   an identifiable non-person subject.',
            'A WORKED EXAMPLE',
            'cov_build_supports',
            'build_state',
        ] as $needle) {
            $this->assertStringContainsString($needle, $prompt);
        }

        $this->assertStringNotContainsString(
            'nothing solid is ever put between',
            $prompt,
            'the v3 prompt must carry its own example, not the v2 one',
        );
    }

    public function test_the_v3_example_keeps_its_scene_count_from_reading_as_a_target(): void
    {
        $notes = (string) file_get_contents(resource_path('ai/screenplay/v3/04_worked_example.md'));

        $this->assertStringContainsString('It is not a target.', $notes);
    }
}
