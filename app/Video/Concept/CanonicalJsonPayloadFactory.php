<?php

declare(strict_types=1);

namespace App\Video\Concept;

use App\Video\Concept\Exceptions\CanonicalValidationException;
use App\Video\Concept\Validation\ValidationError;
use stdClass;
use Throwable;

final class CanonicalJsonPayloadFactory
{
    /**
     * Bien duy nhat giua text cua provider va payload canonical — nen phep
     * "chieu ve" nam o day, khong nam o processor. Dat o processor thi phai
     * nho lam ca nhanh sinh LAN nhanh sua; som muon quen mot nhanh.
     */
    public function create(
        string $rawJson
    ): CanonicalJsonPayload {
        try {
            return CanonicalJsonPayload::fromRawJson(
                $this->withoutProviderNulls($rawJson)
            );
        } catch (Throwable $e) {
            throw new CanonicalValidationException(
                errors: [
                    new ValidationError(
                        code: 'invalid_canonical_json',

                        path: '$',

                        message: $e->getMessage(),
                    ),
                ],

                failedRawJson: $rawJson,

                message: 'Claude returned an invalid '
                    .'canonical JSON payload.',
            );
        }
    }

    /**
     * ClaudeSchemaAdapter bat MOI property phai `required`, cai nao von optional
     * thi cho nhan them `null` — de so optional gui provider ve 0. Doi lai,
     * model tra ve `"tolerance": null` cho nhung o no khong dung.
     *
     * Core schema khai `additionalProperties: false` va KHONG co truong nullable
     * nao (da kiem: 0/0), nen mot khoa mang `null` chac chan la o trong, khong
     * phai gia tri that. Bo di la phep dao nguoc dung, khong nhap nhang.
     *
     * Neu JSON hong thi TRA LAI NGUYEN VAN: viec bao loi thuoc ve
     * CanonicalJsonPayload::fromRawJson(), va `failedRawJson` gui di sua phai la
     * cai model that su viet.
     */
    private function withoutProviderNulls(
        string $rawJson
    ): string {
        try {
            /*
             * associative: FALSE co y — phai giu duoc phan biet {} voi [].
             * Decode assoc roi encode lai se bien mot object rong thanh mang
             * rong, va core schema se tu choi no.
             */
            $decoded = json_decode(
                $rawJson,
                associative: false,
                depth: 512,
                flags: JSON_THROW_ON_ERROR
            );

            $decoded = $this->unwrapProviderEnvelope($decoded);

            return json_encode(
                $this->expandProviderRelationships(
                    $this->stripNulls($decoded)
                ),
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
            );
        } catch (Throwable) {
            return $rawJson;
        }
    }

    private function unwrapProviderEnvelope(
        mixed $decoded
    ): mixed {
        if (! $decoded instanceof stdClass) {
            return $decoded;
        }

        $vars = get_object_vars($decoded);

        if (
            array_keys($vars) !== ['canonical_json']
            || ! is_string($decoded->canonical_json)
        ) {
            return $decoded;
        }

        return json_decode(
            $decoded->canonical_json,
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR
        );
    }

    private function stripNulls(
        mixed $node
    ): mixed {
        if ($node instanceof stdClass) {
            $clean = new stdClass;

            foreach (
                get_object_vars($node) as $key => $value
            ) {
                if ($value === null) {
                    continue;
                }

                $clean->{$key} =
                    $this->stripNulls($value);
            }

            return $clean;
        }

        /*
         * Phan tu `null` NAM TRONG mang thi giu nguyen: no khong phai "o trong"
         * ma la mot phan tu sai kieu — de validator bao ra, dung giau di.
         */
        if (is_array($node)) {
            return array_map(
                fn (mixed $item): mixed => $this->stripNulls($item),
                $node
            );
        }

        return $node;
    }

    private function expandProviderRelationships(
        mixed $node
    ): mixed {
        if (! $node instanceof stdClass) {
            return $node;
        }

        if (
            ! isset($node->relationships)
            || ! is_array($node->relationships)
        ) {
            return $node;
        }

        $node->relationships = array_map(
            fn (mixed $relationship): mixed =>
                $this->expandProviderRelationship($relationship),
            $node->relationships
        );

        return $node;
    }

    private function expandProviderRelationship(
        mixed $relationship
    ): mixed {
        if (! $relationship instanceof stdClass) {
            return $relationship;
        }

        $type =
            is_string($relationship->type ?? null)
                ? $relationship->type
                : null;

        if ($type === null) {
            return $relationship;
        }

        if (! $this->isProviderCompactRelationship($relationship)) {
            return $relationship;
        }

        $out = new stdClass;
        $out->id = $relationship->id ?? '';
        $out->type = $type;

        match ($type) {
            'count' => $this->expandCountRelationship($out, $relationship),
            'grouping' => $this->expandGroupingRelationship($out, $relationship),
            'one_to_one' => $this->expandOneToOneRelationship($out, $relationship),
            'proportion' => $this->expandProportionRelationship($out, $relationship),
            'position' => $this->expandPositionRelationship($out, $relationship),
            'order' => $this->expandOrderRelationship($out, $relationship),
            'continuity' => $this->expandContinuityRelationship($out, $relationship),
            'connectivity' => $this->expandConnectivityRelationship($out, $relationship),
            'symmetry' => $this->expandSymmetryRelationship($out, $relationship),
            'containment' => $this->expandContainmentRelationship($out, $relationship),
            'alignment' => $this->expandAlignmentRelationship($out, $relationship),
            default => null,
        };

        return $out;
    }

    private function isProviderCompactRelationship(
        stdClass $relationship
    ): bool {
        return property_exists($relationship, 'integer_value')
            || property_exists($relationship, 'number_value')
            || property_exists($relationship, 'contained_path')
            || property_exists($relationship, 'group_count');
    }

    private function expandCountRelationship(
        stdClass $out,
        stdClass $relationship
    ): void {
        $out->subject_path = $relationship->subject_path ?? '';
        $out->value = $relationship->integer_value ?? null;
    }

    private function expandGroupingRelationship(
        stdClass $out,
        stdClass $relationship
    ): void {
        $out->subject_path = $relationship->subject_path ?? '';
        $out->group_count = $relationship->group_count ?? null;
        $this->copyNonEmpty($out, $relationship, 'grouped_into_path');
        $this->copyNonEmpty($out, $relationship, 'meaning');
    }

    private function expandOneToOneRelationship(
        stdClass $out,
        stdClass $relationship
    ): void {
        $out->source_path = $relationship->source_path ?? '';
        $out->target_path = $relationship->target_path ?? '';
        $this->copyNonEmpty($out, $relationship, 'meaning');
    }

    private function expandProportionRelationship(
        stdClass $out,
        stdClass $relationship
    ): void {
        $out->subject_path = $relationship->subject_path ?? '';
        $out->metric = $relationship->metric ?? '';
        $out->value = $relationship->number_value ?? null;
    }

    private function expandPositionRelationship(
        stdClass $out,
        stdClass $relationship
    ): void {
        $out->subject_path = $relationship->subject_path ?? '';
        $out->reference_path = $relationship->reference_path ?? '';
        $out->value = $relationship->value ?? '';
    }

    private function expandOrderRelationship(
        stdClass $out,
        stdClass $relationship
    ): void {
        $out->items = $relationship->items ?? [];
        $this->copyNonEmpty($out, $relationship, 'direction');
    }

    private function expandContinuityRelationship(
        stdClass $out,
        stdClass $relationship
    ): void {
        $out->subject_path = $relationship->subject_path ?? '';
        $out->value = $relationship->value ?? '';
        $this->copyNonEmpty($out, $relationship, 'start_path');
        $this->copyNonEmpty($out, $relationship, 'end_path');
    }

    private function expandConnectivityRelationship(
        stdClass $out,
        stdClass $relationship
    ): void {
        $out->members = $relationship->members ?? [];
        $out->value = $relationship->value ?? '';
    }

    private function expandSymmetryRelationship(
        stdClass $out,
        stdClass $relationship
    ): void {
        $out->subject_path = $relationship->subject_path ?? '';
        $out->axis = $relationship->axis ?? '';
        $out->value = $relationship->value ?? '';
    }

    private function expandContainmentRelationship(
        stdClass $out,
        stdClass $relationship
    ): void {
        $out->container_path = $relationship->container_path ?? '';
        $out->contained_path = $relationship->contained_path ?? '';
    }

    private function expandAlignmentRelationship(
        stdClass $out,
        stdClass $relationship
    ): void {
        $out->members = $relationship->members ?? [];
        $out->value = $relationship->value ?? '';
    }

    private function copyNonEmpty(
        stdClass $out,
        stdClass $relationship,
        string $key
    ): void {
        $value =
            $relationship->{$key}
            ?? null;

        if (
            is_string($value)
            && trim($value) !== ''
        ) {
            $out->{$key} = $value;
        }
    }
}
