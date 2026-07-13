<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Pipeline;

use App\Services\AI\FilmOS\Production\ProductionResult;

interface RenderAssetAssembler
{
    /**
     * Download successful shots and merge into a single output file.
     *
     * @param  RenderedShot[]  $shots       all shots (success and failure); implementations filter
     * @param  string          $outputDir   root directory for this production
     * @param  string          $productionId
     * @return ProductionResult
     */
    public function assemble(array $shots, string $outputDir, string $productionId): ProductionResult;
}
