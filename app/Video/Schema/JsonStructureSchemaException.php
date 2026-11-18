<?php

declare(strict_types=1);

namespace App\Video\Schema;

use RuntimeException;

/** A released schema holds a form the structure check does not support: a fault of the check's configuration, never of the answer it checks. */
final class JsonStructureSchemaException extends RuntimeException
{
    public function __construct(public readonly string $schema, public readonly string $pointer, string $why)
    {
        parent::__construct("Bộ kiểm cấu trúc chưa hỗ trợ schema {$schema} tại ".($pointer === '' ? '(gốc)' : $pointer).": {$why} — lỗi cấu hình bộ kiểm, không phải lỗi câu trả lời AI.");
    }
}
