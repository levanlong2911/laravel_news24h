<?php

namespace App\Services\AI\PromptCompiler\PromptDocument;

final class CameraBlock
{
    public function __construct(
        public readonly string $type,       // "extreme macro close-up revealing fine detail"
        public readonly string $lens,       // "85mm portrait lens"
        public readonly string $move,       // "slow smooth push-in toward subject"
        public readonly bool   $isStatic,
    ) {}
}
