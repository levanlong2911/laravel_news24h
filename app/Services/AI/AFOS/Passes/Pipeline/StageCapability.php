<?php

namespace App\Services\AI\AFOS\Passes\Pipeline;

/**
 * StageCapability — orthogonal scheduler and optimizer flags.
 *
 * Each case is independent (a stage can be PURE + CACHEABLE + CPU_INTENSIVE).
 * Scheduler / optimizer queries: $meta->hasCapability(StageCapability::PARALLEL_SAFE).
 *
 * Scheduling contract:
 *   PURE + DETERMINISTIC → safe to memoise (fingerprint → cache)
 *   PARALLEL_SAFE        → no shared-write conflict, schedulable alongside siblings
 *   IO_BOUND             → async-executor candidate (Fiber, Amp, ReactPHP)
 *   SIDE_EFFECT          → must execute in declared order, never skip
 *   MODEL_INFERENCE      → expensive, rate-limited — respect concurrency budget
 *
 * Groupings for optimizer:
 *   {PURE, DETERMINISTIC, READ_ONLY}         — validation stages
 *   {PURE, CACHEABLE, DETERMINISTIC, WRITE_IR} — transform stages
 *   {PURE, CACHEABLE, DETERMINISTIC, WRITE_IR} — serialization stages
 */
enum StageCapability: string
{
    // ── Core semantics ───────────────────────────────────────────────────────

    /** No side effects — same inputs always produce the same output. */
    case PURE            = 'pure';
    /** Output can be memoised given the same StageFingerprint hash. */
    case CACHEABLE       = 'cacheable';
    /** Stronger than PURE: output is bit-identical across environments. */
    case DETERMINISTIC   = 'deterministic';

    // ── Resource profile ─────────────────────────────────────────────────────

    /** Stage is CPU-bound (heavy computation, good for profiling). */
    case CPU_INTENSIVE   = 'cpu_intensive';
    /** Stage makes network / disk I/O calls (async-executor candidate). */
    case IO_BOUND        = 'io_bound';
    /** Stage calls an AI model (expensive, rate-limited, may be async). */
    case MODEL_INFERENCE = 'model_inference';
    /** Stage requires network access (subset of IO_BOUND, for firewall rules). */
    case NETWORK         = 'network';
    /** Stage benefits from GPU acceleration. */
    case GPU             = 'gpu';

    // ── Scheduling safety ────────────────────────────────────────────────────

    /** Stage can run concurrently with other PARALLEL_SAFE stages safely. */
    case PARALLEL_SAFE   = 'parallel_safe';
    /** Stage writes external state — must run in declared order, never skip. */
    case SIDE_EFFECT     = 'side_effect';
    /** Should not run more than once per pipeline (e.g. payments, notifics). */
    case NON_REPEATABLE  = 'non_repeatable';

    // ── IR access pattern ────────────────────────────────────────────────────

    /** Only reads PipelineState; does not produce new IR. */
    case READ_ONLY       = 'read_only';
    /** Writes at least one IR artifact to PipelineState. */
    case WRITE_IR        = 'write_ir';
    /** Writes to external storage or services (logs, DB, cache). */
    case WRITE_EXTERNAL  = 'write_external';

    // ── Pipeline lifecycle ───────────────────────────────────────────────────

    /**
     * Transitions graph state rather than transforming its content.
     * The optimizer must never reorder, skip, or parallelize a FREEZE stage —
     * all writers must complete before it runs, all readers must run after.
     */
    case FREEZE          = 'freeze';
}
