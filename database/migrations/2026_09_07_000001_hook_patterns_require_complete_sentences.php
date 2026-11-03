<?php

use App\Models\PromptFramework;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const REPLACEMENTS = [
        [
            "Rewrite it.\n\nA) SHOCK STAT",
            "Rewrite it.\nWrite the hook in complete sentences. A fragment with no verb is not a hook.\n\nA) SHOCK STAT",
        ],
        [
            "   Two facts. Instant gap. Short sentences. No connectors between them.",
            "   Two facts, one gap. No connectors between them.",
        ],
        [
            "   Good: \"January: MVP front-runner. March: on waivers.\"",
            "   Bad:  \"January: MVP front-runner. March: on waivers.\"\n   Good: \"In January he led the MVP race. By March he was on waivers.\"",
        ],
        [
            "   Good: \"\$5.8M on the table. Zero takers. Aubrey stays.\"",
            "   Good: \"Aubrey had \$5.8M on the table and not one team made an offer.\"",
        ],
        [
            "   Good: \"'I want to be here long-term.' Three months later, no extension.\"",
            "   Good: \"'I want to be here long-term.' Three months later he still has no extension.\"",
        ],
    ];

    public function up(): void
    {
        $this->apply(self::REPLACEMENTS);
    }

    public function down(): void
    {
        $this->apply(array_map(static fn (array $pair): array => [$pair[1], $pair[0]], self::REPLACEMENTS));
    }

    private function apply(array $replacements): void
    {
        foreach (PromptFramework::all() as $framework) {
            $text = $framework->phase3_generate;

            foreach ($replacements as [$old, $new]) {
                if (substr_count($text, $old) !== 1) {
                    throw new RuntimeException(sprintf(
                        '[%s] khong tim thay dung 1 lan chuoi: %s',
                        $framework->name,
                        str_replace("\n", '\n', mb_substr($old, 0, 70))
                    ));
                }

                $text = str_replace($old, $new, $text);
            }

            $framework->phase3_generate = $text;
            $framework->save();
        }
    }
};
