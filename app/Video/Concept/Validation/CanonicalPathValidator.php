<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

final class CanonicalPathValidator
{
    /**
     * @param array<string,mixed> $document
     */
    public function exists(
        array $document,
        string $path
    ): bool {
        [, $found] = $this->resolve(
            $document,
            $path
        );

        return $found;
    }

    /**
     * @param array<string,mixed> $document
     */
    public function value(
        array $document,
        string $path
    ): mixed {
        [$value, $found] = $this->resolve(
            $document,
            $path
        );

        if (!$found) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $document
     *
     * @return array{0:mixed,1:bool}
     */
    public function resolveWithStatus(
        array $document,
        string $path
    ): array {
        return $this->resolve(
            $document,
            $path
        );
    }

    /**
     * @param array<string,mixed> $document
     *
     * @return array{0:mixed,1:bool}
     */
    private function resolve(
        array $document,
        string $path
    ): array {
        $path = trim($path);

        if ($path === '') {
            return [
                null,
                false,
            ];
        }

        $cursor = $document;

        foreach (
            explode('.', $path)
            as $segment
        ) {
            if ($segment === '') {
                return [
                    null,
                    false,
                ];
            }

            if (
                !is_array($cursor)
                || !array_key_exists(
                    $segment,
                    $cursor
                )
            ) {
                return [
                    null,
                    false,
                ];
            }

            $cursor =
                $cursor[$segment];
        }

        return [
            $cursor,
            true,
        ];
    }
}
