<?php

namespace Tests\Feature\Video;

use Tests\TestCase;

class GoldenGeometryBaselineTest extends TestCase
{
    private const DIR = 'tests/Fixtures/concept/golden_superyacht';

    private function fixture(string $file): string
    {
        $path = base_path(self::DIR.'/'.$file);

        $this->assertFileExists($path, "Thieu {$file} trong golden baseline.");

        return (string) file_get_contents($path);
    }

    /** @return array<string, mixed> */
    private function fixtureJson(string $file): array
    {
        return json_decode($this->fixture($file), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_it_locks_the_known_good_geometry_prompt(): void
    {
        $this->assertSame(
            config('video.tests.known_good_geometry_prompt_sha256'),
            hash('sha256', $this->fixture('compiled_prompt.txt')),
        );
    }

    public function test_it_locks_the_render_that_prompt_produced(): void
    {
        $image = $this->fixture('render.png');

        $this->assertSame(
            config('video.tests.known_good_geometry_render_sha256'),
            hash('sha256', $image),
        );

        [$width, $height] = array_values(unpack('N2', substr($image, 16, 8)));

        $this->assertSame('1536x1024', "{$width}x{$height}");
        $this->assertSame($this->fixtureJson('metadata.json')['size'], "{$width}x{$height}");
    }

    public function test_the_metadata_claims_only_what_this_baseline_can_prove(): void
    {
        $metadata = $this->fixtureJson('metadata.json');

        $this->assertSame('chatgpt_ui', $metadata['provider']);

        foreach ([
            'instruction_version', 'profile_code', 'profile_version',
            'projection_version', 'compiler_version', 'template_version',
            'model', 'quality',
        ] as $field) {
            $this->assertArrayHasKey($field, $metadata);
            $this->assertNull($metadata[$field], "{$field} phai la null: baseline nay khong di qua pipeline.");
        }
    }

    public function test_the_baseline_records_where_the_render_left_its_own_prompt(): void
    {
        $expectations = $this->fixtureJson('qa_expectations.json');

        $this->assertFalse($expectations['human_confirmed']);
        $this->assertNotEmpty($expectations['held']);
        $this->assertNotEmpty($expectations['deviated']);
    }

    public function test_the_baseline_carries_every_file_the_contract_names(): void
    {
        foreach ([
            'inspiration_input.json', 'raw_concept_response.json', 'canonical_concept.json',
            'geometry_projection.json', 'compiled_prompt.txt', 'prompt_sections.json',
            'render_request.json', 'qa_expectations.json', 'metadata.json',
        ] as $file) {
            $this->assertFileExists(base_path(self::DIR.'/'.$file));
        }
    }

    /**
     * Nam artefact cua pipeline khong the co that o baseline nay. Chung phai
     * NOI RA la khong co, chu khong duoc dung du lieu bia de lam day cho.
     */
    public function test_a_pipeline_artefact_this_baseline_never_had_says_so(): void
    {
        foreach ([
            'inspiration_input.json', 'raw_concept_response.json',
            'canonical_concept.json', 'geometry_projection.json', 'render_request.json',
        ] as $file) {
            $artefact = $this->fixtureJson($file);

            $this->assertFalse($artefact['available'], "{$file} khong duoc khai la co san.");
            $this->assertNotEmpty($artefact['reason']);
            $this->assertNotEmpty($artefact['expected_from']);
        }
    }

    public function test_the_section_inventory_is_read_off_the_prompt_itself(): void
    {
        $inventory = $this->fixtureJson('prompt_sections.json');
        $prompt = $this->fixture('compiled_prompt.txt');

        $this->assertTrue($inventory['available']);
        $this->assertSame($inventory['section_count'], count($inventory['sections']));

        foreach ($inventory['sections'] as $section) {
            $this->assertStringContainsString($section['name'], $prompt);
        }

        // Nhung khoi Compiler V1 khong he phat ra — do chinh la khoang cach
        // giua baseline va duong ong.
        $names = array_column($inventory['sections'], 'name');

        foreach (['TIER-COUNTING RULE', 'ABSOLUTELY NO BULBOUS BOW', 'HARD CONSTRAINTS'] as $absent) {
            $this->assertContains($absent, $names);
        }
    }
}
