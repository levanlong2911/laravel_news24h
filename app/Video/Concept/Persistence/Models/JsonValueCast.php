<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Models;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use JsonException;
use RuntimeException;

final class JsonValueCast implements CastsAttributes
{
    public function get(
        Model $model,
        string $key,
        mixed $value,
        array $attributes
    ): mixed {
        if ($value === null) {
            return null;
        }

        try {
            return json_decode(
                (string) $value,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException(
                "Unable to decode JSON attribute {$key}.",
                previous: $e,
            );
        }
    }

    public function set(
        Model $model,
        string $key,
        mixed $value,
        array $attributes
    ): mixed {
        if ($value === null) {
            return null;
        }

        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (JsonException $e) {
            throw new RuntimeException(
                "Unable to encode JSON attribute {$key}.",
                previous: $e,
            );
        }
    }
}
