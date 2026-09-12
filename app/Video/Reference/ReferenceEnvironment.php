<?php

namespace App\Video\Reference;

enum ReferenceEnvironment: string
{
    case NEUTRAL_STUDIO = 'neutral_studio';
    case SHIPYARD_HALL = 'shipyard_hall';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::NEUTRAL_STUDIO => 'Neutral Studio',
            self::SHIPYARD_HALL => 'Shipyard Hall',
        };
    }

    public function override(): string
    {
        return match ($this) {
            self::NEUTRAL_STUDIO => '',
            self::SHIPYARD_HALL => 'ENVIRONMENT OVERRIDE: The vessel stands alone inside a shipyard hall. '
                .'Hull geometry, topology, proportions, surface state and camera direction stay exactly as stated above; '
                .'only the surrounding field changes from the neutral background to the hall interior.',
        };
    }
}
