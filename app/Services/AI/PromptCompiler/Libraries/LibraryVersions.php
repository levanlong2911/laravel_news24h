<?php

namespace App\Services\AI\PromptCompiler\Libraries;

/**
 * Snapshot of all Knowledge Library versions at call time.
 * Include in pipeline_runs.decision_trace so any output can be reproduced
 * exactly by replaying with the same library versions.
 */
final class LibraryVersions
{
    public static function all(): array
    {
        return [
            'asset'         => AssetLibrary::VERSION,
            'environment'   => EnvironmentLibrary::VERSION,
            'emotion'       => EmotionLibrary::VERSION,
            'quality'       => QualityLibrary::VERSION,
            'subject'       => SubjectLibrary::VERSION,
            'shot_grammar'  => \App\Services\AI\PromptCompiler\Libraries\ShotGrammarLibrary::VERSION,
            'motion_rules'  => \App\Services\AI\PromptCompiler\Libraries\MotionRulesLibrary::VERSION,
        ];
    }
}
