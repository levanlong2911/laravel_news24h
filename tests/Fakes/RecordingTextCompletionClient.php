<?php

namespace Tests\Fakes;

use App\Video\Prompt\TextCompletionClient;
use App\Video\Prompt\TextCompletionResponse;
use Closure;
use Throwable;

class RecordingTextCompletionClient implements TextCompletionClient
{
    public int $calls = 0;

    public int $authorCalls = 0;

    public int $reviewCalls = 0;

    public ?string $lastSystem = null;

    public ?string $lastUser = null;

    /** @var array<string, mixed>|null */
    public ?array $lastSchema = null;

    public ?string $authorSystem = null;

    public ?string $authorUser = null;

    /** @var array<string, mixed>|null */
    public ?array $authorSchema = null;

    public ?string $reviewSystem = null;

    /** @var array<string, mixed>|null */
    public ?array $reviewSchema = null;

    /** @var list<string> */
    public array $reviewUsers = [];

    public string $text = '{"scenes":[]}';

    public string $reviewText = '{"verdict":"pass","findings":[],"patch":[]}';

    /**
     * Cau tra loi cua reviewer THEO TUNG LUOT. Phan tu la:
     *   string     -> tra ve lam text
     *   Throwable  -> nem ra
     *   array      -> ghi de truong cua response, vi du ['text' => '...', 'stopReason' => 'length']
     * Het hang doi thi quay ve `$reviewText`.
     *
     * @var list<string|Throwable|array<string, mixed>>
     */
    public array $reviewQueue = [];

    public string $stopReason = 'stop';

    public string $model = 'gpt-5.6-terra';

    public int $inputTokens = 4210;

    public int $outputTokens = 1180;

    public int $reasoningTokens = 640;

    public ?Closure $before = null;

    /** @param array<string,mixed>|null $outputSchema */
    public function complete(
        string $model,
        string $system,
        string $user,
        int $maxTokens,
        ?array $outputSchema = null,
    ): TextCompletionResponse {
        $isReview = isset($outputSchema['properties']['verdict']);

        $this->calls++;
        $this->lastSystem = $system;
        $this->lastUser = $user;
        $this->lastSchema = $outputSchema;

        if ($isReview) {
            $this->reviewCalls++;
            $this->reviewSystem = $system;
            $this->reviewSchema = $outputSchema;
            $this->reviewUsers[] = $user;
        } else {
            $this->authorCalls++;
            $this->authorSystem = $system;
            $this->authorUser = $user;
            $this->authorSchema = $outputSchema;
        }

        if ($this->before !== null) {
            ($this->before)();
        }

        $overrides = [];

        if ($isReview) {
            $next = array_shift($this->reviewQueue) ?? $this->reviewText;

            if ($next instanceof Throwable) {
                throw $next;
            }

            $overrides = is_array($next) ? $next : ['text' => $next];
        }

        return new TextCompletionResponse(
            text: $overrides['text'] ?? ($isReview ? $this->reviewText : $this->text),
            model: $overrides['model'] ?? $this->model,
            stopReason: $overrides['stopReason'] ?? $this->stopReason,
            inputTokens: $overrides['inputTokens'] ?? $this->inputTokens,
            outputTokens: $overrides['outputTokens'] ?? $this->outputTokens,
            requestId: 'req_test',
            reasoningTokens: $overrides['reasoningTokens'] ?? $this->reasoningTokens,
        );
    }
}
