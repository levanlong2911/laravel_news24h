<?php

namespace Tests\Unit\DTOs;

use App\DTOs\PipelineContext;
use PHPUnit\Framework\TestCase;

class PipelineContextTest extends TestCase
{
    public function test_creates_with_defaults(): void
    {
        $ctx = new PipelineContext(projectId: 'proj-123');

        $this->assertSame('proj-123', $ctx->projectId);
        $this->assertSame('1.0', $ctx->plannerVersion);
        $this->assertSame('1.0', $ctx->contractVersion);
        $this->assertNotEmpty($ctx->correlationId);
    }

    public function test_cache_hash_changes_with_version(): void
    {
        $input = ['scene' => 'Hook', 'duration' => 3];

        $ctx1 = new PipelineContext('proj-1', plannerVersion: '1.0');
        $ctx2 = new PipelineContext('proj-1', plannerVersion: '1.1');

        $this->assertNotSame($ctx1->cacheHash($input), $ctx2->cacheHash($input), 'Different planner version must produce different hash');
    }

    public function test_cache_hash_same_input_same_version(): void
    {
        $input = ['scene' => 'Hook', 'duration' => 3];
        $ctx   = new PipelineContext('proj-1');

        $this->assertSame($ctx->cacheHash($input), $ctx->cacheHash($input));
    }

    public function test_cache_hash_changes_with_contract_version(): void
    {
        $input = ['data' => 'same'];
        $ctx1  = new PipelineContext('proj-1', contractVersion: '1.0');
        $ctx2  = new PipelineContext('proj-1', contractVersion: '1.1');

        $this->assertNotSame($ctx1->cacheHash($input), $ctx2->cacheHash($input));
    }
}
