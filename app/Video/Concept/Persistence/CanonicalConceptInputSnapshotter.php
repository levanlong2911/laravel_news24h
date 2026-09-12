<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\ConceptInput;
use JsonException;
use RuntimeException;

final class CanonicalConceptInputSnapshotter
{
    /**
     * @return array{json:string,hash:string}
     */
    public function snapshot(ConceptInput $input): array
    {
        $data = $this->sortKeysRecursively($input->toArray());

        try {
            $json = json_encode(
                $data,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (JsonException $e) {
            throw new RuntimeException(
                'Unable to encode canonical concept input snapshot.',
                previous: $e,
            );
        }

        return [
            'json' => $json,
            'hash' => hash('sha256', $json),
        ];
    }

    private function sortKeysRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->sortKeysRecursively($child);
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
