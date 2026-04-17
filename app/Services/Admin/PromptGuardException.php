<?php

namespace App\Services\Admin;

/**
 * Thrown by PromptGuard when the pipeline cannot proceed safely.
 * Unlike PostGuardResult (which returns), PromptGuard throws — these are
 * pre-conditions that must be met before calling Sonnet.
 */
class PromptGuardException extends \RuntimeException
{
    public function __construct(
        string                  $message,
        public readonly string  $field = '',  // 'hook' | 'structure_template'
        ?\Throwable             $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
