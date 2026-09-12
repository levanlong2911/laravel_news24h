<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical;

use JsonException;
use stdClass;

final class CanonicalDesignSpecSerializer
{
    /**
     * @throws JsonException
     */
    public function toJson(
        CanonicalDesignSpec $spec
    ): string {
        $data = $spec->toArray();

        /*
         * Core V1 contract noi bon field nay
         * la JSON object.
         *
         * Empty PHP [] phai serialize thanh {}.
         */
        $data['dimensions'] =
            $this->forceObject(
                $data['dimensions']
            );

        $data['permanent_geometry'] =
            $this->forceObject(
                $data['permanent_geometry']
            );

        $data['form_relationships'] =
            $this->forceObject(
                $data['form_relationships']
            );

        $data['finished_materials'] =
            $this->forceObject(
                $data['finished_materials']
            );

        return json_encode(
            $data,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR
        );
    }

    /**
     * @param  array<string,mixed>  $value
     */
    private function forceObject(
        array $value
    ): array|stdClass {
        if ($value === []) {
            return new stdClass;
        }

        return $value;
    }
}
