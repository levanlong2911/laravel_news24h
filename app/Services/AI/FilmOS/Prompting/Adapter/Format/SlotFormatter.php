<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter\Format;

use App\Services\AI\FilmOS\Prompting\Plan\PlanSlot;

/**
 * Says ONE kind of planned content in one vendor's language.
 *
 * Dispatch is by PlanSlot, never by payload type: the same payload carries
 * different meanings in different slots — a SubjectDescriptor[] is the cast in
 * SUBJECT_PRIMARY, the staging in IN_FRAME, and the anatomy guard in ANATOMY.
 * Dispatching on the type would force a context argument back in, and that
 * argument would just be PlanSlot under another name.
 *
 * A formatter may serve several slots (slots() is plural) — three near-identical
 * subject classes would be worse than one that knows which slot it was handed.
 */
interface SlotFormatter
{
    /** @return PlanSlot[] the slots this formatter can say */
    public function slots(): array;

    /**
     * @param mixed $payload the typed value the slot promises (see PlanSlot)
     * @return string one line; '' to say nothing
     */
    public function format(PlanSlot $slot, mixed $payload): string;
}
