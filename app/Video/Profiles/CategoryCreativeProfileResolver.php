<?php

declare(strict_types=1);

namespace App\Video\Profiles;

use InvalidArgumentException;

final class CategoryCreativeProfileResolver
{
    /**
     * @param  array<string,string>  $objectTypeMap
     */
    public function __construct(
        private readonly CategoryCreativeProfileRegistry $registry,
        private readonly array $objectTypeMap,
        private readonly string $fallbackProfileKey = 'generic_physical_object',
    ) {
        $this->guardConfiguration();
    }

    public function resolve(string $objectType): CategoryCreativeProfile
    {
        $objectType = $this->normalizeObjectType($objectType);

        $profileKey =
            $this->objectTypeMap[$objectType]
            ?? (
                $this->registry->has($objectType)
                    ? $objectType
                    : $this->fallbackProfileKey
            );

        return $this->registry
            ->get($profileKey);
    }

    public function isCompatible(
        string $objectType,
        CategoryCreativeProfile $profile
    ): bool {
        return $this->resolve($objectType)->key === $profile->key;
    }

    private function normalizeObjectType(string $objectType): string
    {
        $value = strtolower(trim($objectType));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        $value = trim((string) $value, '_');

        if ($value === '') {
            throw new InvalidArgumentException(
                'objectType must not be empty.'
            );
        }

        return $value;
    }

    private function guardConfiguration(): void
    {
        if (! $this->registry->has($this->fallbackProfileKey)) {
            throw new InvalidArgumentException(
                'Fallback category profile does not exist: '
                .$this->fallbackProfileKey
            );
        }

        foreach ($this->objectTypeMap as $objectType => $profileKey) {
            if (
                ! is_string($objectType)
                || trim($objectType) === ''
                || ! is_string($profileKey)
                || trim($profileKey) === ''
            ) {
                throw new InvalidArgumentException(
                    'Invalid object_type_map entry.'
                );
            }

            if (! $this->registry->has($profileKey)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'object_type_map references unknown profile "%s".',
                        $profileKey
                    )
                );
            }
        }
    }
}
