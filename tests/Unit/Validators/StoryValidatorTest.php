<?php

namespace Tests\Unit\Validators;

use App\DTOs\BeatDTO;
use App\DTOs\StoryDTO;
use App\DTOs\TransformationDTO;
use App\Services\AI\Validators\StoryValidator;
use PHPUnit\Framework\TestCase;

class StoryValidatorTest extends TestCase
{
    private function transformation(int $duration = 15): TransformationDTO
    {
        return new TransformationDTO('motorcycle', 'cinematic', $duration, ['hook', 'craft', 'reveal', 'wow'], 'warm', 'medium');
    }

    private function beat(array $overrides = []): array
    {
        return array_merge([
            'goal'             => 'Default goal',
            'viewer_question'  => 'What happens next?',
            'information_type' => 'EMOTION',
            'visual_priority'  => 'MEDIUM',
            'emotion'          => 'anticipation',
            'duration'         => 5.0,
            'transition'       => 'cut',
            'narrative_intent' => 'Default intent',
        ], $overrides);
    }

    private function story(array $beatArrays): StoryDTO
    {
        $beats = array_values(array_map(
            fn (array $b, int $i) => BeatDTO::fromArray($b, $i + 1),
            $beatArrays,
            array_keys($beatArrays),
        ));
        return new StoryDTO($beats);
    }

    public function test_valid_story_passes(): void
    {
        $story = $this->story([
            $this->beat(['emotion' => 'anticipation', 'duration' => 4.0, 'information_type' => 'EMOTION']),
            $this->beat(['emotion' => 'craftsmanship', 'duration' => 4.0, 'information_type' => 'PROCESS', 'visual_priority' => 'HIGH']),
            $this->beat(['emotion' => 'awe',           'duration' => 4.0, 'information_type' => 'DETAIL']),
            $this->beat(['emotion' => 'power',         'duration' => 3.0, 'information_type' => 'SUMMARY']),
        ]);

        $this->expectNotToPerformAssertions();
        (new StoryValidator())->validate($story, $this->transformation(15));
    }

    public function test_duration_mismatch_throws(): void
    {
        $story = $this->story([
            $this->beat(['emotion' => 'anticipation', 'duration' => 5.0]),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/duration/');
        (new StoryValidator())->validate($story, $this->transformation(15));
    }

    public function test_first_beat_must_have_hook_emotion(): void
    {
        $story = $this->story([
            $this->beat(['emotion' => 'calm', 'duration' => 15.0]),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/hook/');
        (new StoryValidator())->validate($story, $this->transformation(15));
    }

    public function test_invalid_information_type_throws(): void
    {
        $story = $this->story([
            $this->beat(['emotion' => 'anticipation', 'duration' => 15.0, 'information_type' => 'VIBE']),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/information_type/');
        (new StoryValidator())->validate($story, $this->transformation(15));
    }

    public function test_invalid_visual_priority_throws(): void
    {
        $story = $this->story([
            $this->beat(['emotion' => 'anticipation', 'duration' => 15.0, 'visual_priority' => 'ULTRA']),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/visual_priority/');
        (new StoryValidator())->validate($story, $this->transformation(15));
    }

    public function test_zero_duration_beat_throws(): void
    {
        $story = $this->story([
            $this->beat(['emotion' => 'anticipation', 'duration' => 0.0]),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        (new StoryValidator())->validate($story, $this->transformation(15));
    }
}
