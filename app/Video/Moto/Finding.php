<?php

declare(strict_types=1);

namespace App\Video\Moto;

use JsonSerializable;
use Stringable;

/**
 * One finding of a moto checker: the decision the code made where it found it, a stable code for the
 * rule, and the message shown to a person. The decision is set by the code at the site of the rule and
 * never read back from the message, so what an answer writes into a message (an id, a quote) can never
 * change how strongly the finding holds the answer back.
 *
 * It prints and encodes as its message, so lists that carry findings read and store exactly as the
 * lists of messages they were.
 */
final class Finding implements JsonSerializable, Stringable
{
    private function __construct(public readonly string $kind, public readonly string $code, public readonly string $message) {}

    /** The answer belongs to another run, project or request: a RULE failure. */
    public static function blocking(string $code, string|Stringable $message): self
    {
        return new self(MotoCheckRules::RULE, $code, (string) $message);
    }

    /** An id the next step looks up, or a condition the code that derives, plans or renders from the answer needs. */
    public static function requirement(string $code, string|Stringable $message): self
    {
        return new self(MotoCheckRules::USE, $code, (string) $message);
    }

    /** A judgement of the content: shown, never a failure. */
    public static function note(string $code, string|Stringable $message): self
    {
        return new self(MotoCheckRules::NOTE, $code, (string) $message);
    }

    /**
     * @param  list<string|Stringable>  $messages  the messages of a helper that returns them as text
     * @return list<self>
     */
    public static function each(string $kind, string $code, array $messages): array
    {
        return array_values(array_map(static fn (string|Stringable $message): self => $message instanceof self ? $message : new self($kind, $code, (string) $message), $messages));
    }

    /**
     * @param  list<mixed>  $issues  the findings of a check, some of them still plain messages
     * @return array{0: list<string>, 1: list<string>, 2: list<string>, 3: list<string>} the messages, the decision of each, its rule code ('' for none), and the messages no rule site decided (held back as RULE failures)
     */
    public static function split(array $issues): array
    {
        $messages = [];
        $kinds = [];
        $codes = [];
        $unclassified = [];

        foreach (array_values($issues) as $issue) {
            $messages[] = (string) $issue;
            $kinds[] = $issue instanceof self ? $issue->kind : MotoCheckRules::RULE;
            $codes[] = $issue instanceof self ? $issue->code : '';

            if (! $issue instanceof self) {
                $unclassified[] = (string) $issue;
            }
        }

        return [$messages, $kinds, $codes, $unclassified];
    }

    /**
     * The fields a checker returns for its findings: the messages, as the lists of text they always were,
     * with the decision of each beside them, so a decision is never read back from a message.
     *
     * @param  list<mixed>  $issues
     * @param  ?list<mixed>  $warnings  null for a check that returns no warnings
     * @param  bool  $unique  drop a message met twice, as the checker always did
     * @return array{issues: list<string>, issue_kinds: list<string>, issue_codes: list<string>, unclassified: list<string>, warnings?: list<string>}
     */
    public static function fields(array $issues, ?array $warnings = [], bool $unique = true): array
    {
        if ($unique) {
            $kept = [];

            foreach ($issues as $issue) {
                $kept[(string) $issue] ??= $issue;
            }

            $issues = array_values($kept);
        }

        [$messages, $kinds, $codes, $unclassified] = self::split($issues);
        $texts = $warnings === null ? null : array_map('strval', $warnings);

        return ['issues' => $messages, 'issue_kinds' => $kinds, 'issue_codes' => $codes, 'unclassified' => $unclassified]
            + ($texts === null ? [] : ['warnings' => array_values($unique ? array_unique($texts) : $texts)]);
    }

    /** The same finding, its message under a label (a cell, an entry): the decision and the code never change with the text. */
    public function prefixed(string $prefix): self
    {
        return new self($this->kind, $this->code, $prefix.$this->message);
    }

    /** The same finding told in other words for where it is shown: the decision and the code never change with the text. */
    public function retold(string $message): self
    {
        return new self($this->kind, $this->code, $message);
    }

    public function __toString(): string
    {
        return $this->message;
    }

    public function jsonSerialize(): string
    {
        return $this->message;
    }
}
