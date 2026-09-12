<?php

declare(strict_types=1);

namespace App\Video\Concept\Processing;

use App\Video\Concept\Persistence\Enums\ValidationStage;
use App\Video\Concept\Validation\ValidationError;
use InvalidArgumentException;

/**
 * Mot chang validation da chay, ke lai duoi dang du lieu.
 *
 * Ly do ton tai: persistence can biet DU CA MUOI chang §10.4 da chay tren
 * byte nao, bang validator phien ban nao — nhung validator thi khong duoc
 * biet DB ton tai. Bao cao la thu duy nhat di qua duoc ranh gioi do.
 *
 * documentHash la cua CHINH bytes duoc kiem, khong phai cua canonical cuoi
 * cung: chang 2-5 kiem raw cua model, chang 8-10 kiem bytes da serialize.
 * Hai gia tri nay khac nhau, va su khac nhau do la thong tin.
 */
final class ValidationStageReport
{
    /**
     * @param  list<ValidationError>  $errors
     */
    public function __construct(
        public readonly ValidationStage $stage,
        public readonly bool $passed,
        public readonly string $documentHash,
        public readonly string $validatorVersion,
        public readonly array $errors,
        public readonly int $durationMs,
    ) {
        if ($this->documentHash === '') {
            throw new InvalidArgumentException(
                'documentHash must not be empty.'
            );
        }

        if ($this->validatorVersion === '') {
            throw new InvalidArgumentException(
                'validatorVersion must not be empty.'
            );
        }

        if ($this->durationMs < 0) {
            throw new InvalidArgumentException(
                'durationMs must not be negative.'
            );
        }

        /*
         * Mot chang "passed" ma van kem loi thi mot trong hai la sai, va
         * ta khong biet cai nao — chan tai day thay vi ghi mot dong ledger
         * tu mau thuan roi tin no ve sau.
         */
        if ($this->passed && $this->errors !== []) {
            throw new InvalidArgumentException(
                'A passed validation stage must carry no errors.'
            );
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function errorPayload(): array
    {
        return array_map(
            static fn (ValidationError $error): array => $error->toArray(),
            $this->errors,
        );
    }
}
