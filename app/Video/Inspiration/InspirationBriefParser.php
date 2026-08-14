<?php

namespace App\Video\Inspiration;

final class InspirationBriefParser
{
    public function parse(string $text): InspirationBrief
    {
        $json = trim($text);
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $json, $match)) {
            $json = trim($match[1]);
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidInspirationBrief(['response is not valid JSON']);
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidInspirationBrief(['response root must be an object']);
        }

        $violations = [];
        $allowedRoot = ['article_patterns', 'article_focus', 'source_insights', 'excluded_context'];
        $this->rejectUnknownKeys($data, $allowedRoot, 'root', $violations);

        $patterns = $this->stringList($data['article_patterns'] ?? null, 'article_patterns', $violations, allowEmpty: false);
        $focus = $this->nonEmptyString($data['article_focus'] ?? null, 'article_focus', $violations);
        $insights = [];
        $rawInsights = $data['source_insights'] ?? null;
        if (! is_array($rawInsights) || ! array_is_list($rawInsights)) {
            $violations[] = 'source_insights must be a list';
        } else {
            foreach ($rawInsights as $index => $item) {
                if (! is_array($item)) {
                    $violations[] = "source_insights[{$index}] must be an object";

                    continue;
                }

                $this->rejectUnknownKeys($item, ['aspect', 'summary', 'source_quotes'], "source_insights[{$index}]", $violations);
                $aspect = $this->nonEmptyString($item['aspect'] ?? null, "source_insights[{$index}].aspect", $violations);
                $summary = $this->nonEmptyString($item['summary'] ?? null, "source_insights[{$index}].summary", $violations);
                $quotes = $this->stringList($item['source_quotes'] ?? null, "source_insights[{$index}].source_quotes", $violations, allowEmpty: false);
                $insights[] = new SourceInsight($aspect, $summary, $quotes);
            }
        }

        $excluded = [];
        $rawExcluded = $data['excluded_context'] ?? null;
        if (! is_array($rawExcluded) || ! array_is_list($rawExcluded)) {
            $violations[] = 'excluded_context must be a list';
        } else {
            foreach ($rawExcluded as $index => $item) {
                if (! is_array($item)) {
                    $violations[] = "excluded_context[{$index}] must be an object";

                    continue;
                }

                $this->rejectUnknownKeys($item, ['type', 'value'], "excluded_context[{$index}]", $violations);
                $excluded[] = new ExcludedContext(
                    $this->nonEmptyString($item['type'] ?? null, "excluded_context[{$index}].type", $violations),
                    $this->nonEmptyString($item['value'] ?? null, "excluded_context[{$index}].value", $violations),
                );
            }
        }

        if ($violations !== []) {
            throw new InvalidInspirationBrief($violations);
        }

        return new InspirationBrief($patterns, $focus, $insights, $excluded);
    }

    /** @param list<string> $allowed @param list<string> $violations */
    private function rejectUnknownKeys(array $data, array $allowed, string $path, array &$violations): void
    {
        foreach (array_diff(array_keys($data), $allowed) as $key) {
            $violations[] = "{$path}.{$key} is not allowed";
        }
    }

    /** @param list<string> $violations */
    private function nonEmptyString(mixed $value, string $path, array &$violations): string
    {
        if (! is_string($value) || trim($value) === '') {
            $violations[] = "{$path} must be a non-empty string";

            return '';
        }

        return $value;
    }

    /** @param list<string> $violations @return list<string> */
    private function stringList(mixed $value, string $path, array &$violations, bool $allowEmpty): array
    {
        if (! is_array($value) || ! array_is_list($value) || (! $allowEmpty && $value === [])) {
            $violations[] = "{$path} must be a".($allowEmpty ? '' : ' non-empty').' list';

            return [];
        }

        $result = [];
        foreach ($value as $index => $item) {
            if (! is_string($item) || trim($item) === '') {
                $violations[] = "{$path}[{$index}] must be a non-empty string";

                continue;
            }
            $result[] = $item;
        }

        return $result;
    }
}
