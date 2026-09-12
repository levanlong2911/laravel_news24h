<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical;

use InvalidArgumentException;

final class DesignIdentity
{
    /**
     * @param  list<string>  $identityBasis
     * @param  list<string>  $finishIdentityBasis
     */
    public function __construct(
        public readonly string $subjectClass,
        public readonly array $identityBasis,
        public readonly array $finishIdentityBasis = [],
    ) {
        if (trim($subjectClass) === '') {
            throw new InvalidArgumentException(
                'identity.subject_class must not be empty.'
            );
        }

        if ($identityBasis === []) {
            throw new InvalidArgumentException(
                'identity.identity_basis must contain at least one item.'
            );
        }

        if (
            count($identityBasis)
            !== count(array_unique($identityBasis))
        ) {
            throw new InvalidArgumentException(
                'identity.identity_basis must contain unique items.'
            );
        }

        foreach ($identityBasis as $index => $item) {
            if (
                ! is_string($item)
                || trim($item) === ''
            ) {
                throw new InvalidArgumentException(
                    "identity.identity_basis[{$index}] "
                    .'must be a non-empty string.'
                );
            }
        }

        if (
            count($finishIdentityBasis)
            !== count(array_unique($finishIdentityBasis))
        ) {
            throw new InvalidArgumentException(
                'identity.finish_identity_basis must contain unique items.'
            );
        }

        foreach ($finishIdentityBasis as $index => $item) {
            if (
                ! is_string($item)
                || trim($item) === ''
            ) {
                throw new InvalidArgumentException(
                    "identity.finish_identity_basis[{$index}] "
                    .'must be a non-empty string.'
                );
            }
        }
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $basis = $data['identity_basis'] ?? [];

        if (
            ! is_array($basis)
            || ! array_is_list($basis)
        ) {
            throw new InvalidArgumentException(
                'identity.identity_basis must be a list.'
            );
        }

        $finish = $data['finish_identity_basis'] ?? [];

        if (
            ! is_array($finish)
            || ! array_is_list($finish)
        ) {
            throw new InvalidArgumentException(
                'identity.finish_identity_basis must be a list.'
            );
        }

        return new self(
            subjectClass: (string) ($data['subject_class'] ?? ''),

            identityBasis: array_values($basis),

            finishIdentityBasis: array_values($finish),
        );
    }

    /**
     * @return array{
     *     subject_class:string,
     *     identity_basis:list<string>,
     *     finish_identity_basis:list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'subject_class' => $this->subjectClass,

            'identity_basis' => array_values($this->identityBasis),

            'finish_identity_basis' => array_values($this->finishIdentityBasis),
        ];
    }
}
