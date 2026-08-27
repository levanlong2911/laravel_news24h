<?php

namespace App\Video\Concept;

use App\Video\Inspiration\CategoryCreativeProfile;
use App\Video\Inspiration\InspirationBrief;
use App\Video\Inspiration\SourceInsight;

final class ConceptValidator
{
    /**
     * KHUYEN NGHI bien tap, KHONG phai tran cung. Vuot -> ConceptWarning.
     * Tran cung duy nhat cho prose la MAX_PROSE_STORAGE_LENGTH.
     */
    public const RECOMMENDED_THESIS_LENGTH = 200;

    public const RECOMMENDED_FORM_RELATIONSHIP_LENGTH = 220;

    public const RECOMMENDED_FEATURE_LENGTH = 100;

    /**
     * Do tren hai luot Sonnet da tra tien: concept-v1 dai 156-229 ky tu,
     * concept-v2 (co form_relationships) dich len 221-311. Con so nay chi la
     * khuyen nghi — `decisions` KHONG di vao prompt anh, nen dai khong the lam
     * anh sai.
     */
    public const RECOMMENDED_DECISION_LENGTH = 260;

    /**
     * Tran CUNG cho moi truong prose. Max quan sat 311; 1000 chi chan payload
     * bat thuong, khong cham phan bo that.
     */
    public const MAX_PROSE_STORAGE_LENGTH = 1000;

    public const MAX_FEATURES = 3;

    /**
     * Sonnet KHÔNG nhận `excluded_context` — đưa nó vào prompt là tái lộ đúng
     * những tên vừa tốn công loại. Laravel giữ để đối chiếu ở đây.
     *
     * @return list<string>
     */
    public function validate(
        CreativeConcept $concept,
        CategoryCreativeProfile $profile,
        InspirationBrief $brief,
    ): ConceptValidationResult {
        // Constructor của các DTO đều public — validator không được dựa ngầm
        // vào việc concept đã đi qua parser.
        $profile->assertConceptReady();

        $violations = [];
        $warnings = [];

        $this->checkProse('design_thesis', $concept->designThesis, self::RECOMMENDED_THESIS_LENGTH, $violations, $warnings);
        $this->checkIdentity($concept, $profile, $violations, $warnings);
        $this->checkFormRelationships($concept, $violations, $warnings);
        $this->checkFeatures($concept, $violations, $warnings);
        $this->checkDecisions($concept, $profile, $brief, $violations, $warnings);
        $this->checkExcludedIdentity($concept, $brief, $violations);
        $this->checkForbiddenTerms($concept, $profile, $violations);

        return new ConceptValidationResult(array_values(array_unique($violations)), $warnings);
    }

    /**
     * @param  list<string>  $violations
     * @param  list<ConceptWarning>  $warnings
     */
    private function checkProse(string $field, string $value, int $recommended, array &$violations, array &$warnings): void
    {
        if (trim($value) === '') {
            $violations[] = "{$field} must not be empty";

            return;
        }

        $length = mb_strlen($value);

        if ($length > self::MAX_PROSE_STORAGE_LENGTH) {
            $violations[] = "{$field} exceeds the ".self::MAX_PROSE_STORAGE_LENGTH.' character storage ceiling';

            return;
        }

        if ($length > $recommended) {
            $warnings[] = new ConceptWarning(
                ConceptWarning::PROSE_EXCEEDS_RECOMMENDED_LENGTH, $field, $length, $recommended,
            );
        }
    }

    /** @param list<string> $violations */
    private function checkFormRelationships(CreativeConcept $concept, array &$violations, array &$warnings): void
    {
        foreach ($concept->formRelationships->toArray() as $name => $value) {
            $this->checkProse("form_relationships.{$name}", $value, self::RECOMMENDED_FORM_RELATIONSHIP_LENGTH, $violations, $warnings);
        }
    }

    /** @param list<string> $violations */
    private function checkIdentity(CreativeConcept $concept, CategoryCreativeProfile $profile, array &$violations, array &$warnings): void
    {
        $declared = array_keys($profile->identitySlots);
        $supplied = array_keys($concept->designIdentity);

        // So TẬP KHOÁ, không so số lượng: thiếu một và thừa một vẫn cùng count.
        foreach (array_diff($declared, $supplied) as $missing) {
            $violations[] = "design_identity is missing slot {$missing}";
        }

        foreach (array_diff($supplied, $declared) as $unknown) {
            $violations[] = "design_identity contains unknown slot {$unknown}";
        }

        foreach ($profile->identitySlots as $slot => $spec) {
            if (! array_key_exists($slot, $concept->designIdentity)) {
                continue;
            }

            $this->checkSlotValue($slot, $spec, $concept->designIdentity[$slot], $violations, $warnings);
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<string>  $violations
     */
    private function checkSlotValue(string $slot, array $spec, mixed $value, array &$violations, array &$warnings): void
    {
        switch ($spec['type']) {
            case 'text':
                if (! is_string($value)) {
                    $violations[] = "design_identity.{$slot} must be a non-empty string";

                    return;
                }

                $this->checkProse("design_identity.{$slot}", $value, (int) $spec['max_length'], $violations, $warnings);

                return;

            case 'integer':
                // is_int() nghiêm ngặt: true, 4.0 và "4" đều KHÔNG phải integer.
                if (! is_int($value)) {
                    $violations[] = "design_identity.{$slot} must be an integer";

                    return;
                }

                break;

            case 'enum':
                if (! in_array($value, $spec['values'], true)) {
                    $violations[] = "design_identity.{$slot} must be one of ".implode(', ', $spec['values']);
                }

                return;

            case 'object':
                if (! is_array($value) || ($value !== [] && array_is_list($value))) {
                    $violations[] = "design_identity.{$slot} must be an object";

                    return;
                }

                foreach (array_diff(array_keys($spec['fields']), array_keys($value)) as $missing) {
                    $violations[] = "design_identity.{$slot} is missing field {$missing}";
                }

                foreach (array_diff(array_keys($value), array_keys($spec['fields'])) as $unknown) {
                    $violations[] = "design_identity.{$slot} contains unknown field {$unknown}";
                }

                foreach ($spec['fields'] as $field => $fieldSpec) {
                    if (array_key_exists($field, $value)) {
                        $this->checkSlotValue("{$slot}.{$field}", $fieldSpec, $value[$field], $violations, $warnings);
                    }
                }

                return;

            case 'number':
                if ((! is_int($value) && ! is_float($value)) || (is_float($value) && ! is_finite($value))) {
                    $violations[] = "design_identity.{$slot} must be a finite number";

                    return;
                }

                break;
        }

        if ($value < $spec['min'] || $value > $spec['max']) {
            $violations[] = "design_identity.{$slot} must be between {$spec['min']} and {$spec['max']}";
        }
    }

    /** @param list<string> $violations */
    private function checkFeatures(CreativeConcept $concept, array &$violations, array &$warnings): void
    {
        $count = count($concept->signatureFeatures);

        if ($count < 1 || $count > self::MAX_FEATURES) {
            $violations[] = 'signature_features must hold between 1 and '.self::MAX_FEATURES.' entries';
        }

        foreach ($concept->signatureFeatures as $index => $feature) {
            $this->checkProse("signature_features[{$index}].description", $feature->description, self::RECOMMENDED_FEATURE_LENGTH, $violations, $warnings);

            if ($feature->visibleFrom === []) {
                $violations[] = "signature_features[{$index}].visible_from must name at least one viewpoint";
            }
        }
    }

    /** @param list<string> $violations */
    private function checkDecisions(
        CreativeConcept $concept,
        CategoryCreativeProfile $profile,
        InspirationBrief $brief,
        array &$violations,
        array &$warnings,
    ): void {
        $covered = [];

        $withInsight = array_fill_keys(array_map(
            fn (SourceInsight $insight) => $insight->aspect,
            $brief->sourceInsights,
        ), true);

        foreach ($concept->decisions as $index => $decision) {
            $this->checkProse("decisions[{$index}].decision", $decision->decision, self::RECOMMENDED_DECISION_LENGTH, $violations, $warnings);

            if (! in_array($decision->aspect, $profile->inspectionAspects, true)) {
                $violations[] = "decisions[{$index}] aspect {$decision->aspect} is not an inspection aspect";

                continue;
            }

            if (isset($covered[$decision->aspect])) {
                $violations[] = "decisions holds more than one entry for aspect {$decision->aspect}";
            }

            $covered[$decision->aspect] = true;

            // `invented` hợp lệ với MỌI aspect — nguồn có nói không có nghĩa là
            // buộc phải dùng. Chỉ `inspired` mới đòi có insight thật.
            if ($decision->provenance === Provenance::Inspired && ! isset($withInsight[$decision->aspect])) {
                $violations[] = "decisions[{$index}] claims inspiration for {$decision->aspect}, which the brief did not cover";
            }
        }

        foreach ($profile->inspectionAspects as $aspect) {
            if (! isset($covered[$aspect])) {
                $violations[] = "decisions is missing aspect {$aspect}";
            }
        }
    }

    /** @param list<string> $violations */
    private function checkExcludedIdentity(CreativeConcept $concept, InspirationBrief $brief, array &$violations): void
    {
        $excluded = array_map(
            fn ($item) => mb_strtolower($item->value),
            $brief->excludedContext,
        );

        if ($excluded === []) {
            return;
        }

        foreach ($this->proseFields($concept) as $where => $text) {
            foreach ($excluded as $value) {
                if ($this->containsTerm(mb_strtolower($text), $value)) {
                    $violations[] = "{$where} contains excluded identity context";
                    break;
                }
            }
        }
    }

    /**
     * @param  list<string>  $violations
     */
    private function checkForbiddenTerms(CreativeConcept $concept, CategoryCreativeProfile $profile, array &$violations): void
    {
        if ($profile->conceptForbiddenTerms === []) {
            return;
        }

        foreach ($this->proseFields($concept) as $where => $text) {
            foreach ($profile->conceptForbiddenTerms as $term) {
                if ($this->containsTerm(mb_strtolower($text), mb_strtolower($term))) {
                    $violations[] = "{$where} uses a forbidden form: {$term}";
                    break;
                }
            }
        }
    }

    /** @return array<string, string> */
    private function proseFields(CreativeConcept $concept): array
    {
        $fields = ['design_thesis' => $concept->designThesis];

        foreach ($concept->designIdentity as $slot => $value) {
            $fields += $this->flattenStrings("design_identity.{$slot}", $value);
        }

        foreach ($concept->formRelationships->toArray() as $name => $value) {
            $fields["form_relationships.{$name}"] = $value;
        }

        foreach ($concept->signatureFeatures as $index => $feature) {
            $fields["signature_features[{$index}].description"] = $feature->description;
        }

        foreach ($concept->decisions as $index => $decision) {
            $fields["decisions[{$index}].decision"] = $decision->decision;
        }

        return $fields;
    }

    /** @return array<string, string> */
    private function flattenStrings(string $path, mixed $value): array
    {
        if (is_string($value)) {
            return [$path => $value];
        }

        if (! is_array($value)) {
            return [];
        }

        $flat = [];
        foreach ($value as $key => $inner) {
            $flat += $this->flattenStrings("{$path}.{$key}", $inner);
        }

        return $flat;
    }

    private function containsTerm(string $haystack, string $term): bool
    {
        if ($term === '') {
            return false;
        }

        return preg_match(
            '/(?<![\pL\pN])'.preg_quote($term, '/').'(?![\pL\pN])/u',
            $haystack,
        ) === 1;
    }
}
