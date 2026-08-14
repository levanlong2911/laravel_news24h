<?php

namespace App\Video\Concept;

use JsonException;

/**
 * Khoan dung với hình thức, tuyệt đối không bù nội dung — cùng kỷ luật
 * InspirationBriefParser. `concept_id` KHÔNG có ở đây: Laravel sinh id, model
 * không được bịa, nên bắt buộc một field model không được sinh là contract tự
 * mâu thuẫn.
 */
final class CreativeConceptParser
{
    private const ROOT_KEYS = ['design_thesis', 'design_identity', 'form_relationships', 'signature_features', 'decisions'];

    public function parse(string $text): CreativeConcept
    {
        $json = trim($text);
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $json, $match)) {
            $json = trim($match[1]);
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidCreativeConcept(['response is not valid JSON']);
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidCreativeConcept(['response root must be an object']);
        }

        $violations = [];
        $this->rejectUnknownKeys($data, self::ROOT_KEYS, 'root', $violations);

        $thesis = $this->nonEmptyString($data['design_thesis'] ?? null, 'design_thesis', $violations);

        $identity = $data['design_identity'] ?? null;
        if (! is_array($identity) || array_is_list($identity)) {
            $violations[] = 'design_identity must be an object';
            $identity = [];
        }

        $relationships = $this->relationships($data['form_relationships'] ?? null, $violations);

        $features = $this->features($data['signature_features'] ?? null, $violations);
        $decisions = $this->decisions($data['decisions'] ?? null, $violations);

        if ($violations !== []) {
            throw new InvalidCreativeConcept(array_values(array_unique($violations)));
        }

        return new CreativeConcept($thesis, $identity, $features, $decisions, $relationships);
    }

    /** @param list<string> $violations */
    private function relationships(mixed $raw, array &$violations): FormRelationships
    {
        $keys = ['governing_line', 'massing_rhythm', 'feature_integration'];
        if (! is_array($raw) || array_is_list($raw)) {
            $violations[] = 'form_relationships must be an object';

            return new FormRelationships('', '', '');
        }

        $this->rejectUnknownKeys($raw, $keys, 'form_relationships', $violations);

        return new FormRelationships(
            $this->nonEmptyString($raw['governing_line'] ?? null, 'form_relationships.governing_line', $violations),
            $this->nonEmptyString($raw['massing_rhythm'] ?? null, 'form_relationships.massing_rhythm', $violations),
            $this->nonEmptyString($raw['feature_integration'] ?? null, 'form_relationships.feature_integration', $violations),
        );
    }

    /**
     * @param  list<string>  $violations
     * @return list<SignatureFeature>
     */
    private function features(mixed $raw, array &$violations): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $violations[] = 'signature_features must be a list';

            return [];
        }

        $features = [];

        foreach ($raw as $index => $item) {
            if (! is_array($item) || array_is_list($item)) {
                $violations[] = "signature_features[{$index}] must be an object";

                continue;
            }

            $this->rejectUnknownKeys($item, ['description', 'visible_from'], "signature_features[{$index}]", $violations);
            $description = $this->nonEmptyString($item['description'] ?? null, "signature_features[{$index}].description", $violations);

            $viewpoints = [];
            $rawViewpoints = $item['visible_from'] ?? null;

            if (! is_array($rawViewpoints) || ! array_is_list($rawViewpoints) || $rawViewpoints === []) {
                $violations[] = "signature_features[{$index}].visible_from must be a non-empty list";
            } else {
                foreach ($rawViewpoints as $position => $value) {
                    $viewpoint = is_string($value) ? Viewpoint::tryFrom($value) : null;

                    if ($viewpoint === null) {
                        $violations[] = "signature_features[{$index}].visible_from[{$position}] is not an allowed viewpoint";

                        continue;
                    }

                    if (! in_array($viewpoint, $viewpoints, true)) {
                        $viewpoints[] = $viewpoint;
                    }
                }
            }

            $features[] = new SignatureFeature($description, $viewpoints);
        }

        return $features;
    }

    /**
     * @param  list<string>  $violations
     * @return list<DesignDecision>
     */
    private function decisions(mixed $raw, array &$violations): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $violations[] = 'decisions must be a list';

            return [];
        }

        $decisions = [];

        foreach ($raw as $index => $item) {
            if (! is_array($item) || array_is_list($item)) {
                $violations[] = "decisions[{$index}] must be an object";

                continue;
            }

            $this->rejectUnknownKeys($item, ['aspect', 'provenance', 'decision'], "decisions[{$index}]", $violations);
            $aspect = $this->nonEmptyString($item['aspect'] ?? null, "decisions[{$index}].aspect", $violations);
            $text = $this->nonEmptyString($item['decision'] ?? null, "decisions[{$index}].decision", $violations);

            $rawProvenance = $item['provenance'] ?? null;
            $provenance = is_string($rawProvenance) ? Provenance::tryFrom($rawProvenance) : null;

            if ($provenance === null) {
                $violations[] = "decisions[{$index}].provenance must be one of: ".implode(', ', Provenance::values());

                continue;
            }

            $decisions[] = new DesignDecision($aspect, $provenance, $text);
        }

        return $decisions;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     * @param  list<string>  $violations
     */
    private function rejectUnknownKeys(array $data, array $allowed, string $where, array &$violations): void
    {
        foreach (array_keys($data) as $key) {
            if (! in_array($key, $allowed, true)) {
                $violations[] = "{$where} contains unknown field {$key}";
            }
        }
    }

    /** @param list<string> $violations */
    private function nonEmptyString(mixed $value, string $where, array &$violations): string
    {
        if (! is_string($value) || trim($value) === '') {
            $violations[] = "{$where} must be a non-empty string";

            return '';
        }

        return $value;
    }
}
