<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use JsonException;
use RuntimeException;

final class CanonicalConceptInputLoader
{
    /**
     * @return array<string,mixed>
     */
    public function load(CanonicalConceptRevision $revision): array
    {
        $json = (string) $revision->concept_input_json;

        if (hash('sha256', $json) !== $revision->concept_input_hash) {
            throw new RuntimeException(
                'Canonical concept input snapshot hash mismatch.'
            );
        }

        try {
            $decoded = json_decode(
                $json,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException(
                'Unable to decode canonical concept input snapshot.',
                previous: $e,
            );
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException(
                'Canonical concept input snapshot root must be an object.'
            );
        }

        return $decoded;
    }
}
