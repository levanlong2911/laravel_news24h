<?php

namespace App\Services\AI\AFOS\Ontology;

/**
 * EntityRef — a typed, semantic reference to a scene entity.
 *
 * Replaces bare string entity IDs (e.g. "subject") with a structured object
 * that carries both the vocabulary key and the semantic category.
 *
 * The entityId is the key used by KlingPromptPlanningPass.entityToPhrase()
 * to look up the human-readable phrase. If no match is found, the displayName
 * is used directly — ensuring the prompt always makes semantic sense even for
 * entities not yet in the vocabulary table.
 *
 * The entityType is used by passes that need to reason about what kind of thing
 * the subject is (e.g. a water feature → camera at ankle height).
 */
final class EntityRef
{
    public function __construct(
        public readonly string     $entityId,    // snake_case vocabulary key
        public readonly EntityType $entityType,
        public readonly string     $displayName, // human-readable fallback
    ) {}

    public function toArray(): array
    {
        return [
            'entityId'    => $this->entityId,
            'entityType'  => $this->entityType->value,
            'displayName' => $this->displayName,
        ];
    }

    public static function generic(string $label): self
    {
        $id = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($label))) ?: 'hero_subject';
        return new self($id, EntityType::GENERIC, $label ?: 'subject');
    }
}
