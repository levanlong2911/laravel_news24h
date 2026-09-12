<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\StateMachine;

use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;

final class CanonicalConceptStateMachine
{
    /**
     * @return list<CanonicalConceptStatus>
     */
    public function allowedNext(
        CanonicalConceptStatus $status
    ): array {
        return match ($status) {
            CanonicalConceptStatus::PENDING => [
                CanonicalConceptStatus::GENERATING,
                CanonicalConceptStatus::FAILED,
            ],
            CanonicalConceptStatus::GENERATING => [
                CanonicalConceptStatus::GENERATED,
                CanonicalConceptStatus::FAILED,
            ],
            CanonicalConceptStatus::GENERATED => [
                CanonicalConceptStatus::VALIDATING,
                CanonicalConceptStatus::FAILED,
            ],
            CanonicalConceptStatus::VALIDATING => [
                CanonicalConceptStatus::VALIDATION_FAILED,
                CanonicalConceptStatus::NORMALIZING,
                CanonicalConceptStatus::FAILED,
            ],
            CanonicalConceptStatus::VALIDATION_FAILED => [
                CanonicalConceptStatus::REPAIRING,
                CanonicalConceptStatus::FAILED,
            ],
            CanonicalConceptStatus::REPAIRING => [
                CanonicalConceptStatus::REPAIRED,
                CanonicalConceptStatus::FAILED,
            ],
            CanonicalConceptStatus::REPAIRED => [
                CanonicalConceptStatus::VALIDATING,
                CanonicalConceptStatus::FAILED,
            ],
            CanonicalConceptStatus::NORMALIZING => [
                CanonicalConceptStatus::NORMALIZED,
                CanonicalConceptStatus::FAILED,
            ],
            CanonicalConceptStatus::NORMALIZED => [
                CanonicalConceptStatus::FREEZING,
                CanonicalConceptStatus::FAILED,
            ],
            CanonicalConceptStatus::FREEZING => [
                CanonicalConceptStatus::FROZEN,
                CanonicalConceptStatus::FAILED,
            ],
            CanonicalConceptStatus::FROZEN,
            CanonicalConceptStatus::FAILED => [],
        };
    }

    /**
     * Hoi ma khong nem.
     *
     * Co cho can quyet dinh chu khong can chan — vi du resume phai biet
     * mot buoc co hop le hay khong truoc khi chon di duong nao. Bat
     * exception de doc mot cau tra loi boolean thi bien luong dieu khien
     * binh thuong thanh su co.
     */
    public function canTransition(
        CanonicalConceptStatus $from,
        CanonicalConceptStatus $to
    ): bool {
        return in_array($to, $this->allowedNext($from), true);
    }

    public function assertCanTransition(
        CanonicalConceptStatus $from,
        CanonicalConceptStatus $to
    ): void {
        if (! $this->canTransition($from, $to)) {
            throw new InvalidCanonicalStateTransition(
                "Invalid canonical concept transition: {$from->value} -> {$to->value}."
            );
        }
    }
}
