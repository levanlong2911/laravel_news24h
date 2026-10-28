<?php

declare(strict_types=1);

namespace App\Video\Llm;

use App\Services\Admin\ClaudeWriterService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Http\Message\StreamInterface;
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

    /**
     * @param  array<string, mixed>  $thinking  sent as the request's `thinking`; display "summarized" keeps bytes flowing while the model thinks
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $apiVersion,
        private readonly int $idleTimeoutSeconds,
        private readonly int $progressEverySeconds,
        private readonly array $thinking,
    ) {
        if ($this->idleTimeoutSeconds < 1) {
            throw new RuntimeException('Claude stream idle timeout must be >= 1 second.');
        }

        if ($this->progressEverySeconds < 0) {
            throw new RuntimeException('Claude stream progress interval must be >= 0 seconds.');
        }
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

        if (function_exists('set_time_limit')) {
            @set_time_limit($total + $this->idleTimeoutSeconds + 30);
        }

        $run = new ClaudeStreamRun($request->onProgress, $this->progressEverySeconds, $total, $this->idleTimeoutSeconds);
        $run->emit(true);

        try {
            $response = $this->http
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => $this->apiVersion,
                    'content-type' => 'application/json',
                    'accept' => 'text/event-stream',
                ])
                ->timeout($this->idleTimeoutSeconds)
                ->withOptions(['stream' => true, 'read_timeout' => $this->idleTimeoutSeconds])
                ->post(rtrim($this->baseUrl, '/').'/v1/messages', $this->body($request));
        } catch (\Throwable $e) {
            throw $this->fail($run, self::ENDED_CONNECT_ERROR, sprintf(
                'Không nhận được phản hồi từ Anthropic (chờ tối đa %d s cho tới header): %s — yêu cầu có thể chưa tới máy chủ, không có token nào được ghi nhận.',
                $this->idleTimeoutSeconds,
                $e->getMessage(),
            ), $modelType, $e);
        }

        $run->requestId = $response->header('request-id') ?: null;
        $run->httpStatus = $response->status();

        if (! $response->successful()) {
            $error = is_array($response->json()) ? (array) ($response->json()['error'] ?? []) : [];

            throw $this->fail($run, self::ENDED_HTTP_ERROR, sprintf(
                'Anthropic từ chối yêu cầu [HTTP %d%s]: %s — model chưa sinh token nào.',
                $response->status(),
                isset($error['type']) ? ' '.$error['type'] : '',
                (string) ($error['message'] ?? mb_substr($response->body(), 0, 500)),
            ), $modelType);
        }

        $this->read($response->toPsrResponse()->getBody(), $run, $modelType);

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

    private function read(StreamInterface $stream, ClaudeStreamRun $run, string $modelType): void
    {
        $buffer = '';

        while (true) {
            if ($run->overDeadline()) {
                $stream->close();

                throw $this->fail($run, self::ENDED_TOTAL_TIMEOUT, sprintf(
                    'Hết %d s mà Claude chưa viết xong %s — đã ngắt kết nối.',
                    $run->totalSeconds,
                    $run->describe(),
                ), $modelType);
            }

            try {
                $chunk = $stream->read(8192);
            } catch (RuntimeException) {
                $chunk = '';
            }

            if ($chunk === '') {
                if ($stream->getMetadata('timed_out') === true) {
                    $stream->close();

                    throw $this->fail($run, self::ENDED_IDLE_TIMEOUT, sprintf(
                        'Stream im lặng quá %d s %s — đã ngắt kết nối.',
                        $this->idleTimeoutSeconds,
                        $run->describe(),
                    ), $modelType);
                }

                if ($stream->eof()) {
                    break;
                }

                usleep(50_000);

                continue;
            }

            $buffer .= str_replace("\r\n", "\n", $chunk);

            while (($end = strpos($buffer, "\n\n")) !== false) {
                $this->apply(substr($buffer, 0, $end), $run, $modelType);
                $buffer = substr($buffer, $end + 2);

                if ($run->ended === self::ENDED_MESSAGE_STOP) {
                    $stream->close();

                    return;
                }
            }

            if ($run->emit() === false) {
                $stream->close();

                throw $this->fail($run, self::ENDED_CANCELLED, 'Lượt viết bị dừng giữa chừng vì không còn giữ quyền ghi (đã reset hoặc bị giành) '.$run->describe().'.', $modelType);
            }
        }

        if (trim($buffer) !== '') {
            $this->apply($buffer, $run, $modelType);
        }

        if ($run->ended !== self::ENDED_MESSAGE_STOP) {
            throw $this->fail($run, self::ENDED_STREAM_CUT, 'Stream đóng trước message_stop '.$run->describe().' — phản hồi không hoàn chỉnh.', $modelType);
        }
    }

    private function apply(string $event, ClaudeStreamRun $run, string $modelType): void
    {
        $data = '';

        foreach (explode("\n", $event) as $line) {
            if (str_starts_with($line, 'data:')) {
                $data .= ltrim(substr($line, 5));
            }
        }

        $payload = $data === '' ? null : json_decode($data, true);

        if (! is_array($payload)) {
            return;
        }

        $run->events++;
        $run->lastEventAt = microtime(true);

        switch ($payload['type'] ?? null) {
            case 'message_start':
                $message = is_array($payload['message'] ?? null) ? $payload['message'] : [];
                $run->providerModel = (string) ($message['model'] ?? '');
                $run->usage = is_array($message['usage'] ?? null) ? $message['usage'] : [];
                break;

            case 'content_block_start':
                $type = $payload['content_block']['type'] ?? null;
                $run->phase = $type === 'text' ? ClaudeStreamRun::PHASE_WRITING : ($type === 'thinking' ? ClaudeStreamRun::PHASE_THINKING : $run->phase);
                break;

            case 'content_block_delta':
                $delta = is_array($payload['delta'] ?? null) ? $payload['delta'] : [];

                if (($delta['type'] ?? null) === 'text_delta') {
                    $run->appendText((string) ($delta['text'] ?? ''));
                } elseif (($delta['type'] ?? null) === 'thinking_delta') {
                    $run->thinkingChars += mb_strlen((string) ($delta['thinking'] ?? ''));
                }
                break;

            case 'message_delta':
                if (array_key_exists('stop_reason', (array) ($payload['delta'] ?? []))) {
                    $run->stopReason = is_string($payload['delta']['stop_reason']) ? $payload['delta']['stop_reason'] : null;
                }

                if (is_array($payload['usage'] ?? null)) {
                    $run->usage = array_replace($run->usage, $payload['usage']);
                }
                break;

            case 'message_stop':
                $run->ended = self::ENDED_MESSAGE_STOP;
                break;

            case 'error':
                throw $this->fail($run, self::ENDED_STREAM_ERROR, sprintf(
                    'Anthropic báo lỗi giữa stream (%s: %s) %s.',
                    (string) ($payload['error']['type'] ?? 'unknown'),
                    (string) ($payload['error']['message'] ?? ''),
                    $run->describe(),
                ), $modelType);
        }
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
        );
    }

    private function fail(ClaudeStreamRun $run, string $ended, string $message, string $modelType, ?\Throwable $previous = null, ?LlmResponse $billed = null): LlmUnavailable
    {
        $run->ended = $ended;
        $run->error = $message;
        $run->emit(true);

        if ($billed === null && ($run->usage !== [] || $run->text !== '')) {
            $billed = $this->response($run, $modelType);
        }

        return new LlmUnavailable($message, $billed, $previous);
    }
}
