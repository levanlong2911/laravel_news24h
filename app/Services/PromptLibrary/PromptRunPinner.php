<?php

namespace App\Services\PromptLibrary;

use App\Models\PromptLibrary\PromptRunPin;

/**
 * Ghi revision ma mot execution da dung. Goi BEN TRONG transaction claim cua
 * noi goi: pin loi thi claim cung rollback. Execution duoc tai su dung (stage
 * running het lease) thi bo pin cu thay bang bo moi. alias = consumer, de mot
 * stage dung nhieu prompt (vi du anchor geometry + template) van tach duoc.
 */
final class PromptRunPinner
{
    public function pin(string $executionType, string $executionId, ResolvedPrompt $prompt): void
    {
        $this->pinAll($executionType, $executionId, [$prompt]);
    }

    /** @param list<ResolvedPrompt> $prompts */
    public function pinAll(string $executionType, string $executionId, array $prompts): void
    {
        PromptRunPin::query()
            ->where('execution_type', $executionType)
            ->where('execution_id', $executionId)
            ->delete();

        $order = 0;

        foreach ($prompts as $prompt) {
            foreach ($prompt->pins() as $pin) {
                PromptRunPin::create([
                    'execution_type' => $executionType,
                    'execution_id' => $executionId,
                    'binding_id' => $pin['binding_id'],
                    'document_id' => $pin['document_id'],
                    'revision_id' => $pin['revision_id'],
                    'alias' => $prompt->consumerKey,
                    'sort_order' => $order++,
                    'composition_hash' => $prompt->compositionHash,
                    'compiler_version' => $prompt->compilerVersion,
                    'pinned_at' => now(),
                ]);
            }
        }
    }
}
