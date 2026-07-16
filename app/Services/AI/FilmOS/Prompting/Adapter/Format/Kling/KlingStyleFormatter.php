<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter\Format\Kling;

use App\Services\AI\FilmOS\Prompting\Adapter\Format\SlotFormatter;
use App\Services\AI\FilmOS\Prompting\IR\VisualStyle;
use App\Services\AI\FilmOS\Prompting\Plan\PlanSlot;

/**
 * The look, in Kling's words. One entry per VisualStyle, because an NFL play, a
 * wildlife documentary and a car commercial are not the same footage — and the
 * only reason they ever came out identical was a hardcoded line here.
 */
final class KlingStyleFormatter implements SlotFormatter
{
    private const LOOK = [
        'cinematic'          => 'Hyperrealistic cinematic footage, film grain, shallow depth of field, sharp focus.',
        'sports_documentary' => 'Hyperrealistic broadcast sports footage, long-lens documentary look, natural colour, sharp focus.',
        'nature_documentary' => 'Hyperrealistic wildlife documentary footage, long-lens, natural colour, no grain.',
        'luxury_commercial'  => 'Glossy high-end commercial footage, high contrast, specular highlights, pristine surfaces.',
        'vintage_film'       => 'Vintage 35mm film footage, visible grain, halation, slightly faded colour.',
        'digital_clean'      => 'Clean modern digital footage, crisp detail, neutral colour, no grain.',
        'horror'             => 'Cold desaturated footage, deep shadows, low-key lighting, unsettling stillness.',
        'anime'              => 'Hand-drawn anime animation, cel shading, expressive linework.',
        'comic'              => 'Comic-book illustration style, bold ink outlines, flat graphic colour.',
    ];

    public function slots(): array
    {
        return [PlanSlot::VISUAL_STYLE];
    }

    public function format(PlanSlot $slot, mixed $payload): string
    {
        assert($payload instanceof VisualStyle);
        return self::LOOK[$payload->value] ?? self::LOOK['cinematic'];
    }
}
