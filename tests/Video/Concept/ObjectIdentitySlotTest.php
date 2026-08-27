<?php

namespace Tests\Video\Concept;

use App\Video\Inspiration\CategoryCreativeProfile;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ObjectIdentitySlotTest extends TestCase
{
    /** @param array<string, mixed> $slots */
    private function profileWith(array $slots): CategoryCreativeProfile
    {
        return new CategoryCreativeProfile(
            'test_profile',
            'A mission.',
            ['pattern'],
            ['size_and_dimensions'],
            ['owner'],
            $slots,
            'A concept mission.',
            ['side' => 'Low camera square to the centreline.'],
        );
    }

    /** @return array<string, mixed> */
    private function bowSlot(): array
    {
        return [
            'type' => 'object',
            'fields' => [
                'stem' => ['type' => 'text', 'max_length' => 60],
                'rake_degrees' => ['type' => 'number', 'min' => 0.0, 'max' => 30.0],
            ],
        ];
    }

    public function test_a_profile_may_declare_an_object_slot_with_typed_fields(): void
    {
        $profile = $this->profileWith(['bow' => $this->bowSlot()]);

        $this->assertSame('object', $profile->identitySlots['bow']['type']);
        $this->assertSame(['stem', 'rake_degrees'], array_keys($profile->identitySlots['bow']['fields']));
    }

    public function test_an_object_slot_with_no_fields_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must declare at least one field');

        $this->profileWith(['bow' => ['type' => 'object', 'fields' => []]]);
    }

    public function test_an_object_slot_that_nests_another_object_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not nest another object');

        $this->profileWith(['bow' => [
            'type' => 'object',
            'fields' => ['inner' => ['type' => 'object', 'fields' => ['a' => ['type' => 'text', 'max_length' => 5]]]],
        ]]);
    }

    public function test_a_field_with_an_invalid_name_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contains an invalid field name');

        $this->profileWith(['bow' => [
            'type' => 'object',
            'fields' => ['1stem' => ['type' => 'text', 'max_length' => 60]],
        ]]);
    }

    public function test_a_field_with_a_mistyped_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bow.rake_degrees must declare');

        $this->profileWith(['bow' => [
            'type' => 'object',
            'fields' => ['rake_degrees' => ['type' => 'number', 'min' => 0.0, 'maximum' => 30.0]],
        ]]);
    }

    public function test_a_field_bound_the_wrong_way_round_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bow.rake_degrees has min greater than max');

        $this->profileWith(['bow' => [
            'type' => 'object',
            'fields' => ['rake_degrees' => ['type' => 'number', 'min' => 30.0, 'max' => 0.0]],
        ]]);
    }

    public function test_an_object_slot_may_not_declare_bounds_of_its_own(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bow must declare type, fields');

        $this->profileWith(['bow' => [
            'type' => 'object',
            'fields' => ['stem' => ['type' => 'text', 'max_length' => 60]],
            'min' => 1,
        ]]);
    }

    private function violationsFor(mixed $identity): array
    {
        $profile = $this->profileWith(['bow' => $this->bowSlot()]);
        $validator = new \App\Video\Concept\ConceptValidator;

        $method = new \ReflectionMethod($validator, 'checkSlotValue');
        $method->setAccessible(true);

        $violations = [];
        $warnings = [];
        $method->invokeArgs($validator, ['bow', $profile->identitySlots['bow'], $identity, &$violations, &$warnings]);

        return $violations;
    }

    public function test_a_complete_object_value_passes(): void
    {
        $this->assertSame([], $this->violationsFor(['stem' => 'Near plumb stem', 'rake_degrees' => 12.0]));
    }

    public function test_a_value_that_is_not_an_object_is_a_violation(): void
    {
        $this->assertSame(['design_identity.bow must be an object'], $this->violationsFor('Near plumb stem'));
    }

    public function test_a_missing_field_is_a_violation(): void
    {
        $this->assertContains(
            'design_identity.bow is missing field rake_degrees',
            $this->violationsFor(['stem' => 'Near plumb stem']),
        );
    }

    public function test_an_unknown_field_is_a_violation(): void
    {
        $this->assertContains(
            'design_identity.bow contains unknown field forefoot',
            $this->violationsFor(['stem' => 'a', 'rake_degrees' => 12.0, 'forefoot' => 'b']),
        );
    }

    public function test_a_field_out_of_bounds_is_a_violation(): void
    {
        $this->assertContains(
            'design_identity.bow.rake_degrees must be between 0 and 30',
            $this->violationsFor(['stem' => 'a', 'rake_degrees' => 44.0]),
        );
    }

    public function test_a_field_of_the_wrong_type_is_a_violation(): void
    {
        $this->assertContains(
            'design_identity.bow.stem must be a non-empty string',
            $this->violationsFor(['stem' => 12, 'rake_degrees' => 12.0]),
        );
    }

    private function instructionFor(CategoryCreativeProfile $profile): string
    {
        $designer = new \App\Video\Concept\ClaudeConceptDesigner(
            new class implements \App\Video\Llm\LlmClient
            {
                public function complete(\App\Video\Llm\LlmRequest $request): \App\Video\Llm\LlmResponse
                {
                    throw new \RuntimeException('khong goi');
                }
            }
        );

        $method = new \ReflectionMethod($designer, 'instruction');
        $method->setAccessible(true);

        return $method->invoke($designer, $profile);
    }

    public function test_the_instruction_lists_an_object_slot_with_its_fields_indented(): void
    {
        $instruction = $this->instructionFor($this->profileWith(['bow' => $this->bowSlot()]));

        $this->assertStringContainsString('- bow: an object with exactly these keys:', $instruction);
        $this->assertStringContainsString('  - stem: one compact technical phrase, at most 8 words.', $instruction);
        $this->assertStringContainsString('  - rake_degrees: number, between 0 and 30.', $instruction);
    }

    public function test_a_flat_slot_is_written_exactly_as_before(): void
    {
        $instruction = $this->instructionFor($this->profileWith([
            'hull_colour' => ['type' => 'text', 'max_length' => 60],
            'visible_deck_tiers' => ['type' => 'integer', 'min' => 1, 'max' => 10],
        ]));

        $this->assertStringContainsString('- hull_colour: one compact technical phrase, at most 8 words.', $instruction);
        $this->assertStringContainsString('- visible_deck_tiers: integer, between 1 and 10.', $instruction);
    }

    /** @return array<string, mixed> */
    private function enumSlot(): array
    {
        return ['type' => 'enum', 'values' => ['plumb', 'near_plumb', 'raked']];
    }

    public function test_a_profile_may_declare_an_enum_slot(): void
    {
        $profile = $this->profileWith(['stem' => $this->enumSlot()]);

        $this->assertSame(['plumb', 'near_plumb', 'raked'], $profile->identitySlots['stem']['values']);
    }

    public function test_an_enum_may_lock_a_single_value(): void
    {
        $profile = $this->profileWith(['distribution' => ['type' => 'enum', 'values' => ['horizontal_ribbon']]]);

        $this->assertSame(['horizontal_ribbon'], $profile->identitySlots['distribution']['values']);
    }

    public function test_an_enum_with_no_values_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must declare at least one value');

        $this->profileWith(['stem' => ['type' => 'enum', 'values' => []]]);
    }

    public function test_a_locked_enum_reads_as_always_in_the_instruction(): void
    {
        $instruction = $this->instructionFor($this->profileWith([
            'distribution' => ['type' => 'enum', 'values' => ['horizontal_ribbon']],
        ]));

        $this->assertStringContainsString('- distribution: always horizontal_ribbon.', $instruction);
    }

    public function test_an_enum_with_duplicate_values_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has duplicate values');

        $this->profileWith(['stem' => ['type' => 'enum', 'values' => ['plumb', 'plumb']]]);
    }

    public function test_an_enum_value_that_is_not_a_plain_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has an invalid value');

        $this->profileWith(['stem' => ['type' => 'enum', 'values' => ['plumb', 'Near Plumb']]]);
    }

    public function test_a_value_outside_the_enum_is_a_violation(): void
    {
        $profile = $this->profileWith(['bow' => ['type' => 'object', 'fields' => ['stem' => $this->enumSlot()]]]);
        $validator = new \App\Video\Concept\ConceptValidator;

        $method = new \ReflectionMethod($validator, 'checkSlotValue');
        $method->setAccessible(true);

        $violations = [];
        $warnings = [];
        $method->invokeArgs($validator, ['bow', $profile->identitySlots['bow'], ['stem' => 'azimut_style'], &$violations, &$warnings]);

        $this->assertSame(['design_identity.bow.stem must be one of plumb, near_plumb, raked'], $violations);
    }

    public function test_the_instruction_offers_the_enum_values_as_a_closed_choice(): void
    {
        $instruction = $this->instructionFor($this->profileWith(['stem' => $this->enumSlot()]));

        $this->assertStringContainsString('- stem: exactly one of: plumb, near_plumb, raked.', $instruction);
    }

    public function test_a_json_list_is_not_accepted_as_an_object(): void
    {
        $this->assertSame(
            ['design_identity.bow must be an object'],
            $this->violationsFor(['near_plumb', 12]),
        );
    }

    public function test_an_empty_object_reports_its_missing_fields_not_a_type_error(): void
    {
        $violations = $this->violationsFor([]);

        $this->assertContains('design_identity.bow is missing field stem', $violations);
        $this->assertNotContains('design_identity.bow must be an object', $violations);
    }

    public function test_an_enum_value_that_is_an_array_throws_without_a_php_warning(): void
    {
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \RuntimeException("PHP warning duoc phat truoc khi hop dong no: {$message}");
        });

        try {
            $this->profileWith(['stem' => ['type' => 'enum', 'values' => ['plumb', ['bad']]]]);
            $this->fail('phai nem');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('has an invalid value', $e->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    public function test_an_enum_value_that_is_null_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has an invalid value');

        $this->profileWith(['stem' => ['type' => 'enum', 'values' => ['plumb', null]]]);
    }
}
