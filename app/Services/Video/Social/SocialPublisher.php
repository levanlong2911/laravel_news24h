<?php

declare(strict_types=1);

namespace App\Services\Video\Social;

use App\Video\Social\SocialPlatformError;
use Carbon\CarbonInterface;

/**
 * One platform, as the posting flow sees it: it turns a package into the fields the platform takes and makes each
 * request of a posting, one step at a time, so what the platform gave back can be stored before the next step.
 *
 * `$account` is what a request is signed with — a token and the id of the page or channel — read from the connection
 * when a step runs. It is never stored on a posting, never logged and never part of a payload or a hash.
 * `$session` is what the platform gave to follow one posting by; it is stored encrypted after every step.
 *
 * Every method that talks to the platform throws SocialPlatformError, which says what kind of refusal it was and
 * whether the request may have gone through anyway.
 */
interface SocialPublisher
{
    public function platform(): string;

    /** @return string the version of this adapter: with the fields it sends it makes the payload hash, and the platform policy names the one a format is written for */
    public function adapter(): string;

    /**
     * @param  array<string, mixed>  $document  the package document
     * @param  array<string, mixed>  $rules  the rules of its format
     * @return array<string, mixed> the fields that are sent, exactly; with the adapter version they make the payload hash
     */
    public function payload(array $document, array $rules, ?CarbonInterface $scheduledAt): array;

    /**
     * @param  list<string>  $scopes  what the connection was granted
     * @param  string  $action  post: a posting; cancel: taking back the schedule of one
     * @return list<string> what the connection lacks for the action, known without asking the platform
     */
    public function missingScopes(array $scopes, string $action = 'post'): array;

    /**
     * Asks the platform to drop the schedule of a video it holds, then reads the video back: only what the platform reads
     * back counts. Nothing is deleted.
     *
     * @param  array{access_token: string, external_id: string}  $account
     * @param  \Closure(): void  $guard  called right before every request; it throws when the caller no longer holds the posting, and no request is made after that
     * @return array{state: string, detail: ?string} state is cancelled (read back with no schedule left), scheduled (the platform still holds one), published (it went out already), unknown (the platform did not say), or unsupported (this adapter knows no documented request for it, and sent nothing)
     *
     * @throws SocialPlatformError
     */
    public function unschedule(array $account, string $externalId, \Closure $guard): array;

    /**
     * Opens an upload.
     *
     * @param  array{access_token: string, external_id: string}  $account
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> the session: what to follow the posting by, with `external_id` as soon as the platform names the video
     *
     * @throws SocialPlatformError
     */
    public function open(array $account, array $payload, int $bytes): array;

    /**
     * Sends the bytes of the film.
     *
     * @param  array{access_token: string, external_id: string}  $account
     * @param  array<string, mixed>  $session
     * @return array<string, mixed> the session after the upload
     *
     * @throws SocialPlatformError
     */
    public function send(array $account, array $session, string $file, int $bytes): array;

    /**
     * Whatever the platform needs after the bytes to post or schedule the video; nothing for a platform that posts on upload.
     *
     * @param  array{access_token: string, external_id: string}  $account
     * @param  array<string, mixed>  $session
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> the session after the call
     *
     * @throws SocialPlatformError
     */
    public function finish(array $account, array $session, array $payload): array;

    /**
     * Where the posting stands on the platform, asked with what the session holds. Sends nothing new.
     *
     * @param  array{access_token: string, external_id: string}  $account
     * @param  array<string, mixed>  $session
     * @param  array<string, mixed>  $payload
     * @return array{state: string, external_id: ?string, url: ?string, at: ?string, detail: ?string} state is upload_incomplete (the platform says the bytes never arrived whole), uploaded (the bytes arrived; nothing after them has started), processing, scheduled, published, failed, or unknown (the platform cannot say); at is when it was or will be published, in UTC
     *
     * @throws SocialPlatformError
     */
    public function state(array $account, array $session, array $payload, int $bytes): array;

    /**
     * Sets the cover of a posted video.
     *
     * @param  array{access_token: string, external_id: string}  $account
     *
     * @throws SocialPlatformError
     */
    public function cover(array $account, string $externalId, string $file): void;

    /**
     * @param  array{access_token: string, external_id: string}  $account
     * @param  list<array{id: string, shape: string}>  $items  the numbers the platform policy lists for the format
     * @return array<string, int|float|null> every number by its name, null for one the platform did not give; a number given per key comes as name.key
     *
     * @throws SocialPlatformError
     */
    public function metrics(array $account, string $externalId, array $items, CarbonInterface $from, CarbonInterface $to): array;
}
