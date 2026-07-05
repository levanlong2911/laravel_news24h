<?php

namespace App\Services\AI\AFOS\Observability;

use Illuminate\Support\Facades\Log;

/**
 * TraceCollector — IR Trace Mode infrastructure.
 *
 * When AFOS_TRACE=true, dumps every IR artifact at each pipeline stage to
 * storage/app/afos-traces/{shot_id}/ as numbered JSON files:
 *
 *   001_shot_goal_ir.json
 *   002_intent.json
 *   003_director_profile.json
 *   004_composition_ir.json
 *   005_camera_ir.json
 *   006_backend_prompt.json
 *   _manifest.json
 *
 * This enables fault isolation: "Composition IR looks correct → bug is in
 * Camera Pass, not in Planning". Same principle as LLVM -print-after-all.
 *
 * NOT a module. NOT a new artifact. Pure observability — disabled in prod.
 *
 * PassExecution record structure (for future Experience Engine integration):
 * {
 *   pass_name: string
 *   input_hash: string (sha256 of input IR serialized)
 *   output_hash: string (sha256 of output IR serialized)
 *   parameters: {key: value}  ← tunable by Experience Engine
 *   duration_ms: float
 *   qa_delta: float|null      ← filled post-render by QA pipeline
 * }
 */
final class TraceCollector
{
    private array $steps     = [];
    private array $passes    = [];
    private float $startedAt;
    private int   $seq       = 0;

    public function __construct(private readonly string $shotId) {
        $this->startedAt = microtime(true);
    }

    /**
     * Record an IR artifact at a pipeline stage.
     *
     * @param string $name  e.g. 'shot_goal_ir', 'composition_ir', 'backend_prompt'
     * @param array  $data  the artifact's toArray() payload
     */
    public function record(string $name, array $data): void
    {
        $this->seq++;
        $this->steps[] = [
            'seq'          => $this->seq,
            'name'         => $name,
            'elapsed_ms'   => $this->elapsedMs(),
            'data'         => $data,
        ];
    }

    /**
     * Record a pass execution (input IR → output IR transform).
     * Used by Optimization Passes when they are implemented as discrete classes.
     *
     * @param string $passName   e.g. 'AttentionPass', 'CompositionPass'
     * @param array  $inputIR    the IR array fed into the pass
     * @param array  $outputIR   the IR array produced by the pass
     * @param array  $parameters the tunable parameters used by this pass
     */
    public function recordPass(string $passName, array $inputIR, array $outputIR, array $parameters = []): void
    {
        $this->passes[] = [
            'pass_name'    => $passName,
            'input_hash'   => substr(hash('sha256', json_encode($inputIR)),  0, 12),
            'output_hash'  => substr(hash('sha256', json_encode($outputIR)), 0, 12),
            'parameters'   => $parameters,
            'duration_ms'  => $this->elapsedMs(),
            'qa_delta'     => null,  // filled post-render by QA pipeline
        ];
    }

    /**
     * Flush all collected trace data to disk.
     * Writes numbered artifact files + _manifest.json.
     */
    /** Return the data array for a recorded step by name, or [] if not found. */
    public function getData(string $name): array
    {
        foreach ($this->steps as $step) {
            if ($step['name'] === $name) {
                return $step['data'];
            }
        }
        return [];
    }

    public function flush(): void
    {
        $dir = storage_path("app/afos-traces/{$this->shotId}");

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            Log::error("[AFOS trace] Cannot create trace dir: {$dir}");
            return;
        }

        foreach ($this->steps as $step) {
            $filename = sprintf('%03d_%s.json', $step['seq'], $step['name']);
            file_put_contents(
                "{$dir}/{$filename}",
                json_encode($step['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        if (!empty($this->passes)) {
            file_put_contents(
                "{$dir}/_passes.json",
                json_encode($this->passes, JSON_PRETTY_PRINT)
            );
        }

        $manifest = [
            'shot_id'           => $this->shotId,
            'generated_at'      => now()->toIso8601String(),
            'total_duration_ms' => $this->elapsedMs(),
            'pipeline'          => 'AFOS-v1.0',
            'steps'             => array_map(fn($s) => [
                'seq'        => $s['seq'],
                'name'       => $s['name'],
                'elapsed_ms' => $s['elapsed_ms'],
            ], $this->steps),
            'passes_recorded'   => count($this->passes),
        ];

        file_put_contents(
            "{$dir}/_manifest.json",
            json_encode($manifest, JSON_PRETTY_PRINT)
        );

        Log::info("[AFOS trace] Shot {$this->shotId} — {$this->seq} steps, {$this->elapsedMs()}ms", [
            'dir' => $dir,
        ]);
    }

    private function elapsedMs(): float
    {
        return round((microtime(true) - $this->startedAt) * 1000, 2);
    }
}
