<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Shared;

/**
 * Generic case-insensitive key-value bag for semantic attributes.
 *
 * Shared infrastructure — carries NO domain semantics. Owned by Narrative\Shared
 * precisely so that World, Character, Scene (and future profiles) can all use it
 * without creating cross-domain dependencies.
 *
 * Consumers: WorldObject::$attributes, CharacterProfile::$appearance, …
 */
final class AttributeBag
{
    /** @var array<string, mixed> */
    private array $data;

    public function __construct(array $attributes = [])
    {
        $this->data = [];
        foreach ($attributes as $key => $value) {
            $this->data[strtolower((string) $key)] = $value;
        }
    }

    public static function empty(): self
    {
        return new self();
    }

    public static function from(array $attributes): self
    {
        return new self($attributes);
    }

    public function getString(string $key, string $default = ''): string
    {
        $k = strtolower($key);
        return isset($this->data[$k]) ? (string) $this->data[$k] : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $k   = strtolower($key);
        $val = $this->data[$k] ?? null;
        return $val !== null ? (bool) $val : $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $k   = strtolower($key);
        $val = $this->data[$k] ?? null;
        return $val !== null ? (int) $val : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists(strtolower($key), $this->data);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }
}
