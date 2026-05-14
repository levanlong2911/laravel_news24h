<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NAME_RULE = "  - proper names: never expand, alter, or substitute a name not in the source\n"
        . "    (e.g. source says 'Wilson' → write 'Wilson', not 'Russell Wilson' or any other Wilson)\n"
        . "    (e.g. source says 'Payton Wilson' → never change to 'Russell Wilson' or any other person)";

    public function up(): void
    {
        $frameworks = DB::table('prompt_frameworks')->get();

        foreach ($frameworks as $fw) {
            $phase3 = $fw->phase3_generate;

            $needle = "  - locations or event names";
            if (!str_contains($phase3, $needle)) continue;
            if (str_contains($phase3, 'proper names: never expand')) continue;

            $updated = str_replace(
                $needle,
                $needle . "\n" . self::NAME_RULE,
                $phase3
            );

            DB::table('prompt_frameworks')
                ->where('id', $fw->id)
                ->update(['phase3_generate' => $updated]);
        }
    }

    public function down(): void
    {
        $frameworks = DB::table('prompt_frameworks')->get();

        foreach ($frameworks as $fw) {
            $updated = str_replace("\n" . self::NAME_RULE, '', $fw->phase3_generate);
            DB::table('prompt_frameworks')
                ->where('id', $fw->id)
                ->update(['phase3_generate' => $updated]);
        }
    }
};
