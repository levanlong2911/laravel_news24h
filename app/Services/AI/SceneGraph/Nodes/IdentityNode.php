<?php

namespace App\Services\AI\SceneGraph\Nodes;

/**
 * Subject identity fields that MUST NOT change between shots in a scene.
 * Locked from shot 1 and injected into every subsequent shot's CONTINUITY section.
 *
 * Fields are populated progressively — early sprints may leave them empty.
 * Sprint 6 will fill them from VisualMomentDTO (jersey color, number, etc.).
 */
final class IdentityNode
{
    public function __construct(
        /** Human-readable role: "quarterback", "goalkeeper", "driver" */
        public readonly string $role,
        /** Gender label for identity persistence */
        public readonly string $gender,
        /** Jersey color/description */
        public readonly string $jersey,
        /** Helmet style/color */
        public readonly string $helmet,
        /** Jersey number as string */
        public readonly string $number,
        /** Body posture label */
        public readonly string $posture,
    ) {}

    public static function from(array $data): self
    {
        return new self(
            role:    $data['role']    ?? '',
            gender:  $data['gender']  ?? '',
            jersey:  $data['jersey']  ?? '',
            helmet:  $data['helmet']  ?? '',
            number:  $data['number']  ?? '',
            posture: $data['posture'] ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'role'    => $this->role,
            'gender'  => $this->gender,
            'jersey'  => $this->jersey,
            'helmet'  => $this->helmet,
            'number'  => $this->number,
            'posture' => $this->posture,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->role === '';
    }
}
