<?php

declare(strict_types=1);

namespace App\Video\Concept;

use JsonException;
use RuntimeException;

final class CanonicalJsonPayload
{
    /**
     * @param  array<string,mixed>  $data
     */
    private function __construct(
        public readonly string $rawJson,
        public readonly array $data,
    ) {}

    /**
     * @throws JsonException
     */
    public static function fromRawJson(
        string $rawJson
    ): self {
        $rawJson = trim($rawJson);

        if ($rawJson === '') {
            throw new RuntimeException(
                'Canonical JSON payload is empty.'
            );
        }

        /*
         * Parse object-mode truoc de dam bao
         * root JSON thuc su la object.
         *
         * Khong dung associative=true o buoc nay
         * vi can phan biet:
         *
         * {} -> stdClass
         * [] -> array
         */
        $root = json_decode(
            $rawJson,
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR
        );

        if (! $root instanceof \stdClass) {
            throw new RuntimeException(
                'Canonical JSON root must be an object.'
            );
        }

        /*
         * Sau khi da xac nhan root JSON object,
         * decode lan hai thanh associative array
         * de hydrate DTO.
         */
        $data = json_decode(
            $rawJson,
            associative: true,
            depth: 512,
            flags: JSON_THROW_ON_ERROR
        );

        if (
            ! is_array($data)
            || array_is_list($data)
        ) {
            throw new RuntimeException(
                'Canonical JSON root must decode '
                .'to an associative array.'
            );
        }

        return new self(
            rawJson: $rawJson,
            data: $data,
        );
    }
}
