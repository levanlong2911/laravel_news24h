<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * Generates all AFOS typed artifacts (PHP enums + readonly classes, Python dataclasses)
 * from a single canonical schema: resources/afos/schema.yaml
 *
 * Single source of truth — edit the YAML, regenerate, never hand-edit generated files.
 * Run: php artisan afos:generate
 */
class AfosGenerate extends Command
{
    protected $signature   = 'afos:generate {--dry-run : Show what would be generated without writing files}';
    protected $description = 'Generate typed AFOS artifacts from resources/afos/schema.yaml (PHP + Python)';

    private array  $allEnumNames    = [];
    private array  $schemaEnums     = [];
    private array  $schemaArtifacts = [];
    private string $phpNsRoot;
    private string $phpTypesDir;
    private string $pyOutputDir;
    private bool   $dryRun         = false;
    private int    $fileCount       = 0;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        $schemaPath = resource_path('afos/schema.yaml');
        if (! file_exists($schemaPath)) {
            $this->error("Schema not found: {$schemaPath}");
            $this->line("Create it at: resources/afos/schema.yaml");
            return self::FAILURE;
        }

        $schema = Yaml::parseFile($schemaPath);

        $this->allEnumNames    = array_keys($schema['enums'] ?? []);
        $this->schemaEnums     = $schema['enums']     ?? [];
        $this->schemaArtifacts = $schema['artifacts'] ?? [];
        $this->phpNsRoot       = $schema['php_namespace_root'] ?? 'App\\Services\\AI\\AFOS';
        $this->phpTypesDir     = base_path($schema['php_types_dir'] ?? 'app/Services/AI/AFOS/Types');
        $rawPyDir              = $schema['python_output_dir'] ?? '../media_runtime/afos';
        // Support both absolute paths (D:/...) and relative paths (../...)
        $this->pyOutputDir     = preg_match('/^[A-Za-z]:/', $rawPyDir) ? $rawPyDir : base_path($rawPyDir);

        if ($this->dryRun) {
            $this->warn('DRY RUN — no files will be written');
        }

        $this->newLine();
        $this->line('<fg=cyan>── PHP Enums ─────────────────────────────────────────────</>');
        $this->generatePhpEnums();

        $this->newLine();
        $this->line('<fg=cyan>── PHP Artifacts ─────────────────────────────────────────</>');
        $this->generatePhpArtifacts();

        $this->newLine();
        $this->line('<fg=cyan>── Python ────────────────────────────────────────────────</>');
        $this->generatePythonTypes();
        $this->generatePythonArtifacts();

        $this->newLine();
        $this->info("{$this->fileCount} files " . ($this->dryRun ? 'would be' : '') . " generated from resources/afos/schema.yaml");
        return self::SUCCESS;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PHP GENERATION
    // ══════════════════════════════════════════════════════════════════════════

    private function generatePhpEnums(): void
    {
        $this->ensureDir($this->phpTypesDir);

        foreach ($this->schemaEnums as $name => $values) {
            $cases = implode("\n", array_map(
                fn($v) => "    case {$v} = '" . strtolower($v) . "';",
                $values
            ));

            $src = $this->phpBanner()
                . "namespace {$this->phpNsRoot}\\Types;\n\n"
                . "enum {$name}: string\n{\n{$cases}\n}\n";

            $this->emit("{$this->phpTypesDir}/{$name}.php", $src, "Types/{$name}");
        }
    }

    private function generatePhpArtifacts(): void
    {
        foreach ($this->schemaArtifacts as $tier => $artifacts) {
            $tierPascal = ucfirst($tier);
            $dir        = base_path("app/Services/AI/AFOS/{$tierPascal}");
            $this->ensureDir($dir);

            foreach ($artifacts as $name => $config) {
                $fields = $this->normalizeFields($config['fields']);
                $uses   = $this->phpUseBlock($fields);

                $src = $this->phpBanner()
                    . "namespace {$this->phpNsRoot}\\{$tierPascal};\n\n"
                    . $uses
                    . "final class {$name}\n{\n"
                    . "    public function __construct(\n"
                    . $this->phpConstructorParams($fields)
                    . "\n    ) {}\n\n"
                    . $this->phpFromArray($name, $fields) . "\n\n"
                    . $this->phpToArray($fields)
                    . "}\n";

                $this->emit("{$dir}/{$name}.php", $src, "{$tierPascal}/{$name}");
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PYTHON GENERATION
    // ══════════════════════════════════════════════════════════════════════════

    private function generatePythonTypes(): void
    {
        $this->ensureDir($this->pyOutputDir);

        $lines = [
            'from __future__ import annotations',
            'from enum import Enum',
            '',
        ];

        foreach ($this->schemaEnums as $name => $values) {
            $lines[] = '';
            $lines[] = "class {$name}(str, Enum):";
            foreach ($values as $v) {
                $lines[] = "    {$v} = '" . strtolower($v) . "'";
            }
        }

        $this->emit(
            "{$this->pyOutputDir}/types.py",
            $this->pyBanner() . implode("\n", $lines) . "\n",
            'types.py'
        );
    }

    private function generatePythonArtifacts(): void
    {
        $this->emit("{$this->pyOutputDir}/__init__.py", '', '__init__.py');

        foreach ($this->schemaArtifacts as $tier => $artifacts) {
            $lines = [
                'from __future__ import annotations',
                'from dataclasses import dataclass',
                'from typing import Optional',
                'from .types import *',
                '',
            ];

            foreach ($artifacts as $name => $config) {
                $fields = $this->normalizeFields($config['fields']);
                $lines  = array_merge($lines, $this->pyClassLines($name, $fields));
            }

            $this->emit(
                "{$this->pyOutputDir}/{$tier}.py",
                $this->pyBanner() . implode("\n", $lines) . "\n",
                "{$tier}.py"
            );
        }
    }

    private function pyClassLines(string $name, array $fields): array
    {
        $lines = ['', "@dataclass(frozen=True)", "class {$name}:"];

        foreach ($fields as $fname => $fcfg) {
            $lines[] = '    ' . $this->toSnake($fname) . ': ' . $this->pyType($fcfg);
        }

        // from_dict
        $lines[] = '';
        $lines[] = '    @classmethod';
        $lines[] = "    def from_dict(cls, d: dict) -> '{$name}':";
        $lines[] = '        return cls(';
        foreach ($fields as $fname => $fcfg) {
            $lines[] = '            ' . $this->pyFromDictExpr($fname, $fcfg) . ',';
        }
        $lines[] = '        )';

        // to_dict
        $lines[] = '';
        $lines[] = '    def to_dict(self) -> dict:';
        $lines[] = '        return {';
        foreach ($fields as $fname => $fcfg) {
            $snake   = $this->toSnake($fname);
            $valExpr = $this->pyToDictExpr($snake, $fcfg);
            $lines[] = "            '{$fname}': {$valExpr},";
        }
        $lines[] = '        }';

        return $lines;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PHP SNIPPET BUILDERS
    // ══════════════════════════════════════════════════════════════════════════

    private function phpUseBlock(array $fields): string
    {
        $uses = [];
        foreach ($fields as $fcfg) {
            if ($this->isEnum($fcfg['type'])) {
                $t        = $fcfg['type'];
                $uses[$t] = "use {$this->phpNsRoot}\\Types\\{$t};";
            }
        }
        return $uses ? implode("\n", $uses) . "\n\n" : '';
    }

    private function phpConstructorParams(array $fields): string
    {
        return implode("\n", array_map(
            fn($name, $fcfg) => "        public readonly {$this->phpType($fcfg)} \${$name},",
            array_keys($fields),
            $fields
        ));
    }

    private function phpType(array $fcfg): string
    {
        if ($fcfg['list']) {
            return 'array';
        }
        $base = match ($fcfg['type']) {
            'string', 'int', 'float', 'bool' => $fcfg['type'],
            default                           => $fcfg['type'],
        };
        return ($fcfg['nullable'] ? '?' : '') . $base;
    }

    private function phpFromArray(string $_, array $fields): string
    {
        $lines   = ["    public static function fromArray(array \$data): self", '    {', '        return new self('];
        foreach ($fields as $name => $fcfg) {
            $lines[] = "            {$name}: {$this->phpFromArrayExpr($name, $fcfg)},";
        }
        $lines[] = '        );';
        $lines[] = '    }';
        return implode("\n", $lines);
    }

    private function phpFromArrayExpr(string $name, array $fcfg): string
    {
        ['type' => $type, 'nullable' => $nullable, 'list' => $list] = $fcfg;
        $isEnum = $this->isEnum($type);

        if ($list && $isEnum)  return "array_map(fn(\$v) => {$type}::from(\$v), \$data['{$name}'] ?? [])";
        if ($list)             return "\$data['{$name}'] ?? []";
        if ($isEnum && $nullable) return "isset(\$data['{$name}']) ? {$type}::from(\$data['{$name}']) : null";
        if ($isEnum)           return "{$type}::from(\$data['{$name}'])";
        if ($nullable)         return "\$data['{$name}'] ?? null";
        return "\$data['{$name}']";
    }

    private function phpToArray(array $fields): string
    {
        $lines = ['    public function toArray(): array', '    {', '        return ['];
        foreach ($fields as $name => $fcfg) {
            $lines[] = "            '{$name}' => {$this->phpToArrayExpr($name, $fcfg)},";
        }
        $lines[] = '        ];';
        $lines[] = '    }';
        return implode("\n", $lines);
    }

    private function phpToArrayExpr(string $name, array $fcfg): string
    {
        ['nullable' => $nullable, 'list' => $list] = $fcfg;
        $isEnum = $this->isEnum($fcfg['type']);

        if ($list && $isEnum)     return "array_map(fn(\$v) => \$v->value, \$this->{$name})";
        if ($isEnum && $nullable) return "\$this->{$name}?->value";
        if ($isEnum)              return "\$this->{$name}->value";
        return "\$this->{$name}";
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PYTHON SNIPPET BUILDERS
    // ══════════════════════════════════════════════════════════════════════════

    private function pyType(array $fcfg): string
    {
        $base = $this->pyBaseType($fcfg['type']);
        if ($fcfg['list'])     return "list[{$base}]";
        if ($fcfg['nullable']) return "Optional[{$base}]";
        return $base;
    }

    private function pyBaseType(string $type): string
    {
        return match ($type) {
            'string' => 'str',
            'int'    => 'int',
            'float'  => 'float',
            'bool'   => 'bool',
            default  => $type,
        };
    }

    private function pyFromDictExpr(string $name, array $fcfg): string
    {
        $snake    = $this->toSnake($name);
        $isEnum   = $this->isEnum($fcfg['type']);
        $nullable = $fcfg['nullable'];
        $list     = $fcfg['list'];
        $type     = $fcfg['type'];

        if ($list && $isEnum)     return "{$snake}=[{$type}(v) for v in d.get('{$name}', [])]";
        if ($list)                return "{$snake}=d.get('{$name}', [])";
        if ($isEnum && $nullable) return "{$snake}={$type}(d['{$name}']) if d.get('{$name}') else None";
        if ($isEnum)              return "{$snake}={$type}(d['{$name}'])";
        if ($nullable)            return "{$snake}=d.get('{$name}')";
        return "{$snake}=d['{$name}']";
    }

    private function pyToDictExpr(string $snake, array $fcfg): string
    {
        $isEnum   = $this->isEnum($fcfg['type']);
        $nullable = $fcfg['nullable'];
        $list     = $fcfg['list'];

        if ($list && $isEnum)     return "[v.value for v in self.{$snake}]";
        if ($isEnum && $nullable) return "self.{$snake}.value if self.{$snake} else None";
        if ($isEnum)              return "self.{$snake}.value";
        return "self.{$snake}";
    }

    // ══════════════════════════════════════════════════════════════════════════
    // UTILITIES
    // ══════════════════════════════════════════════════════════════════════════

    private function normalizeFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $name => $def) {
            $out[$name] = is_string($def)
                ? ['type' => $def, 'nullable' => false, 'list' => false]
                : array_merge(['nullable' => false, 'list' => false], $def);
        }
        return $out;
    }

    private function isEnum(string $type): bool
    {
        return in_array($type, $this->allEnumNames, true);
    }

    private function toSnake(string $camel): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $camel));
    }

    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function emit(string $path, string $content, string $label): void
    {
        $this->fileCount++;
        if ($this->dryRun) {
            $this->line("  → {$label}");
            return;
        }
        file_put_contents($path, $content);
        $this->line("  <fg=green>✓</> {$label}");
    }

    private function phpBanner(): string
    {
        return implode("\n", [
            '<?php',
            '',
            '// ┌─────────────────────────────────────────────────────────────────┐',
            '// │ AUTO-GENERATED — do not edit this file manually                 │',
            '// │ Source: resources/afos/schema.yaml                              │',
            '// │ Regenerate: php artisan afos:generate                           │',
            '// └─────────────────────────────────────────────────────────────────┘',
            '',
            '',
        ]);
    }

    private function pyBanner(): string
    {
        return implode("\n", [
            '# ┌─────────────────────────────────────────────────────────────────┐',
            '# │ AUTO-GENERATED — do not edit this file manually                 │',
            '# │ Source: resources/afos/schema.yaml                              │',
            '# │ Regenerate: php artisan afos:generate                           │',
            '# └─────────────────────────────────────────────────────────────────┘',
            '',
            '',
        ]);
    }
}
