<?php

namespace App\Services\AI\Contracts;

interface SchemaValidator
{
    /**
     * Validate $data against the named schema.
     *
     * @param  array  $data        Already-decoded PHP array (from json_decode(..., true))
     * @param  string $schemaName  Schema file stem, e.g. "Story" → contracts/v1/Story.schema.json
     */
    public function validate(array $data, string $schemaName): ValidationResult;
}
