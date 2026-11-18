<?php

declare(strict_types=1);

namespace App\Video\Script;

/** A checked workflow whose schema check holds an answer to its JSON structure only; its check records by which version. */
interface StructureChecked
{
    /** @var string the code of a schema violation that is a fault of the check itself: the answer is left unscored, never failed */
    public const CONFIG_FAULT = 'SCHEMA_CONFIG';

    public function structurePolicy(): string;

    /**
     * @param  array<string, mixed>  $check  the result of the phase's rules
     * @return array<string, mixed> the same result with each finding where it belongs: an issue (RULE), a requirement for the next step, or a warning
     */
    public function sortFindings(array $check): array;
}
