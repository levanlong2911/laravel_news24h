<?php

namespace Tests\Video\Director;

use App\Video\Director\DirectorSelectionFailed;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DirectorSelectionFailedTest extends TestCase
{
    public function test_it_carries_a_stable_reason_code(): void
    {
        $e = DirectorSelectionFailed::afterRetry(4, 2);

        $this->assertSame(4, $e->sceneOrdinal);
        $this->assertSame(2, $e->attempts);

        // Hai assertion, hai câu hỏi khác nhau — đừng dọn mất một cái: cái trên
        // khoá "factory dùng đúng constant", cái dưới khoá "giá trị đi vào log
        // không đổi".
        $this->assertSame(DirectorSelectionFailed::REASON_NO_VALID_INDEX_AFTER_RETRY, $e->reason);
        $this->assertSame('NO_VALID_INDEX_AFTER_RETRY', $e->reason);
    }

    public function test_the_message_carries_no_candidate_or_response_data(): void
    {
        $message = DirectorSelectionFailed::afterRetry(4, 2)->getMessage();

        $this->assertSame('Director selection failed for scene ordinal 4 after 2 attempts', $message);
    }

    /** @dataProvider invalidOrdinals */
    public function test_it_refuses_an_ordinal_below_one(int $ordinal): void
    {
        $this->expectException(InvalidArgumentException::class);

        DirectorSelectionFailed::afterRetry($ordinal, 2);
    }

    public static function invalidOrdinals(): array
    {
        return [[0], [-1]];
    }

    /** @dataProvider invalidAttempts */
    public function test_it_refuses_to_claim_a_retry_that_never_happened(int $attempts): void
    {
        $this->expectException(InvalidArgumentException::class);

        DirectorSelectionFailed::afterRetry(1, $attempts);
    }

    public static function invalidAttempts(): array
    {
        return [[1], [0], [-1]];
    }
}
