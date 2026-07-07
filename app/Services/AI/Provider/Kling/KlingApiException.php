<?php

namespace App\Services\AI\Provider\Kling;

use App\Services\AI\Provider\Kling\Dto\ApiError;

final class KlingApiException extends \RuntimeException
{
    public function __construct(public readonly ApiError $error)
    {
        parent::__construct((string) $error);
    }
}
