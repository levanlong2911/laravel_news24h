<?php

declare(strict_types=1);

return [

    'fallback_profile' => 'generic_physical_object',

    'object_type_map' => [
        'yacht' => 'marine_vessel',
        'superyacht' => 'marine_vessel',
        'motor_yacht' => 'marine_vessel',
        'sailing_yacht' => 'marine_vessel',
        'cargo_ship' => 'marine_vessel',
        'container_ship' => 'marine_vessel',
        'ferry' => 'marine_vessel',
        'passenger_ship' => 'marine_vessel',
        'passenger_aircraft' => 'aircraft',
        'business_jet' => 'aircraft',
        'cargo_aircraft' => 'aircraft',
        'private_jet' => 'aircraft',
        'sedan' => 'road_vehicle',
        'suv' => 'road_vehicle',
        'truck' => 'road_vehicle',
        'bus' => 'road_vehicle',
        'villa' => 'architecture',
        'house' => 'architecture',
        'office_tower' => 'architecture',
        'hotel_building' => 'architecture',
    ],

    'profiles' => [

        'generic_physical_object' => [
        'version' => '1.0',

        'schema_path' => resource_path(
            'ai/schemas/profiles/'
            .'generic_physical_object_v1.json'
        ),

        'inspection_aspects' => [
            'size_and_dimensions',
            'form_and_proportions',
            'primary_massing',
            'permanent_geometry',
            'openings',
            'spatial_layout',
            'materials',
        ],
        ],

        'marine_vessel' => [
        'version' => '1.0',

        'schema_path' => resource_path(
            'ai/schemas/profiles/'
            .'marine_vessel_v1.json'
        ),

        'inspection_aspects' => [
            'size_and_dimensions',
            'form_and_proportions',
            'primary_massing',
            'bow_geometry',
            'stern_geometry',
            'hull_geometry',
            'superstructure',
            'openings',
            'spatial_layout',
            'materials',
        ],
        ],

        'aircraft' => [
        'version' => '1.0',

        'schema_path' => resource_path(
            'ai/schemas/profiles/'
            .'aircraft_v1.json'
        ),

        'inspection_aspects' => [
            'size_and_dimensions',
            'fuselage_geometry',
            'wing_geometry',
            'engine_configuration',
            'tail_geometry',
            'landing_configuration',
            'openings',
            'materials',
        ],
        ],

        'architecture' => [
        'version' => '1.0',

        'schema_path' => resource_path(
            'ai/schemas/profiles/'
            .'architecture_v1.json'
        ),

        'inspection_aspects' => [
            'size_and_dimensions',
            'primary_massing',
            'floor_organization',
            'facade_geometry',
            'opening_pattern',
            'circulation',
            'structural_expression',
            'materials',
        ],
        ],

        'road_vehicle' => [
        'version' => '1.0',

        'schema_path' => resource_path(
            'ai/schemas/profiles/'
            .'road_vehicle_v1.json'
        ),

        'inspection_aspects' => [
            'size_and_dimensions',
            'body_geometry',
            'cabin_proportion',
            'wheel_configuration',
            'opening_pattern',
            'lighting_geometry',
            'materials',
        ],
        ],

        'industrial_machine' => [
        'version' => '1.0',

        'schema_path' => resource_path(
            'ai/schemas/profiles/'
            .'industrial_machine_v1.json'
        ),

        'inspection_aspects' => [
            'size_and_dimensions',
            'primary_massing',
            'structural_frame',
            'functional_modules',
            'interfaces',
            'openings',
            'materials',
        ],
        ],

    ],

];
