<?php

declare(strict_types=1);

namespace App\Video\Social;

use RuntimeException;

/**
 * What a package of Phase 12 is written from, for the projects of one workflow: the approved film and everything that
 * very film was made from. Each workflow has its own reader; nothing above it knows how a film of that workflow pins
 * its script or its design.
 */
interface SocialSource
{
    public const FORMAT = 'social-source-v1';

    /**
     * The approved film of the project with its file hashed again now, and the sources that film pins — never the
     * script or the design the project selects today.
     *
     * @return array{
     *     pins: array<string, mixed>,
     *     film: array{final_id: string, revision: int, path: string, sha256: string, decision_id: string, approved_at: ?string, approved_by: ?string, purpose: ?string, shots: int},
     *     facts: array<string, mixed>,
     *     refs: list<array{ref: string, what: string}>,
     *     assets: list<array<string, mixed>>,
     *     missing: list<string>
     * } pins is every id and hash the film rests on, and nothing that changes without the film changing: its hash is the
     *   source hash. facts is what a writer reads; refs every source a claim may cite; assets every picture a cover may be
     *   made from; missing the sources this film could have had and does not, each said plainly
     *
     * @throws RuntimeException when there is no approved film, its file is gone or changed, or a source it pins cannot be read as pinned — said by name, and never replaced by what the project holds now
     */
    public function read(string $projectId): array;

    /**
     * Whether the film the pins name is still the one the project hands on, read with a lock on what selects it, so a
     * change of the selection waits for the transaction this runs in, or is seen by it. No file is read: the caller
     * hashed the file before its transaction; this proves only that the selection did not move since.
     *
     * @param  array<string, mixed>  $pins  the pins of read()
     * @return ?string why the film the pins name is no longer the approved one; null when it still is
     */
    public function selected(string $projectId, array $pins): ?string;
}
