<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

enum EventOrigin: string
{
    case MotionBeatStage      = 'MotionBeatStage';
    case CameraArcStage       = 'CameraArcStage';
    case TemporalAssemblyStage = 'TemporalAssemblyStage';
    case Optimizer            = 'Optimizer';
    case PhysicsPlanner       = 'PhysicsPlanner';
    case HumanEdited          = 'HumanEdited';
    case LLMGenerated         = 'LLMGenerated';
    case RuleBased            = 'RuleBased';
    case Imported             = 'Imported';
    case Unknown              = 'unknown';
}
