<?php

namespace App\Video\Concept;

use App\Video\Inspiration\CategoryCreativeProfile;
use App\Video\Inspiration\InspirationBrief;
use App\Video\Inspiration\SourceInsight;

final class ConceptValidator
{
    public const MAX_THESIS = 200;

    /**
     * 260 chứ không phải 200: decision phải chứa CẢ lựa chọn lẫn cách chuyển hoá
     * khỏi nguồn. Đo trên lượt Sonnet đã trả tiền — 12 decision dài 156→229, nên
     * 200 cắt ngang vùng tự nhiên và retry chỉ dồn chi tiết sang chỗ chưa bị
     * mắng (identity/feature về 0 violation, decision từ 2 lên 4).
     */
    public const MAX_DECISION = 260;

    public const MAX_FEATURE_DESCRIPTION = 100;

    public const MAX_FEATURES = 3;

    public const MAX_FORM_RELATIONSHIP = 220;

    /**
     * Sonnet KHÔNG nhận `excluded_context` — đưa nó vào prompt là tái lộ đúng
     * những tên vừa tốn công loại. Laravel giữ để đối chiếu ở đây.
     *
     * @return list<string>
     */
    public function violations(
        CreativeConcept $concept,
        CategoryCreativeProfile $profile,
        InspirationBrief $brief,
    ): array {
        // Constructor của các DTO đều public — validator không được dựa ngầm
        // vào việc concept đã đi qua parser.
        $profile->assertConceptReady();

        $violations = [];

        if (trim($concept->designThesis) === '') {
            $violations[] = 'design_thesis must not be empty';
        } elseif (mb_strlen($concept->designThesis) > self::MAX_THESIS) {
            $violations[] = 'design_thesis exceeds '.self::MAX_THESIS.' characters';
        }

        $this->checkIdentity($concept, $profile, $violations);
        $this->checkFormRelationships($concept, $violations);
        $this->checkFeatures($concept, $violations);
        $this->checkDecisions($concept, $profile, $brief, $violations);
        $this->checkExcludedIdentity($concept, $brief, $violations);

        return array_values(array_unique($violations));
    }

    /** @param list<string> $violations */
    private function checkFormRelationships(CreativeConcept $concept, array &$violations): void
    {
        foreach ($concept->formRelationships->toArray() as $name => $value) {
            if (trim($value) === '') {
                $violations[] = "form_relationships.{$name} must not be empty";
            } elseif (mb_strlen($value) > self::MAX_FORM_RELATIONSHIP) {
                $violations[] = "form_relationships.{$name} exceeds ".self::MAX_FORM_RELATIONSHIP.' characters';
            }
        }
    }

    /** @param list<string> $violations */
    private function checkIdentity(CreativeConcept $concept, CategoryCreativeProfile $profile, array &$violations): void
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

            $this->checkSlotValue($slot, $spec, $concept->designIdentity[$slot], $violations);
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<string>  $violations
     */
    private function checkSlotValue(string $slot, array $spec, mixed $value, array &$violations): void
    {
        switch ($spec['type']) {
            case 'text':
                if (! is_string($value) || trim($value) === '') {
                    $violations[] = "design_identity.{$slot} must be a non-empty string";

                    return;
                }

                if (mb_strlen($value) > $spec['max_length']) {
                    $violations[] = "design_identity.{$slot} exceeds {$spec['max_length']} characters";
                }

                return;

            case 'integer':
                // is_int() nghiêm ngặt: true, 4.0 và "4" đều KHÔNG phải integer.
                if (! is_int($value)) {
                    $violations[] = "design_identity.{$slot} must be an integer";

                    return;
                }

                break;

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
    private function checkFeatures(CreativeConcept $concept, array &$violations): void
    {
        $count = count($concept->signatureFeatures);

        if ($count < 1 || $count > self::MAX_FEATURES) {
            $violations[] = 'signature_features must hold between 1 and '.self::MAX_FEATURES.' entries';
        }

        foreach ($concept->signatureFeatures as $index => $feature) {
            if (trim($feature->description) === '') {
                $violations[] = "signature_features[{$index}].description must not be empty";
            } elseif (mb_strlen($feature->description) > self::MAX_FEATURE_DESCRIPTION) {
                $violations[] = "signature_features[{$index}].description exceeds ".self::MAX_FEATURE_DESCRIPTION.' characters';
            }

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
    ): void {
        $covered = [];

        $withInsight = array_fill_keys(array_map(
            fn (SourceInsight $insight) => $insight->aspect,
            $brief->sourceInsights,
        ), true);

        foreach ($concept->decisions as $index => $decision) {
            if (trim($decision->decision) === '') {
                $violations[] = "decisions[{$index}].decision must not be empty";
            } elseif (mb_strlen($decision->decision) > self::MAX_DECISION) {
                $violations[] = "decisions[{$index}].decision exceeds ".self::MAX_DECISION.' characters';
            }

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

        $fields = ['design_thesis' => $concept->designThesis];

        foreach ($concept->designIdentity as $slot => $value) {
            if (is_string($value)) {
                $fields["design_identity.{$slot}"] = $value;
            }
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

        foreach ($fields as $where => $text) {
            foreach ($excluded as $value) {
                if ($this->containsTerm(mb_strtolower($text), $value)) {
                    $violations[] = "{$where} contains excluded identity context";
                    break;
                }
            }
        }
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
