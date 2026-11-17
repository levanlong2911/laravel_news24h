<?php

declare(strict_types=1);

namespace App\Video\Script;

/**
 * A script workflow whose later steps read the approved document through derived data of the run:
 * the answer stays as the model wrote it, and every consumer receives the same projection of it.
 */
interface ProjectsScriptDocument
{
    /**
     * @param  array<string, mixed>  $document  the document as the model returned it
     * @param  array<string, mixed>  $derived  the derived data of the run, checked against the stored copy
     * @return array<string, mixed> the document the later steps read
     */
    public function consumerDocument(array $document, array $derived): array;

    /**
     * @param  array<string, mixed>  $context  the context the run pinned
     * @return array<string, mixed> the validation policy the run pinned, for the steps that read it to pin in turn
     */
    public function validation(array $context): array;

    /**
     * @param  array<string, mixed>  $context  the context of a run being made
     * @return array<string, mixed> the fields the run adds to its input for the policy it pins, so a run checked by other rules is never served for it; empty when it pins none
     */
    public function validationInput(array $context): array;
}
