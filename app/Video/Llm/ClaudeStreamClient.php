<?php

declare(strict_types=1);

namespace App\Video\Llm;

use App\Services\Admin\ClaudeWriterService;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

final class ClaudeStreamClient implements LlmClient
{
    public const ENDED_MESSAGE_STOP = 'message_stop';

    public const ENDED_CONNECT_ERROR = 'connect_error';

    public const ENDED_HTTP_ERROR = 'http_error';

    public const ENDED_IDLE_TIMEOUT = 'idle_timeout';

    public const ENDED_TOTAL_TIMEOUT = 'total_timeout';

    public const ENDED_STREAM_CUT = 'stream_cut';

    public const ENDED_STREAM_ERROR = 'stream_error';

    public const ENDED_CANCELLED = 'cancelled';

    private const DEFAULT_TIMEOUT_SECONDS = 600;

    private readonly AnthropicTransport $transport;

    /**
     * @param  array<string, mixed>  $thinking  sent as the request's `thinking`
     * @param  ?ClaudeWriterService  $limiter  the shared requests-per-minute and concurrency limit, held for the whole call
     */
    public function __construct(
        HttpFactory $http,
        private readonly string $apiKey,
        string $baseUrl,
        string $apiVersion,
        private readonly int $idleTimeoutSeconds,
        private readonly int $progressEverySeconds,
        private readonly array $thinking,
        private readonly ?ClaudeWriterService $limiter = null,
        private readonly int $retrySleepMs = 2000,
    ) {
        if ($this->idleTimeoutSeconds < 1) {
            throw new RuntimeException('Claude stream idle timeout must be >= 1 second.');
        }

        if ($this->progressEverySeconds < 0) {
            throw new RuntimeException('Claude stream progress interval must be >= 0 seconds.');
        }

        $this->transport = new AnthropicTransport($http, $apiKey, $baseUrl, $apiVersion);
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $modelType = $request->model;

        if (! ClaudeWriterService::supports($modelType)) {
            throw new LlmUnavailable("Model không hỗ trợ: {$modelType} — xem ClaudeWriterService::MODEL_CATALOG");
        }

        if (trim($this->apiKey) === '') {
            throw new LlmUnavailable('Chưa cấu hình khoá API Claude — chưa gửi yêu cầu nào.');
        }

        $total = $request->timeoutSeconds ?? self::DEFAULT_TIMEOUT_SECONDS;
        $deadline = microtime(true) + $total;

        if (function_exists('set_time_limit')) {
            @set_time_limit($total + 60);
        }

        $run = new ClaudeStreamRun($request->onProgress, $this->progressEverySeconds, $total, $this->idleTimeoutSeconds, $this->thinking);

        if ($run->emit(true) === false) {
            throw $this->notSent($run, $modelType);
        }

        if ($this->limiter === null) {
            return $this->call($request, $run, $deadline, $modelType);
        }

        if (! $this->limiter->acquireSlot($deadline)) {
            $run->usageComplete = true;

            throw $this->fail($run, self::ENDED_TOTAL_TIMEOUT, "Hết {$total} s khi còn chờ lượt trong bộ giới hạn gọi Claude — chưa gửi yêu cầu nào.", $modelType);
        }

        try {
            if ($run->emit(true) === false) {
                throw $this->notSent($run, $modelType);
            }

            return $this->call($request, $run, $deadline, $modelType);
        } finally {
            $this->limiter->releaseSlot();
        }
    }

    private function notSent(ClaudeStreamRun $run, string $modelType): LlmUnavailable
    {
        $run->usageComplete = true;

        return $this->fail($run, self::ENDED_CANCELLED, 'Lượt viết không còn giữ quyền ghi (đã reset hoặc bị giành) — dừng trước khi gửi, chưa gọi AI.', $modelType);
    }

    private function call(LlmRequest $request, ClaudeStreamRun $run, float $deadline, string $modelType): LlmResponse
    {
        $beforeRetry = function () use ($run, $deadline): ?string {
            if ($run->emit(true) === false) {
                return AnthropicTransportError::CANCELLED;
            }

            if ($this->limiter === null) {
                return null;
            }

            $minuteKey = $this->limiter->acquireRequestSlot($deadline);

            if ($minuteKey === null) {
                return AnthropicTransportError::OUT_OF_TIME;
            }

            if ($run->emit(true) === false) {
                $this->limiter->releaseRequestSlot($minuteKey);

                return AnthropicTransportError::CANCELLED;
            }

            return null;
        };

        try {
            [$response, $run->attempts] = $this->transport->send($this->body($request), true, $deadline, $this->idleTimeoutSeconds, max(1, $request->maxAttempts ?? 1), $this->retrySleepMs, $beforeRetry);
        } catch (AnthropicTransportError $e) {
            $run->attempts = $e->attempts;
            $run->httpStatus = $e->status;
            $run->requestId = $e->requestId;
            $sent = $e->status === null ? '' : " (đã gửi {$e->attempts} lần, lần cuối HTTP {$e->status})";

            if (in_array($e->kind, [AnthropicTransportError::CANCELLED, AnthropicTransportError::OUT_OF_TIME], true)) {
                $run->usageComplete = $e->attempts === 0 || $e->status !== null;
            }

            throw match ($e->kind) {
                AnthropicTransportError::HTTP => $this->rejected($run, $e, $modelType),
                AnthropicTransportError::CANCELLED => $this->fail($run, self::ENDED_CANCELLED, "Lượt viết không còn giữ quyền ghi (đã reset hoặc bị giành) — dừng trước khi gửi lại{$sent}.", $modelType, $e),
                AnthropicTransportError::OUT_OF_TIME => $this->fail($run, self::ENDED_TOTAL_TIMEOUT, "Hết {$run->totalSeconds} s trước khi gửi được yêu cầu{$sent}.", $modelType, $e),
                default => $this->fail($run, self::ENDED_CONNECT_ERROR, sprintf(
                    'Không nhận được header từ Anthropic sau %.0f s: %s — chưa xác định máy chủ đã nhận và xử lý yêu cầu hay chưa; có thể vẫn phát sinh phí, đối chiếu console Anthropic.',
                    $e->waitedSeconds,
                    $e->getMessage(),
                ), $modelType, $e),
            };
        }

        $run->requestId = $response->header('request-id') ?: null;
        $run->httpStatus = $response->status();
        $stream = null;

        try {
            $stream = AnthropicTransport::events($response, $deadline, $this->idleTimeoutSeconds);

            foreach ($stream->events() as $payload) {
                if (($error = $run->apply($payload)) !== null) {
                    throw $this->fail($run, self::ENDED_STREAM_ERROR, sprintf(
                        'Anthropic báo lỗi giữa stream (%s: %s) %s.',
                        (string) ($error['type'] ?? 'unknown'),
                        (string) ($error['message'] ?? ''),
                        $run->describe(),
                    ), $modelType);
                }

                if ($run->ended === self::ENDED_MESSAGE_STOP) {
                    break;
                }

                if ($run->emit() === false) {
                    throw $this->fail($run, self::ENDED_CANCELLED, 'Lượt viết bị dừng giữa chừng vì không còn giữ quyền ghi (đã reset hoặc bị giành) '.$run->describe().'.', $modelType);
                }
            }
        } catch (AnthropicStreamTimeout $e) {
            throw $e->kind === AnthropicStreamTimeout::IDLE
                ? $this->fail($run, self::ENDED_IDLE_TIMEOUT, "Stream im lặng quá {$this->idleTimeoutSeconds} s {$run->describe()} — đã ngắt kết nối.", $modelType, $e)
                : $this->fail($run, self::ENDED_TOTAL_TIMEOUT, "Hết {$run->totalSeconds} s mà Claude chưa viết xong {$run->describe()} — đã ngắt kết nối.", $modelType, $e);
        } catch (LlmUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw $this->fail($run, self::ENDED_STREAM_ERROR, "Lỗi khi đọc stream ({$e->getMessage()}) {$run->describe()} — đã ngắt kết nối.", $modelType, $e);
        } finally {
            $stream?->close();
        }

        if ($run->ended !== self::ENDED_MESSAGE_STOP) {
            throw $this->fail($run, self::ENDED_STREAM_CUT, 'Stream đóng trước message_stop '.$run->describe().' — phản hồi không hoàn chỉnh.', $modelType);
        }

        return $this->finish($run, $request, $modelType);
    }

    /** @return array<string, mixed> */
    private function body(LlmRequest $request): array
    {
        $body = [
            'model' => ClaudeWriterService::modelId($request->model),
            'max_tokens' => $request->maxTokens,
            'messages' => [['role' => 'user', 'content' => $request->input]],
            'thinking' => $this->thinking,
            'stream' => true,
        ];

        if ($request->instruction !== '') {
            $body['system'] = $request->instruction;
        }

        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }

        if ($request->effort !== null) {
            $body['output_config'] = ['effort' => $request->effort];
        }

        return $body;
    }

    private function rejected(ClaudeStreamRun $run, AnthropicTransportError $e, string $modelType): LlmUnavailable
    {
        $run->usageComplete = true;

        return $this->fail($run, self::ENDED_HTTP_ERROR, sprintf(
            'Anthropic từ chối yêu cầu [HTTP %d%s] sau %d lần gửi: %s — máy chủ trả lỗi trước khi sinh nội dung.',
            (int) $e->status,
            $e->errorType !== null ? ' '.$e->errorType : '',
            $e->attempts,
            $e->providerMessage ?? $e->getMessage(),
        ), $modelType, $e);
    }

    private function finish(ClaudeStreamRun $run, LlmRequest $request, string $modelType): LlmResponse
    {
        $billed = $this->response($run, $modelType);

        if ($run->stopReason === 'max_tokens') {
            throw $this->fail($run, self::ENDED_MESSAGE_STOP, sprintf(
                'Claude bị CẮT ở trần %d token output (đã sinh %d, trong đó %s là thinking) nên kết quả không hoàn chỉnh — ĐÃ TỐN PHÍ cú gọi này. Bấm lại nguyên trạng sẽ hỏng y hệt và mất thêm tiền.',
                $request->maxTokens,
                $billed->tokensOut,
                $billed->thinkingTokens === null ? 'không rõ' : (string) $billed->thinkingTokens,
            ), $modelType, billed: $billed);
        }

        if ($run->stopReason === 'refusal') {
            throw $this->fail($run, self::ENDED_MESSAGE_STOP, 'Claude từ chối viết (stop_reason refusal) — đã tính phí cú gọi này.', $modelType, billed: $billed);
        }

        if (! in_array($run->stopReason, ['end_turn', 'stop_sequence'], true)) {
            throw $this->fail($run, self::ENDED_MESSAGE_STOP, 'Claude dừng với stop_reason '.($run->stopReason ?? 'không rõ').' — kết quả không dùng, đã tính phí cú gọi này.', $modelType, billed: $billed);
        }

        if (trim($run->text) === '') {
            throw $this->fail($run, self::ENDED_MESSAGE_STOP, 'Claude kết thúc ('.$run->stopReason.') nhưng không có phần chữ — đã tính phí cú gọi này.', $modelType, billed: $billed);
        }

        $run->emit(true);

        return $billed;
    }

    private function response(ClaudeStreamRun $run, string $modelType): LlmResponse
    {
        $input = (int) ($run->usage['input_tokens'] ?? 0);
        $cacheWrite = (int) ($run->usage['cache_creation_input_tokens'] ?? 0);
        $cacheRead = (int) ($run->usage['cache_read_input_tokens'] ?? 0);
        $output = (int) ($run->usage['output_tokens'] ?? 0);
        $thinking = $run->usage['output_tokens_details']['thinking_tokens'] ?? null;

        return new LlmResponse(
            $run->text,
            $modelType,
            $input + $cacheWrite + $cacheRead,
            $output,
            $run->elapsedMs(),
            ClaudeWriterService::costUsd($input, $output, $modelType, $cacheWrite, $cacheRead),
            $run->text,
            $run->providerModel,
            is_int($thinking) && $thinking >= 0 ? $thinking : null,
            $run->usageComplete,
        );
    }

    private function fail(ClaudeStreamRun $run, string $ended, string $message, string $modelType, ?\Throwable $previous = null, ?LlmResponse $billed = null): LlmUnavailable
    {
        if ($billed === null && ($run->usage !== [] || $run->text !== '')) {
            $billed = $this->response($run, $modelType);
        }

        if ($billed !== null && ! $billed->usageComplete) {
            $message .= sprintf(
                ' Chưa nhận usage cuối: đã biết %d token vào và %d token ra — chi phí ghi nhận $%s chỉ là mức tối thiểu, phí thực tế có thể cao hơn.',
                $billed->tokensIn,
                $billed->tokensOut,
                number_format($billed->costUsd, 4, '.', ''),
            );
        }

        $run->ended = $ended;
        $run->error = $message;
        $run->emit(true);

        return new LlmUnavailable($message, $billed, $previous);
    }
}
