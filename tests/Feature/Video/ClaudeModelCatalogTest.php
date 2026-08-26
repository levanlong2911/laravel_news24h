<?php

namespace Tests\Feature\Video;

use App\Services\Admin\ClaudeWriterService;
use App\Video\Concept\ClaudeConceptDesigner;
use InvalidArgumentException;
use Tests\TestCase;

class ClaudeModelCatalogTest extends TestCase
{
    public function test_a_known_alias_is_supported_and_an_unknown_one_is_not(): void
    {
        $this->assertTrue(ClaudeWriterService::supports('haiku'));
        $this->assertTrue(ClaudeWriterService::supports('sonnet'));
        $this->assertFalse(ClaudeWriterService::supports('sonet'));
        $this->assertFalse(ClaudeWriterService::supports(''));
    }

    public function test_each_alias_resolves_to_the_model_id_on_its_own_row(): void
    {
        $this->assertSame('claude-haiku-4-5-20251001', ClaudeWriterService::modelId('haiku'));
        $this->assertSame('claude-sonnet-4-6', ClaudeWriterService::modelId('sonnet'));
    }

    public function test_the_alias_names_a_model_line_not_a_version(): void
    {
        $this->assertStringStartsWith('claude-sonnet-', ClaudeWriterService::modelId('sonnet'));
        $this->assertStringStartsWith('claude-haiku-', ClaudeWriterService::modelId('haiku'));
    }

    public function test_the_max_tokens_come_from_the_same_row_as_the_id(): void
    {
        $this->assertSame(4096, ClaudeWriterService::maxTokensFor('haiku'));
        $this->assertSame(8000, ClaudeWriterService::maxTokensFor('sonnet'));
    }

    public function test_an_unknown_alias_throws_instead_of_falling_back(): void
    {
        foreach (['modelId', 'maxTokensFor'] as $method) {
            try {
                ClaudeWriterService::$method('gpt-5');
                $this->fail("{$method}() phai nem voi khoa la, khong duoc roi ve dong khac");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('MODEL_CATALOG', $e->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        ClaudeWriterService::costUsd(1000, 500, 'gpt-5');
    }

    public function test_the_error_message_names_the_aliases_that_do_work(): void
    {
        try {
            ClaudeWriterService::modelId('sonet');
            $this->fail('phai nem');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('sonet', $e->getMessage());
            $this->assertStringContainsString('haiku', $e->getMessage());
            $this->assertStringContainsString('sonnet', $e->getMessage());
        }
    }

    public function test_the_price_is_read_from_the_row_the_id_came_from(): void
    {
        $this->assertSame(1.00, ClaudeWriterService::costUsd(1_000_000, 0, 'haiku'));
        $this->assertSame(5.00, ClaudeWriterService::costUsd(0, 1_000_000, 'haiku'));
        $this->assertSame(3.00, ClaudeWriterService::costUsd(1_000_000, 0, 'sonnet'));
        $this->assertSame(15.00, ClaudeWriterService::costUsd(0, 1_000_000, 'sonnet'));
    }

    public function test_two_model_lines_never_share_a_price(): void
    {
        $this->assertNotSame(
            ClaudeWriterService::costUsd(1_000_000, 1_000_000, 'haiku'),
            ClaudeWriterService::costUsd(1_000_000, 1_000_000, 'sonnet'),
        );
    }

    public function test_the_sonnet_five_row_resolves_to_its_own_model_id(): void
    {
        $this->assertTrue(ClaudeWriterService::supports('sonnet5'));
        $this->assertSame('claude-sonnet-5', ClaudeWriterService::modelId('sonnet5'));
        $this->assertSame(8000, ClaudeWriterService::maxTokensFor('sonnet5'));
    }

    public function test_the_two_sonnet_rows_point_at_different_models(): void
    {
        $this->assertNotSame(
            ClaudeWriterService::modelId('sonnet'),
            ClaudeWriterService::modelId('sonnet5'),
        );
        $this->assertSame('claude-sonnet-4-6', ClaudeWriterService::modelId('sonnet'));
    }

    public function test_the_two_sonnet_rows_are_priced_apart(): void
    {
        $this->assertSame(2.00, ClaudeWriterService::costUsd(1_000_000, 0, 'sonnet5'));
        $this->assertSame(10.00, ClaudeWriterService::costUsd(0, 1_000_000, 'sonnet5'));
        $this->assertSame(3.00, ClaudeWriterService::costUsd(1_000_000, 0, 'sonnet'));
        $this->assertSame(15.00, ClaudeWriterService::costUsd(0, 1_000_000, 'sonnet'));
    }

    public function test_the_concept_designer_runs_on_the_sonnet_five_row(): void
    {
        $this->assertSame('sonnet5', ClaudeConceptDesigner::MODEL);
        $this->assertTrue(ClaudeWriterService::supports(ClaudeConceptDesigner::MODEL));
        $this->assertSame('claude-sonnet-5', ClaudeWriterService::modelId(ClaudeConceptDesigner::MODEL));
    }
}
