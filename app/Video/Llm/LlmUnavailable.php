<?php

namespace App\Video\Llm;

/** Mô hình không gọi được. Fail fast — Truth Layer không đoán thay nó. */
final class LlmUnavailable extends \RuntimeException
{
}
