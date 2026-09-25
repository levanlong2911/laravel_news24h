<?php

namespace Tests\Feature\Video;

use App\Video\Prompt\Exceptions\TextCompletionException;
use App\Video\Screenplay\ScreenplayAuthor;
use App\Video\Screenplay\ScreenplayValidator;
use Tests\TestCase;

class ScreenplaySchemaDriftTest extends TestCase
{
    /** @var list<string> */
    private const KNOWN_TYPES = ['string', 'integer', 'list'];

    /** @var list<string> */
    private const DELEGATED_FIELDS = ['scenes.build_state'];

    private function constant(string $name): mixed
    {
        return (new \ReflectionClass(ScreenplayValidator::class))->getConstant($name);
    }

    /** @return array<string, mixed> */
    private function schema(string $contract): array
    {
        return json_decode(
            (string) file_get_contents(resource_path("ai/screenplay/schemas/{$contract}.json")),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    public function test_an_unsupported_field_type_stops_the_validator_instead_of_passing(): void
    {
        $method = (new \ReflectionClass(ScreenplayValidator::class))->getMethod('isOfType');
        $method->setAccessible(true);
        $validator = new ScreenplayValidator;

        $this->assertTrue($method->invoke($validator, 'a string', 'string'));
        $this->assertFalse($method->invoke($validator, 7, 'string'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unsupported field type: int');

        $method->invoke($validator, 7, 'int');
    }

    public function test_every_declared_field_type_is_one_the_validator_can_check(): void
    {
        $tables = [
            'FIELD_TYPES' => $this->constant('FIELD_TYPES'),
            'NESTED_TEXT_FIELDS' => $this->constant('NESTED_TEXT_FIELDS'),
        ];

        $checked = 0;

        foreach ($tables as $table => $collections) {
            foreach ($collections as $collection => $fields) {
                foreach ($fields as $field => $type) {
                    $this->assertSame(
                        1,
                        preg_match('/^\??(?:'.implode('|', self::KNOWN_TYPES).')$/D', $type),
                        "{$table}.{$collection}.{$field}: '{$type}' is not a type the validator can check",
                    );

                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(20, $checked);
    }

    /** @dataProvider declaredTypes */
    public function test_a_declared_type_is_spelled_the_way_the_validator_spells_it(string $where, string $type): void
    {
        $bare = str_starts_with($type, '?') ? substr($type, 1) : $type;

        $this->assertNotSame('', $bare, "{$where}: the type is empty");
        $this->assertStringNotContainsString('?', $bare, "{$where}: '?' may only lead the type");
        $this->assertContains($bare, self::KNOWN_TYPES, "{$where}: unknown type '{$bare}'");
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function declaredTypes(): array
    {
        $reflection = new \ReflectionClass(ScreenplayValidator::class);
        $rows = [];

        foreach (['FIELD_TYPES', 'NESTED_TEXT_FIELDS'] as $table) {
            foreach ($reflection->getConstant($table) as $collection => $fields) {
                foreach ($fields as $field => $type) {
                    $rows["{$table} {$collection}.{$field}"] = ["{$table}.{$collection}.{$field}", $type];
                }
            }
        }

        return $rows;
    }

    /** @dataProvider contracts */
    public function test_the_field_table_still_matches_the_schema_it_copies(string $contract): void
    {
        $schema = $this->schema($contract);
        $types = $this->constant('FIELD_TYPES');
        $enums = $this->constant('FIELD_ENUMS');
        $kinds = $this->constant('CHARACTER_KINDS');

        $items = [
            'characters' => $schema['properties']['characters']['items'],
            'locations' => $schema['properties']['locations']['items'],
            'scenes' => $schema['properties']['scenes']['items'],
            'dialogue' => $schema['properties']['scenes']['items']['properties']['dialogue']['items'],
        ];

        foreach ($items as $collection => $item) {
            $declared = array_keys($item['properties']);
            $tabled = array_keys($types[$collection]);
            $delegated = array_values(array_filter(
                $declared,
                static fn (string $f): bool => in_array("{$collection}.{$f}", self::DELEGATED_FIELDS, true),
            ));

            $this->assertSame(
                [],
                array_values(array_diff($declared, $tabled, $delegated)),
                "{$contract} {$collection}: the schema declares fields the table does not check",
            );
            $this->assertSame(
                [],
                array_values(array_diff($tabled, $declared)),
                "{$contract} {$collection}: the table checks fields the schema does not declare",
            );
            $this->assertSame(
                [],
                array_values(array_diff($tabled, $item['required'])),
                "{$contract} {$collection}: the table treats an optional field as required",
            );

            foreach ($item['properties'] as $field => $definition) {
                if (! array_key_exists($field, $types[$collection])) {
                    continue;
                }

                $where = "{$contract} {$collection}.{$field}";
                $declaredTypes = (array) ($definition['type'] ?? []);
                $bare = array_values(array_diff($declaredTypes, ['null']));
                $expected = (in_array('null', $declaredTypes, true) ? '?' : '')
                    .($bare === ['array'] ? 'list' : implode('|', $bare));

                $this->assertSame($expected, $types[$collection][$field], "{$where}: type or nullability drifted");

                $tableEnum = $collection === 'characters' && $field === 'kind'
                    ? ($kinds[$contract] ?? null)
                    : ($enums["{$collection}.{$field}"] ?? null);

                $this->assertSame($definition['enum'] ?? null, $tableEnum, "{$where}: enum drifted");
            }
        }
    }

    /** @dataProvider contracts */
    public function test_the_nested_text_tables_still_match_the_schema(string $contract): void
    {
        $schema = $this->schema($contract);

        foreach ($this->constant('NESTED_TEXT_FIELDS') as $key => $fields) {
            $declared = $schema['properties'][$key];

            $declaredFields = array_keys($declared['properties']);
            $tabledFields = array_keys($fields);
            sort($declaredFields);
            sort($tabledFields);
            $required = $declared['required'];
            sort($required);

            $this->assertSame($declaredFields, $tabledFields, "{$contract} {$key}: field set drifted");
            $this->assertSame(
                $declaredFields,
                $required,
                "{$contract} {$key}: the schema no longer requires every field the table requires",
            );

            foreach ($fields as $field => $type) {
                $this->assertSame('string', $type, "{$contract} {$key}.{$field}: only strings are expected here");
                $this->assertSame(
                    'string',
                    $declared['properties'][$field]['type'],
                    "{$contract} {$key}.{$field}: the schema type drifted",
                );
            }
        }
    }

    public function test_the_delegated_field_still_matches_the_rule_that_carries_it(): void
    {
        $buildState = $this->schema('screenplay_v3')['properties']['scenes']['items']['properties']['build_state'];

        $this->assertSame(['object', 'null'], $buildState['type']);
        $this->assertSame(['subject_id', 'state'], $buildState['required']);
        $this->assertSame('string', $buildState['properties']['subject_id']['type']);
        $this->assertSame('string', $buildState['properties']['state']['type']);

        $this->assertNotContains(
            'build_state',
            array_keys($this->constant('FIELD_TYPES')['scenes']),
            'build_state is delegated to buildStateViolations() and must not sit in the shared table too',
        );
    }

    public function test_the_kind_table_covers_exactly_the_contracts_the_validator_supports(): void
    {
        $this->assertSame(
            ScreenplayValidator::CONTRACTS,
            array_keys($this->constant('CHARACTER_KINDS')),
        );

        $this->assertContains(ScreenplayAuthor::DEFAULT_CONTRACT, ScreenplayValidator::CONTRACTS);
        $this->assertContains(
            (string) config('video.screenplay.contract_version'),
            ScreenplayValidator::CONTRACTS,
        );
    }

    public function test_an_author_cannot_be_built_on_a_contract_the_validator_does_not_know(): void
    {
        $this->expectException(TextCompletionException::class);
        $this->expectExceptionMessage('Unsupported screenplay contract: screenplay_v9');

        new ScreenplayAuthor(
            client: \Mockery::mock(\App\Video\Concept\Contracts\StructuredOutputLlmClient::class),
            promptDir: (string) config('video.screenplay.prompt_dir'),
            schemaPath: (string) config('video.screenplay.schema_path'),
            promptVersion: 'test',
            model: 'test',
            maxTokens: 1000,
            contractVersion: 'screenplay_v9',
        );
    }

    /** @return array<string, array{0: string}> */
    public static function contracts(): array
    {
        return [
            'screenplay_v2' => ['screenplay_v2'],
            'screenplay_v3' => ['screenplay_v3'],
        ];
    }
}
