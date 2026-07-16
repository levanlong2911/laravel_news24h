<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter\Format\Kling;

use App\Services\AI\FilmOS\Prompting\Adapter\Format\SlotFormatter;
use App\Services\AI\FilmOS\Prompting\IR\SubjectDescriptor;
use App\Services\AI\FilmOS\Prompting\Plan\PlanSlot;

/**
 * Everything about who is on screen. Five slots, one formatter — the payload is
 * the same SubjectDescriptor in all of them and only the slot says whether it
 * means the cast, the staging, the attention, or the anatomy guard. That is
 * exactly why dispatch is by slot and not by type.
 */
final class KlingSubjectFormatter implements SlotFormatter
{
    use JoinsPhrases;

    /** WorldObjectType → anatomy guard. Typed knowledge, never a regex on the label. */
    private const ANATOMY = [
        'character' => 'natural human anatomy, correct limb count, realistic hands',
        'animal'    => 'correct animal anatomy, natural coat, no human features',
        'vehicle'   => 'accurate mechanical detail, no human figures, no floating limbs',
    ];

    public function slots(): array
    {
        return [
            PlanSlot::SUBJECT_PRIMARY,
            PlanSlot::SUBJECT_SECONDARY,
            PlanSlot::SUBJECT_BACKGROUND,
            PlanSlot::ANATOMY,
            PlanSlot::IN_FRAME,
        ];
    }

    public function format(PlanSlot $slot, mixed $payload): string
    {
        return match ($slot) {
            PlanSlot::SUBJECT_PRIMARY    => 'Primary: ' . $this->described($payload) . '.',
            PlanSlot::SUBJECT_SECONDARY  => 'Secondary: ' . $this->described($payload) . '.',
            PlanSlot::SUBJECT_BACKGROUND => 'Background: ' . $this->described($payload) . '.',
            PlanSlot::ANATOMY            => $this->anatomy($payload),
            PlanSlot::IN_FRAME           => 'In frame: ' . $this->named($payload) . '.',
            default                      => '',
        };
    }

    /** Full identity: label plus the authored appearance that keeps it consistent. */
    private function described(array $subjects): string
    {
        return $this->join(array_map(
            function (SubjectDescriptor $s): string {
                // Authored appearance beats bare world-object attributes.
                $detail = $s->appearance !== [] ? array_values($s->appearance) : array_values($s->attributes->all());
                return $detail === [] ? $s->label : $s->label . ' (' . implode(', ', $detail) . ')';
            },
            $subjects,
        ));
    }

    /** Staging only needs names — identity was already established once, globally. */
    private function named(array $subjects): string
    {
        return $this->join(array_values(array_unique(
            array_map(static fn(SubjectDescriptor $s): string => $s->label, $subjects),
        )));
    }

    private function anatomy(array $subjects): string
    {
        $guards = [];
        foreach ($subjects as $s) {
            $guard = self::ANATOMY[$s->type->value] ?? null;
            if ($guard !== null) {
                $guards[$guard] = true;   // two vehicles → one guard
            }
        }
        return $guards === [] ? '' : $this->sentence(implode('. ', array_keys($guards)));
    }
}
