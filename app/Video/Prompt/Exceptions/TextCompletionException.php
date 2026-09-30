<?php

declare(strict_types=1);

namespace App\Video\Prompt\Exceptions;

use RuntimeException;

final class TextCompletionException extends RuntimeException
{
    /** @var array<string, mixed> */
    public array $usage = [];

    public string $raw = '';

    public ?string $model = null;

    /**
     * @param  array<string, mixed>  $usage
     */
    public static function afterResponse(string $message, array $usage, string $raw, ?string $model): self
    {
        $exception = new self($message);
        $exception->usage = $usage;
        $exception->raw = $raw;
        $exception->model = $model;

        return $exception;
    }
}
