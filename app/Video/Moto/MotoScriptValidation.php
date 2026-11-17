<?php

declare(strict_types=1);

namespace App\Video\Moto;

use RuntimeException;

/**
 * The validation policy a moto script run is checked by, chosen by the server when the run is made
 * and pinned in its context: never read from a request, and never the newest policy again when the
 * run is checked later, so a queued run, a re-check and an approval all see the rules it was made
 * under. A run that pinned none was made before policies were versioned and keeps the legacy rules.
 *
 * The phases that read an approved script (locations, scenes, shots) take the policy of that script,
 * so one chain never mixes two policies. The policy is apart from the release of the project: a
 * project keeps its release and its pinned design while a new run or a hand revision of its script is
 * checked by the policy current when it is made.
 */
final class MotoScriptValidation
{
    public const KEY = 'validation';

    /** @var string the rules before versioning: a support belongs to the temporary items of the beat's own build state, and the refs of a beat are taken as written */
    public const LEGACY = 'moto-validation-v1';

    /** @var string a support is any temporary item the design lists, and the refs of a beat are the union code projects from its events and its in_use */
    public const VERSION = 'moto-validation-v2';

    public const SUPPORT_BY_STATE = 'build_state';

    public const SUPPORT_BY_DESIGN = 'design';

    /** @var array<string, array{support: string, references: ?string}> every policy a run may pin */
    private const REGISTERED = [
        self::LEGACY => ['support' => self::SUPPORT_BY_STATE, 'references' => null],
        self::VERSION => ['support' => self::SUPPORT_BY_DESIGN, 'references' => MotoScriptReferences::VERSION],
    ];

    /** @return array{version: string, support: string, references: ?string} the policy a run made now pins */
    public static function current(): array
    {
        return ['version' => self::VERSION] + self::REGISTERED[self::VERSION];
    }

    /**
     * @param  array<string, mixed>  $context  the context a run pinned
     * @return array{version: string, support: string, references: ?string} the registered policy the run pinned, the legacy one when it pinned none; the rules come from this registry, never from what the context says beside the version
     *
     * @throws RuntimeException when the run pinned a version this code does not know
     */
    public static function of(array $context): array
    {
        $pinned = $context[self::KEY] ?? null;

        if ($pinned === null) {
            return ['version' => self::LEGACY] + self::REGISTERED[self::LEGACY];
        }

        $version = is_array($pinned) ? ($pinned['version'] ?? null) : null;

        if (! is_string($version) || ! isset(self::REGISTERED[$version])) {
            throw new RuntimeException('Lượt chạy ghim chính sách kiểm '.json_encode($version).' không có trong các phiên bản đã đăng ký.');
        }

        return ['version' => $version] + self::REGISTERED[$version];
    }

    /**
     * @param  array<string, mixed>  $context  the context of a run being made
     * @return array{validation_version?: string, references_version?: ?string} what the run adds to its input when it pins a policy, so the cache never serves a run checked by other rules for it and a queued run is refused when the policy moved; nothing for a run that pins none, so the input of every older run stays as it was
     */
    public static function input(array $context): array
    {
        if (! isset($context[self::KEY])) {
            return [];
        }

        $validation = self::of($context);

        return ['validation_version' => $validation['version'], 'references_version' => $validation['references']];
    }

    /** @param  array<string, mixed>  $context */
    public static function supportsByDesign(array $context): bool
    {
        return self::of($context)['support'] === self::SUPPORT_BY_DESIGN;
    }

    /** @param  array<string, mixed>  $context */
    public static function projectsReferences(array $context): bool
    {
        return self::of($context)['references'] !== null;
    }
}
