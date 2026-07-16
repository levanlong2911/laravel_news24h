<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Selection;

/**
 * Article Model + who is on screen -> what this shot may say (ADR-019 §6.1).
 *
 * The signature is the boundary. A policy receives the article and a BeatContext
 * and NOTHING else: no reference selection to imitate, no authored prose to copy,
 * no vendor, no budget. A policy that needed any of those would be scoring itself.
 */
interface SelectionPolicy
{
    public function select(ArticleModel $model, BeatContext $context): ShotTruth;
}
