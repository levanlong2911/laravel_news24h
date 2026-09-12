<?php

declare(strict_types=1);

namespace App\Video\Concept\Support;

use RuntimeException;

final class PromptFileLoader
{
    public function load(
        string $path
    ): string {
        if (! is_file($path)) {
            throw new RuntimeException(
                'Prompt file not found: '
                .$path
            );
        }

        $content =
            file_get_contents(
                $path
            );

        if ($content === false) {
            throw new RuntimeException(
                'Unable to read prompt file: '
                .$path
            );
        }

        $content =
            trim($content);

        if ($content === '') {
            throw new RuntimeException(
                'Prompt file is empty: '
                .$path
            );
        }

        return $content;
    }
}
