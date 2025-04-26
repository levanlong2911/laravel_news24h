<?php

namespace App\Traits;

trait EnumTrait
{
    /**
     * Check if the current enum's value equals the provided value.
     *
     * @param mixed $value
     * @return bool
     */
    public function equals(mixed $value): bool
    {
        // Ensure the calling class is an Enum
        if (!method_exists($this, 'cases')) {
            throw new \LogicException('EnumTrait can only be used with Enums.');
        }

        return $this->value === $value;
    }

    public static function options(): array
    {
        return array_column(self::cases(), 'value', 'name');
    }
}
