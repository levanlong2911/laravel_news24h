<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter\Format\Kling;

use App\Services\AI\FilmOS\Narrative\Scene\SceneNodeType;
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
            PlanSlot::IN_FRAME           => $this->staging($payload),
            default                      => '',
        };
    }

    /**
     * Staging keeps the tier. Listing everything present as one flat set —
     * "In frame: Moonrise and Nebula support vessel" — tells the model the two
     * are equals and invites the support vessel to fight the yacht for the shot.
     * The tier is already in the data (SceneNodeType); flattening it here threw
     * it away, and the SUBJECTS block cannot be relied on to carry it because
     * the background tier is OPTIONAL and the budget drops it first.
     *
     * @param SubjectDescriptor[] $subjects
     */
    private function staging(array $subjects): string
    {
        $front = $back = [];
        foreach ($subjects as $s) {
            if ($s->nodeType === SceneNodeType::BACKGROUND) {
                $back[] = $s->label;
            } else {
                $front[] = $s->label;
            }
        }

        $lines = [];
        if ($front !== []) {
            $lines[] = 'In frame: ' . $this->join(array_values(array_unique($front))) . '.';
        }
        if ($back !== []) {
            $lines[] = 'Far behind, small in frame: ' . $this->join(array_values(array_unique($back))) . '.';
        }
        return implode("\n", $lines);
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
