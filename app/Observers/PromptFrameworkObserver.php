<?php

namespace App\Observers;

use App\Models\PromptFramework;
use App\Models\PromptVersion;

class PromptFrameworkObserver
{
    /**
     * Auto backup trước khi update — không bao giờ mất bản cũ.
     */
    public function updating(PromptFramework $framework): void
    {
        // Guard: chỉ backup khi thay đổi thực sự ở prompt fields
        if (!$framework->isDirty(['system_prompt', 'phase1_analyze', 'phase2_diagnose', 'phase3_generate'])) {
            return;
        }

        PromptVersion::create([
            'framework_id' => $framework->id,
            'snapshot'     => $framework->getOriginal(), // lưu bản CŨ trước khi ghi đè
            'change_note'  => 'auto-backup v' . $framework->version,
        ]);

        // Tăng version number
        $framework->version = $framework->version + 1;
    }
}
