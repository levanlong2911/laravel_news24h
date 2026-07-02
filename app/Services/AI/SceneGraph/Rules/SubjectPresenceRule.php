<?php

namespace App\Services\AI\SceneGraph\Rules;

use App\Services\AI\SceneGraph\ShotSceneGraph;

final class SubjectPresenceRule implements SceneRule
{
    public function validate(ShotSceneGraph $graph): array
    {
        if ($graph->subject->actor === '') {
            return [[
                'field'    => 'subject.actor',
                'expected' => 'non-empty actor label',
                'actual'   => '',
            ]];
        }
        return [];
    }

    public function name(): string
    {
        return 'subject_presence';
    }
}
