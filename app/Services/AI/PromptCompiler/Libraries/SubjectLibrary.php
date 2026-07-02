<?php

namespace App\Services\AI\PromptCompiler\Libraries;

/**
 * Subject knowledge base: actor/role → display name and contextual attributes.
 *
 * Planner outputs actor role ("mechanic"). PromptCompiler enriches to
 * "experienced motorcycle mechanic" without knowing the exact prompt wording.
 */
final class SubjectLibrary
{
    public const VERSION = '1.0';

    private const SUBJECTS = [
        // Human roles
        'mechanic'    => ['display' => 'experienced motorcycle mechanic',  'clothes' => 'black workshop uniform'],
        'engineer'    => ['display' => 'skilled mechanical engineer',      'clothes' => 'technical workwear'],
        'rider'       => ['display' => 'motorcycle rider',                 'clothes' => 'full riding gear'],
        'technician'  => ['display' => 'precision workshop technician',    'clothes' => 'technical uniform'],
        'craftsman'   => ['display' => 'master craftsman',                 'clothes' => 'workshop apron'],
        'welder'      => ['display' => 'skilled welder',                   'clothes' => 'welding mask and gloves'],
        'designer'    => ['display' => 'industrial designer',              'clothes' => 'clean workshop attire'],
        'inspector'   => ['display' => 'quality control inspector',        'clothes' => 'professional uniform'],
        // Vehicles / objects as primary subjects
        'motorcycle'  => ['display' => 'custom built scrambler motorcycle', 'clothes' => ''],
        'vehicle'     => ['display' => 'vehicle',                           'clothes' => ''],
        'car'         => ['display' => 'performance car',                   'clothes' => ''],
    ];

    public static function displayName(string $actor): string
    {
        $key = strtolower(trim($actor));
        return self::SUBJECTS[$key]['display'] ?? $actor;
    }

    public static function clothes(string $actor): string
    {
        $key = strtolower(trim($actor));
        return self::SUBJECTS[$key]['clothes'] ?? '';
    }
}
