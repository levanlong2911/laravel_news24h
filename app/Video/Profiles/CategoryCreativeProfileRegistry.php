<?php

declare(strict_types=1);

namespace App\Video\Profiles;

use InvalidArgumentException;

final class CategoryCreativeProfileRegistry
{
    /**
     * @var array<string,CategoryCreativeProfile>
     */
    private array $profiles = [];

    /**
     * @param  list<CategoryCreativeProfile>  $profiles
     */
    public function __construct(
        array $profiles
    ) {
        foreach ($profiles as $profile) {
            $this->register($profile);
        }
    }

    public function register(
        CategoryCreativeProfile $profile
    ): void {
        if (
            isset(
                $this->profiles[
                    $profile->key
                ]
            )
        ) {
            throw new InvalidArgumentException(
                'Duplicate category creative profile key: '
                .$profile->key
            );
        }

        $this->profiles[
            $profile->key
        ] = $profile;
    }

    public function get(
        string $key
    ): CategoryCreativeProfile {
        $key = trim($key);

        if (
            $key === ''
            || ! isset($this->profiles[$key])
        ) {
            throw CategoryCreativeProfileNotFound::forKey($key);
        }

        return $this->profiles[$key];
    }

    public function has(
        string $key
    ): bool {
        return isset(
            $this->profiles[
                trim($key)
            ]
        );
    }

    /**
     * @return list<CategoryCreativeProfile>
     */
    public function all(): array
    {
        return array_values(
            $this->profiles
        );
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys =
            array_keys(
                $this->profiles
            );

        sort(
            $keys,
            SORT_STRING
        );

        return $keys;
    }
}
