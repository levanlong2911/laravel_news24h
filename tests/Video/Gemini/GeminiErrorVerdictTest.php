<?php

namespace Tests\Video\Gemini;

use App\Video\Gemini\GeminiErrorVerdict;
use PHPUnit\Framework\TestCase;

class GeminiErrorVerdictTest extends TestCase
{
    private const SENTINEL_LINE = "Invalid JSON payload received. Unknown name \"__probe_unknown_field__\" at 'generation_config': Cannot find field.";

    public function test_a_message_it_cannot_read_is_inconclusive(): void
    {
        [$verdict, $unknown, $invalid] = GeminiErrorVerdict::classify('Request contains an invalid argument.');

        $this->assertSame('inconclusive', $verdict);
        $this->assertSame([], $unknown);
        $this->assertSame([], $invalid);
    }

    public function test_a_wrong_field_name_is_a_field_rejection(): void
    {
        [$verdict, $unknown] = GeminiErrorVerdict::classify(
            "Invalid JSON payload received. Unknown name \"aspectRatio\" at 'generation_config.response_format': Cannot find field.\n"
            .self::SENTINEL_LINE,
        );

        $this->assertSame('field_rejected', $verdict);
        $this->assertSame(['aspectRatio', GeminiErrorVerdict::SENTINEL], array_column($unknown, 'name'));
    }

    public function test_a_right_field_with_a_wrong_value_is_a_value_rejection(): void
    {
        [$verdict, , $invalid] = GeminiErrorVerdict::classify(
            "Invalid value at 'generation_config.response_format.image.aspect_ratio' "
            ."(type.googleapis.com/google.ai.generativelanguage.v1.ImageResponseFormat.AspectRatio), \"9:16\"\n"
            .self::SENTINEL_LINE,
        );

        $this->assertSame('value_rejected', $verdict);
        $this->assertSame([[
            'at' => 'generation_config.response_format.image.aspect_ratio',
            'type' => 'type.googleapis.com/google.ai.generativelanguage.v1.ImageResponseFormat.AspectRatio',
            'value' => '9:16',
        ]], $invalid);
    }

    public function test_only_the_sentinel_means_the_body_was_accepted(): void
    {
        [$verdict] = GeminiErrorVerdict::classify(self::SENTINEL_LINE);

        $this->assertSame('field_accepted', $verdict);
    }

    public function test_a_wrong_field_outranks_a_wrong_value(): void
    {
        [$verdict] = GeminiErrorVerdict::classify(
            "Invalid value at 'a.b' (type.googleapis.com/x.AspectRatio), \"9:16\"\n"
            ."Invalid JSON payload received. Unknown name \"imageSizee\" at 'a': Cannot find field.\n"
            .self::SENTINEL_LINE,
        );

        $this->assertSame('field_rejected', $verdict);
    }

    public function test_an_unquoted_invalid_value_is_still_read(): void
    {
        [$verdict, , $invalid] = GeminiErrorVerdict::classify(
            "Invalid value at 'a.b' (type.googleapis.com/x.AspectRatio), 7\n".self::SENTINEL_LINE,
        );

        $this->assertSame('value_rejected', $verdict);
        $this->assertSame('7', $invalid[0]['value']);
    }
}
