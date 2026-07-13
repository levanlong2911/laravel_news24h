<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

final class PlanningAssembler
{
    /** @param PlanningContribution[] $contributions */
    public function assemble(PlanningContext $context, array $contributions, int $shotOrder): PlanningIR
    {
        /** @var array<string, PlanningField> $resolved key = full namespaced key */
        $resolved = [];

        foreach ($contributions as $contribution) {
            foreach ($contribution->toFields() as $field) {
                if (!isset($resolved[$field->key]) || $field->priority > $resolved[$field->key]->priority) {
                    $resolved[$field->key] = $field;
                }
            }
        }

        $renderHints = [];
        $constraints = [];
        $attributes  = [];

        foreach ($resolved as $nsKey => $field) {
            $dotPos  = strpos($nsKey, '.');
            $ns      = $dotPos !== false ? substr($nsKey, 0, $dotPos) : 'render';
            $bareKey = $dotPos !== false ? substr($nsKey, $dotPos + 1) : $nsKey;

            match ($ns) {
                'constraint' => $constraints[$bareKey] = $field->value,
                'attribute'  => $attributes[$bareKey]  = $field->value,
                default      => $renderHints[$bareKey]  = $field->value,
            };
        }

        foreach ($this->contextFallbacks($context) as $key => $value) {
            if ($value !== '') {
                $renderHints[$key] ??= $value;
            }
        }

        return new PlanningIR(
            version:     1,
            shotId:      $context->goalId . '_' . $shotOrder,
            shotOrder:   $shotOrder,
            goalId:      $context->goalId,
            renderHints: $renderHints,
            constraints: $constraints,
            attributes:  $attributes,
        );
    }

    private function contextFallbacks(PlanningContext $context): array
    {
        return [
            'subject'     => $context->subject,
            'action'      => $context->action,
            'environment' => $context->environment,
        ];
    }
}
