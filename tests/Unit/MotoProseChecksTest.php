<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Video\Moto\MotoScenesCheck;
use App\Video\Moto\MotoShotsCheck;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** PHP chi kiem du lieu xac dinh: nghia cua van xuoi trong do bao ho va mo ta bo phan la viec cua nguoi duyet. */
final class MotoProseChecksTest extends TestCase
{
    /** @return array<string, array{0: list<string>, 1: string}> */
    public static function proseThatMustNotFail(): array
    {
        return [
            'the beat names a visor, the sheet does not' => [['welding helmet with visor down', 'leather gloves'], 'Adult fabricator; welding helmet as the work needs.'],
            'other wording for the same equipment' => [['auto-darkening hood', 'face shield lowered'], 'Adult fabricator; welding helmet as the work needs.'],
            'a state the sheet does not state' => [['helmet visor raised'], 'Rider in a plain full-face helmet.'],
            'another character name and project' => [['visor'], ''],
        ];
    }

    /**
     * @dataProvider proseThatMustNotFail
     * @param list<string> $items
     */
    public function test_wording_of_protection_never_fails_the_scene(array $items, string $sheet): void
    {
        $issues = $this->continuity([['character' => 'welder', 'items' => $items]], ['welder'], ['characters' => ['welder' => $sheet]]);

        self::assertSame([], $issues);
    }

    public function test_a_character_the_scene_does_not_show_still_fails(): void
    {
        $issues = $this->continuity([['character' => 'rider', 'items' => ['gloves']]], ['builder'], ['characters' => []]);

        self::assertCount(1, $issues);
        self::assertStringContainsString('không beat nào của scene có rider', $issues[0]);
    }

    public function test_a_character_named_twice_still_fails(): void
    {
        $issues = $this->continuity([['character' => 'builder', 'items' => []], ['character' => 'builder', 'items' => null]], ['builder'], ['characters' => []]);

        self::assertCount(1, $issues);
        self::assertStringContainsString('nêu builder hai lần', $issues[0]);
    }

    public function test_a_part_the_design_does_not_have_still_fails(): void
    {
        $issues = $this->continuity([], ['builder'], ['characters' => [], 'parts' => ['P01' => []]], [['part' => 'P99', 'status' => 'being_made']], 'BS07');

        self::assertNotSame([], array_filter($issues, static fn (string $issue): bool => str_contains($issue, 'P99')));
    }

    public function test_shot_warnings_do_not_read_the_prose_of_a_part(): void
    {
        $shot = ['id' => 'SH01', 'parts' => [['part' => 'P01']]];
        $context = [
            'states' => ['BS07' => ['physical' => true, 'surface' => 'bare steel', 'finishes' => []]],
            'part_records' => ['P01' => ['description' => 'Gloss midnight indigo tank.', 'faces' => [], 'surfaces' => [['finish' => 'gloss', 'color_id' => 'C1']]]],
            'palette' => ['C1' => ['name' => 'midnight indigo']],
        ];

        self::assertSame([], $this->surfaceWarnings($shot, 'BS07', $context));
    }

    public function test_a_state_with_no_surface_data_still_warns(): void
    {
        $warnings = $this->surfaceWarnings(['id' => 'SH01', 'parts' => []], 'BS07', ['states' => ['BS07' => ['physical' => true, 'surface' => null, 'finishes' => []]]]);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('không có mô tả bề mặt', $warnings[0]);
    }

    /**
     * @param  list<array<string, mixed>>  $protection
     * @param  list<string>  $onScreen
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $detached
     * @return list<string>
     */
    private function continuity(array $protection, array $onScreen, array $context, array $detached = [], ?string $state = null): array
    {
        $continuity = ['placement' => null, 'detached_parts' => $state === null ? null : $detached, 'lights_on' => $state === null ? null : [], 'surface_condition' => null, 'protection' => $protection, 'weather' => null];
        $states = $state === null ? [] : [$state => ['present' => []]];
        $method = new ReflectionMethod(MotoScenesCheck::class, 'continuityIssues');

        return $method->invoke(null, 'SC04.continuity_start', $continuity, $state, $onScreen, $context + ['states' => $states, 'lights' => []], []);
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    private function surfaceWarnings(array $shot, string $state, array $context): array
    {
        return (new ReflectionMethod(MotoShotsCheck::class, 'surfaceWarnings'))->invoke(null, $shot, $state, $context);
    }
}
