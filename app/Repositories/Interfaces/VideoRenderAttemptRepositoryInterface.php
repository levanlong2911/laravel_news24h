<?php

namespace App\Repositories\Interfaces;

use App\Models\VideoRenderAttempt;
use DateTimeImmutable;

interface VideoRenderAttemptRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes  every attribute is fillable on this model
     * @return VideoRenderAttempt the new attempt with its generated id
     */
    public function createAttempt(array $attributes): VideoRenderAttempt;

    /**
     * @param  string  $renderId
     * @param  int  $attemptNo  the attempt count of the render
     * @return ?VideoRenderAttempt the current attempt of the render locked FOR UPDATE; call inside the caller's transaction
     */
    public function lockCurrentAttempt(string $renderId, int $attemptNo): ?VideoRenderAttempt;

    /**
     * @param  string  $renderId
     * @param  int  $attemptNo  the attempt count of the render
     * @return VideoRenderAttempt the current attempt of the render locked FOR UPDATE; call inside the caller's transaction
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function lockCurrentAttemptOrFail(string $renderId, int $attemptNo): VideoRenderAttempt;

    /**
     * @param  string  $renderId
     * @param  int  $attemptNo  the attempt count of the render
     * @return ?VideoRenderAttempt the current attempt of the render, not locked
     */
    public function findCurrentAttempt(string $renderId, int $attemptNo): ?VideoRenderAttempt;

    /**
     * @param  string  $renderId
     * @param  int  $attemptNo
     * @param  string  $claimToken
     * @param  int  $claimGeneration
     * @return ?VideoRenderAttempt the attempt of the render made under this claim and generation
     */
    public function findAttemptForClaim(string $renderId, int $attemptNo, string $claimToken, int $claimGeneration): ?VideoRenderAttempt;

    /**
     * @param  string  $renderId
     * @param  int  $attemptNo
     * @return bool whether the render has an attempt numbered after $attemptNo
     */
    public function hasNewerAttempt(string $renderId, int $attemptNo): bool;

    /**
     * @param  VideoRenderAttempt  $attempt  the attempt the caller holds, usually locked; it is written in place
     * @param  array<string, mixed>  $attributes  force filled, cast by the model
     * @return void
     */
    public function saveAttempt(VideoRenderAttempt $attempt, array $attributes): void;

    /**
     * @param  string  $renderId
     * @param  int  $attemptNo
     * @param  string  $providerJobId
     * @param  ?string  $providerRequestId
     * @param  array<string, mixed>  $submitResponse
     * @param  DateTimeImmutable  $submittedAt
     * @return int the rows of that attempt marked submitted, the row not locked
     */
    public function markCurrentAttemptSubmitted(string $renderId, int $attemptNo, string $providerJobId, ?string $providerRequestId, array $submitResponse, DateTimeImmutable $submittedAt): int;

    /**
     * @param  string  $renderId
     * @param  int  $attemptNo
     * @param  string  $claimToken
     * @param  string  $providerJobId
     * @param  string  $submitResponseJson
     * @return int the rows of the attempt of this claim that had no job yet and now record it
     */
    public function recordJobOnOwnAttemptIfMissing(string $renderId, int $attemptNo, string $claimToken, string $providerJobId, string $submitResponseJson): int;

    /**
     * @param  string  $renderId
     * @param  int  $attemptNo
     * @param  string  $errorCode
     * @param  string  $errorMessage
     * @return int the rows of that attempt still open that are now abandoned and completed now
     */
    public function abandonOpenAttempt(string $renderId, int $attemptNo, string $errorCode, string $errorMessage): int;
}
