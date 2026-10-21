<?php

namespace App\Console\Commands;

use App\Video\Superyacht\Validation\DocumentValidator;
use App\Video\Superyacht\Validation\Invariants\ArrayInvariantContext;
use Illuminate\Console\Command;

/**
 * Thay cho FixturesTest cua starter (du an khong giu thu muc tests). Moi file
 * trong resources/ai/superyacht/schemas/fixtures/<Schema>/ phai cu xu dung nhu ten:
 *   valid_*               qua schema va rang buoc
 *   invalid_schema_*      truot JSON Schema
 *   invalid_inv_<CODE>_*  qua schema, truot dung rang buoc <CODE>
 */
class VideoSpecCheckFixtures extends Command
{
    private const EXPECTED_INVARIANTS = [
        'INV-CD-01', 'INV-CD-02', 'INV-CD-03', 'INV-BS-01', 'INV-BS-02', 'INV-BS-03', 'INV-BS-04',
        'INV-CN-01', 'INV-CN-02', 'INV-CN-03', 'INV-SS-01', 'INV-SS-02', 'INV-SS-03', 'INV-SS-04',
        'INV-CS-01', 'INV-CS-02', 'INV-CS-03', 'INV-RR-01', 'INV-RR-02', 'INV-RR-03', 'INV-LK-01', 'INV-RD-01',
    ];

    protected $signature = 'video:spec-check-fixtures';

    protected $description = 'Chay toan bo fixture cua 8 schema va 22 rang buoc v6.3';

    public function handle(DocumentValidator $validator): int
    {
        $dir = resource_path('ai/superyacht/schemas/fixtures');
        $ctx = new ArrayInvariantContext(json_decode((string) file_get_contents($dir.'/context.json'), true, 512, JSON_THROW_ON_ERROR));
        $failures = [];
        $covered = [];
        $files = glob($dir.'/*/*.json') ?: [];

        foreach ($files as $file) {
            $schema = basename(dirname($file));
            $name = basename($file, '.json');
            $problem = $this->check($validator, $ctx, $schema, $name, (string) file_get_contents($file));

            if (preg_match('/^invalid_inv_(INV-[A-Z]{2}-\d{2})_/', $name, $m)) {
                $covered[$m[1]] = true;
            }

            if ($problem !== null) {
                $failures[] = "{$schema}/{$name}: {$problem}";
            }
        }

        foreach (array_diff(self::EXPECTED_INVARIANTS, array_keys($covered)) as $code) {
            $failures[] = "{$code}: khong co fixture nao lam truot rang buoc nay";
        }

        foreach ($failures as $failure) {
            $this->line("  <fg=red>x</> {$failure}");
        }

        $this->line(sprintf('%d fixture, %d/%d rang buoc co fixture truot.', count($files), count($covered), count(self::EXPECTED_INVARIANTS)));

        if ($failures !== []) {
            $this->error(count($failures).' sai lech.');

            return self::FAILURE;
        }

        $this->info('Moi fixture cu xu dung nhu ten.');

        return self::SUCCESS;
    }

    private function check(DocumentValidator $validator, ArrayInvariantContext $ctx, string $schema, string $name, string $json): ?string
    {
        $schemaReport = $validator->schemas()->validate($schema, $json);

        if (str_starts_with($name, 'invalid_schema_')) {
            return $schemaReport->fails() ? null : 'le ra phai truot schema';
        }

        if ($schemaReport->fails()) {
            return 'le ra phai qua schema: '.implode('; ', $schemaReport->messages());
        }

        $report = $validator->validate($schema, $json, $ctx);

        if (str_starts_with($name, 'valid_')) {
            return $report->passes() ? null : 'le ra phai qua: '.implode('; ', $report->messages());
        }

        if (! preg_match('/^invalid_inv_(INV-[A-Z]{2}-\d{2})_/', $name, $m)) {
            return 'ten file khong theo quy uoc';
        }

        return in_array($m[1], $report->codes(), true)
            ? null
            : "le ra phai truot {$m[1]}; nhan duoc: ".implode('; ', $report->messages());
    }
}
