<?php

declare(strict_types=1);

namespace App\Video\Social;

use App\Video\Superyacht\Support\CanonicalJson;

/**
 * The package of Phase 12 as a document: what is approved, exported and posted for one channel, one format and one
 * language. Code assembles it from the fields a person edits; the text a posting carries is `rendered`, put together
 * here and nowhere else, so what a person reads before approving is what is sent.
 *
 * How the answer of a model becomes those fields is here too (fromPlan), and it is the whole of the mapping:
 *
 *   selected_hook_id → hook_id      copy.title → title        copy.opening → opening     copy.body → body
 *   copy.cta → cta                  copy.hashtags → hashtags  copy.keywords → keywords
 *
 * A null call to action leaves the one the package had. strategy, hook_options, claims, artwork_options and
 * review_notes are kept beside the fields for a person to read and are no part of what is posted.
 *
 * The link, the disclosure sentence, the platform's synthetic-content choice, the visibility and the made-for-kids
 * declaration never come from a model: they start from the channel profile and the platform policy and a person sets them.
 */
final class SocialPackageDocument
{
    public const FORMAT = 'social-package-v1';

    public const SCHEMA = 'SocialPackage@1.0.0';

    /** @var list<string> the fields a person edits; together with the cover they are everything a package says */
    public const FIELDS = ['hook_id', 'title', 'opening', 'body', 'cta', 'link', 'hashtags', 'keywords', 'disclosure_text', 'synthetic_media', 'privacy', 'made_for_kids'];

    /**
     * @param  array<string, mixed>  $channel  the snapshot of the channel profile
     * @param  array<string, mixed>  $rules  the rules of the format
     * @return array<string, mixed> the fields of a package nobody has written yet: empty text, and the choices of the channel profile and the platform policy
     */
    public static function blank(array $channel, array $rules, string $language): array
    {
        $metadata = (array) $rules['metadata'];

        return [
            'hook_id' => null,
            'title' => null,
            'opening' => null,
            'body' => null,
            'cta' => self::text($channel['default_cta'] ?? null),
            'link' => self::text($channel['default_link'] ?? null),
            'hashtags' => [],
            'keywords' => [],
            'disclosure_text' => self::text($channel['disclosure']['texts'][$language] ?? null),
            'synthetic_media' => (bool) ($channel['disclosure']['synthetic_media'] ?? false),
            'privacy' => $metadata['privacy'] === [] ? null : ($metadata['default_privacy'] ?? null),
            'made_for_kids' => $metadata['made_for_kids'] ? false : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan  a SocialPublishingPlan that passed its check
     * @param  array<string, mixed>  $fields  the fields of the revision the plan was written for
     * @return array<string, mixed> those fields with the copy of the plan in place of their text; what a model never writes stays as it was
     */
    public static function fromPlan(array $plan, array $fields): array
    {
        $copy = (array) $plan['copy'];

        return array_replace($fields, [
            'hook_id' => (string) $plan['selected_hook_id'],
            'title' => self::text($copy['title'] ?? null),
            'opening' => self::text($copy['opening'] ?? null),
            'body' => self::text($copy['body'] ?? null),
            'cta' => self::text($copy['cta'] ?? null) ?? $fields['cta'],
            'hashtags' => self::hashtags((array) ($copy['hashtags'] ?? [])),
            'keywords' => self::keywords((array) ($copy['keywords'] ?? [])),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input  what a person sent: any of FIELDS; a list may come as one text
     * @param  array<string, mixed>  $fields  the fields of the revision being edited
     * @return array<string, mixed> the fields after the edit: a field not sent stays, an empty text is null, a list is cleaned of blanks and repeats
     */
    public static function edited(array $input, array $fields): array
    {
        foreach (['title', 'opening', 'body', 'cta', 'link', 'disclosure_text'] as $key) {
            if (array_key_exists($key, $input)) {
                $fields[$key] = self::text($input[$key]);
            }
        }

        if (array_key_exists('hook_id', $input)) {
            $fields['hook_id'] = is_string($input['hook_id']) && preg_match('/^H[1-9]$/', $input['hook_id']) === 1 ? $input['hook_id'] : null;
        }

        if (array_key_exists('hashtags', $input)) {
            $fields['hashtags'] = self::hashtags(is_array($input['hashtags']) ? $input['hashtags'] : (preg_split('/[\s,]+/u', (string) $input['hashtags']) ?: []));
        }

        if (array_key_exists('keywords', $input)) {
            $fields['keywords'] = self::keywords(is_array($input['keywords']) ? $input['keywords'] : (preg_split('/[,\r\n]+/u', (string) $input['keywords']) ?: []));
        }

        if (array_key_exists('synthetic_media', $input)) {
            $fields['synthetic_media'] = filter_var($input['synthetic_media'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('privacy', $input)) {
            $fields['privacy'] = self::text($input['privacy']);
        }

        if (array_key_exists('made_for_kids', $input) && $fields['made_for_kids'] !== null) {
            $fields['made_for_kids'] = filter_var($input['made_for_kids'], FILTER_VALIDATE_BOOLEAN);
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array{title: ?string, text: string, tags: list<string>} the text as it is posted: the opening, the body, the call to action with its link under it, the disclosure sentence and the hashtags, each a paragraph of its own, in that order; a part that is not there leaves no gap
     */
    public static function render(array $fields): array
    {
        $action = implode("\n", array_values(array_filter([$fields['cta'] ?? null, $fields['link'] ?? null], 'is_string')));
        $tags = implode(' ', array_map(static fn (string $tag): string => '#'.$tag, (array) ($fields['hashtags'] ?? [])));
        $parts = array_values(array_filter([$fields['opening'] ?? null, $fields['body'] ?? null, $action, $fields['disclosure_text'] ?? null, $tags], static fn (mixed $part): bool => is_string($part) && $part !== ''));

        return ['title' => $fields['title'] ?? null, 'text' => implode("\n\n", $parts), 'tags' => array_values((array) ($fields['keywords'] ?? []))];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return int how many characters code adds around the text a model writes: the link, the disclosure sentence, and the breaks that join them
     */
    public static function reserved(array $fields): int
    {
        return mb_strlen(self::render(array_replace($fields, ['opening' => null, 'body' => null, 'cta' => null, 'hashtags' => []]))['text']) + 8;
    }

    /**
     * @param  array{channel_id: string, platform: string, format: string, language: string, external_id: ?string, channel_hash: string}  $target
     * @param  array{hash: string, final_id: string, artifact_sha256: string, decision_id: string}  $source
     * @param  array{phase_id: string, phase_revision: int, policy_hash: string}  $policy
     * @param  array<string, mixed>  $fields
     * @param  ?array{artifact_id: string, sha256: string, width: int, height: int, recipe_hash: string}  $artwork
     * @return array<string, mixed> the package document; its hash is the package hash
     */
    public static function assemble(string $projectId, array $target, array $source, array $policy, array $fields, ?array $artwork): array
    {
        return [
            'format' => self::FORMAT,
            'schema' => self::SCHEMA,
            'project_id' => $projectId,
            'target' => array_intersect_key($target, array_flip(['channel_id', 'platform', 'format', 'language', 'external_id', 'channel_hash'])),
            'source' => array_intersect_key($source, array_flip(['hash', 'final_id', 'artifact_sha256', 'decision_id'])),
            'policy' => array_intersect_key($policy, array_flip(['phase_id', 'phase_revision', 'policy_hash'])),
            'content' => array_intersect_key($fields, array_flip(['hook_id', 'title', 'opening', 'body', 'cta', 'link', 'hashtags', 'keywords', 'disclosure_text'])),
            'rendered' => self::render($fields),
            'disclosure' => ['synthetic_media' => (bool) $fields['synthetic_media'], 'in_text' => is_string($fields['disclosure_text'] ?? null)],
            'artwork' => $artwork === null ? null : array_intersect_key($artwork, array_flip(['artifact_id', 'sha256', 'width', 'height', 'recipe_hash'])),
            'metadata' => ['privacy' => $fields['privacy'] ?? null, 'made_for_kids' => $fields['made_for_kids'] ?? null],
        ];
    }

    /** @param array<string, mixed> $document */
    public static function hash(array $document): string
    {
        return CanonicalJson::hash($document);
    }

    /**
     * @param  array<string, mixed>  $document  a package document
     * @return array<string, mixed> the fields a person edits, read back from the document
     */
    public static function fields(array $document): array
    {
        return (array) $document['content'] + [
            'synthetic_media' => (bool) $document['disclosure']['synthetic_media'],
            'privacy' => $document['metadata']['privacy'] ?? null,
            'made_for_kids' => $document['metadata']['made_for_kids'] ?? null,
        ];
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return list<string> without the # sign, without blanks and repeats, in the order given
     */
    private static function hashtags(array $values): array
    {
        $tags = [];

        foreach ($values as $value) {
            $tag = is_string($value) ? ltrim(trim($value), '#') : '';

            if ($tag !== '' && preg_match('/[\s#]/u', $tag) !== 1 && ! in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return list<string> trimmed, without blanks and repeats, in the order given
     */
    private static function keywords(array $values): array
    {
        $words = [];

        foreach ($values as $value) {
            $word = is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';

            if ($word !== '' && ! in_array($word, $words, true)) {
                $words[] = $word;
            }
        }

        return $words;
    }
}
