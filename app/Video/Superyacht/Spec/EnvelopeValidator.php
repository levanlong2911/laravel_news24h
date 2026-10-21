<?php

namespace App\Video\Superyacht\Spec;

use App\Video\Superyacht\Validation\ValidationReport;
use App\Video\Superyacht\Validation\Violation;

final class EnvelopeValidator
{
    public const VERSION = 'envelope-6.3.0-1';

    /** @var list<string> */
    public const STATUSES = ['ok', 'partial', 'fail'];

    /** @var array<string, string> field => JSON type, as shown by "Output envelope" in Common standards */
    private const FIELDS = [
        'phase' => 'string',
        'prompt_version' => 'string',
        'project_id' => 'string',
        'status' => 'string',
        'data' => 'object',
        'flags' => 'array',
        'notes' => 'string',
    ];

    /**
     * @return array{report: ValidationReport, status: ?string, notices: list<string>}
     */
    public function check(mixed $envelope, string $phaseCode, string $projectId): array
    {
        if (! is_object($envelope)) {
            return ['report' => new ValidationReport([new Violation('ENVELOPE', 'The output is not a JSON object.')]), 'status' => null, 'notices' => []];
        }

        $violations = [];
        $notices = [];

        foreach (self::FIELDS as $field => $type) {
            if (! property_exists($envelope, $field)) {
                $violations[] = new Violation('ENVELOPE', "The envelope has no {$field}.", '/'.$field);
            } elseif (! $this->hasType($envelope->{$field}, $type)) {
                $violations[] = new Violation('ENVELOPE', "{$field} must be a JSON {$type}.", '/'.$field);
            }
        }

        $status = is_string($envelope->status ?? null) ? $envelope->status : null;

        if ($status !== null && ! in_array($status, self::STATUSES, true)) {
            $violations[] = new Violation('ENVELOPE', 'status must be one of '.implode(', ', self::STATUSES).'.', '/status');
        }

        $expectedPhase = strtoupper((string) preg_replace('/^phase-/', '', $phaseCode));

        if (is_string($envelope->phase ?? null) && $envelope->phase !== $expectedPhase) {
            $violations[] = new Violation('ENVELOPE', "phase is {$envelope->phase}; this run expects {$expectedPhase}.", '/phase');
        }

        if (is_string($envelope->project_id ?? null) && $envelope->project_id !== $projectId) {
            $violations[] = new Violation('ENVELOPE', "project_id is {$envelope->project_id}; this run belongs to {$projectId}.", '/project_id');
        }

        foreach (array_diff(array_keys(get_object_vars($envelope)), array_keys(self::FIELDS)) as $extra) {
            $notices[] = "Key {$extra} is not part of the documented envelope and was not checked.";
        }

        if (is_array($envelope->flags ?? null) && $envelope->flags !== []) {
            $notices[] = 'The document does not define the format of a flag; flags were not checked.';
        }

        if ($status === 'partial') {
            $notices[] = 'status partial means a fallback was used; the result is not applied automatically.';
        }

        return ['report' => new ValidationReport($violations), 'status' => $status, 'notices' => $notices];
    }

    private function hasType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'object' => is_object($value),
            'array' => is_array($value) && array_is_list($value),
            default => false,
        };
    }
}
