<?php

namespace App\Video\Concept;

use App\Video\Inspiration\CategoryCreativeProfile;
use App\Video\Inspiration\InspirationBrief;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use Illuminate\Support\Str;
use RuntimeException;

final class SonnetScreenConceptDesigner
{
    public const INSTRUCTION_VERSION = 'screen-concept-v1';

    /**
     * @return array<string, mixed>
     */
    public function design(
        LlmClient $llm,
        InspirationBrief $brief,
        CategoryCreativeProfile $profile,
        string $objectType,
    ): array {
        $profile->assertConceptReady();

        $response = $llm->complete(new LlmRequest(
            instruction: $this->instruction($profile),
            input: $this->input($brief, $profile, $objectType),
            instructionVersion: self::INSTRUCTION_VERSION,
            model: 'sonnet5',
            maxTokens: 8000,
        ));

        $concept = $this->normalizeForPythonPrompt($this->decodeJsonObject($response->text));
        $this->assertConceptShape($concept, $profile);

        return $concept;
    }

    private function instruction(CategoryCreativeProfile $profile): string
    {
        return implode("\n\n", [
            'You are a concept designer. Return exactly one JSON object and no prose outside JSON.',
            $profile->conceptMission,
            'The JSON object must have exactly these top-level keys: design_thesis, design_identity, form_relationships, signature_features, decisions.',
            'design_thesis must be one concise sentence.',
            'design_identity must contain every required slot below and no extra keys.',
            $this->identitySlotLines($profile),
            'form_relationships must be an object with three concise string fields: governing_line, massing_rhythm, feature_integration.',
            'signature_features must be a list of 3 objects. Each object must have name, description, and visible_from.',
            'signature_features.visible_from must be exactly ["front_three_quarter", "side", "rear_three_quarter"] unless a feature is genuinely invisible from one of those views.',
            'decisions must be a list of 3 objects. Each object must have area and decision strings.',
            'Do not write image-generation prompts, camera instructions, or video prompts.',
            'Do not copy source-specific identity. Use excluded_context as forbidden source context.',
            $this->avoidLines($profile),
        ]);
    }

    private function identitySlotLines(CategoryCreativeProfile $profile): string
    {
        $lines = ['Required design_identity slots:'];

        foreach ($profile->identitySlots as $name => $spec) {
            $lines[] = '- '.$name.': '.$this->slotDescription($spec);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function slotDescription(array $spec): string
    {
        $type = (string) ($spec['type'] ?? 'text');

        if ($type === 'object') {
            $fields = [];

            foreach (($spec['fields'] ?? []) as $field => $fieldSpec) {
                $fields[] = $field.'='.$this->slotDescription($fieldSpec);
            }

            return 'object with fields: '.implode('; ', $fields);
        }

        if ($type === 'enum') {
            return count($spec['values'] ?? []) === 1
                ? 'always "'.$spec['values'][0].'"'
                : 'one of: '.implode(', ', $spec['values'] ?? []);
        }

        if ($type === 'integer' || $type === 'number') {
            return $type.' between '.$spec['min'].' and '.$spec['max'];
        }

        if ($type === 'boolean') {
            return 'true or false'.(isset($spec['guidance']) ? '; '.$spec['guidance'] : '');
        }

        return 'short text'.(isset($spec['max_length']) ? ' up to '.$spec['max_length'].' characters' : '');
    }

    private function avoidLines(CategoryCreativeProfile $profile): string
    {
        $parts = [];

        if ($profile->conceptAntipatterns !== []) {
            $parts[] = "Avoid these design antipatterns:\n- ".implode("\n- ", $profile->conceptAntipatterns);
        }

        if ($profile->conceptForbiddenTerms !== []) {
            $parts[] = 'Do not use these forbidden terms anywhere in the JSON: '.implode(', ', $profile->conceptForbiddenTerms).'.';
        }

        return implode("\n\n", $parts);
    }

    private function input(
        InspirationBrief $brief,
        CategoryCreativeProfile $profile,
        string $objectType,
    ): string {
        return json_encode([
            'object_type' => $objectType,
            'source_derived_inspiration' => $brief->toArray($profile),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(string $text): array
    {
        $json = trim($text);

        if (str_starts_with($json, '```')) {
            $json = preg_replace('/\A```(?:json)?\s*/i', '', $json) ?? $json;
            $json = preg_replace('/\s*```\z/', '', $json) ?? $json;
        }

        if (! str_starts_with(ltrim($json), '{')) {
            $start = strpos($json, '{');
            $end = strrpos($json, '}');

            if ($start !== false && $end !== false && $end > $start) {
                $json = substr($json, $start, $end - $start + 1);
            }
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Sonnet concept response must be a JSON object.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $concept
     * @return array<string, mixed>
     */
    private function normalizeForPythonPrompt(array $concept): array
    {
        if (! is_array($concept['signature_features'] ?? null)) {
            return $concept;
        }

        foreach ($concept['signature_features'] as $index => $feature) {
            if (! is_array($feature)) {
                continue;
            }

            $feature['visible_from'] = $this->validVisibleFrom($feature['visible_from'] ?? null);

            $concept['signature_features'][$index] = $feature;
        }

        return $concept;
    }

    /**
     * @return list<string>
     */
    private function validVisibleFrom(mixed $visibleFrom): array
    {
        /*
         * Lay tu enum Viewpoint chu KHONG khai lai chuoi: hai danh sach song
         * song thi som muon lech nhau. Them mot case vao enum ma o day khong
         * biet, view do se bi loc bo nhu view la — im lang, khong test nao do.
         */
        $defaultViews = array_column(Viewpoint::cases(), 'value');

        if (! is_array($visibleFrom)) {
            return $defaultViews;
        }

        $allowed = array_fill_keys($defaultViews, true);
        $valid = [];

        foreach ($visibleFrom as $view) {
            if (! is_string($view)) {
                continue;
            }

            $view = trim($view);

            if (isset($allowed[$view]) && ! in_array($view, $valid, true)) {
                $valid[] = $view;
            }
        }

        return $valid !== [] ? $valid : $defaultViews;
    }

    /**
     * @param  array<string, mixed>  $concept
     */
    private function assertConceptShape(array $concept, CategoryCreativeProfile $profile): void
    {
        $required = ['design_thesis', 'design_identity', 'form_relationships', 'signature_features', 'decisions'];
        $missing = array_values(array_diff($required, array_keys($concept)));

        if ($missing !== []) {
            throw new RuntimeException('Sonnet concept is missing '.implode(', ', $missing).'.');
        }

        if (! is_string($concept['design_thesis']) || trim($concept['design_thesis']) === '') {
            throw new RuntimeException('Sonnet concept design_thesis must be a non-empty string.');
        }

        if (! is_array($concept['design_identity'] ?? null) || array_is_list($concept['design_identity'])) {
            throw new RuntimeException('Sonnet concept design_identity must be an object.');
        }

        $absent = array_values(array_diff(array_keys($profile->identitySlots), array_keys($concept['design_identity'])));

        if ($absent !== []) {
            throw new RuntimeException('Sonnet concept design_identity is missing '.Str::limit(implode(', ', $absent), 180).'.');
        }
    }
}
