<?php

declare(strict_types=1);

namespace App\Video\Script;

use App\Video\Superyacht\Support\CanonicalJson;

final class ScriptContentHash
{
    public const VERSION = 'script-content-hash-v1';

    /**
     * @param  array<string, mixed>  $document  the author document exactly as the model returned it
     * @param  array<string, mixed>  $source  the pinned source of the run
     */
    public static function of(string $contract, array $document, array $source): string
    {
        return CanonicalJson::hash([
            'version' => self::VERSION,
            'contract' => $contract,
            'document' => $document,
            'source' => $source,
        ]);
    }
}
