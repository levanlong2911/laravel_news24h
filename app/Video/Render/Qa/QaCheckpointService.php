<?php

declare(strict_types=1);

namespace App\Video\Render\Qa;

use App\Models\RenderQaFinding;
use App\Models\RenderQaRun;
use App\Models\VideoRender;
use App\Video\Render\Enums\QaDecision;
use App\Video\Render\Enums\QaStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class QaCheckpointService
{
    public function __construct(
        private readonly QaCostAccountingService $costs,
    ) {}

    public function complete(RenderQaRun $qaRun, array $payload): RenderQaRun
    {
        return DB::transaction(function () use ($qaRun, $payload): RenderQaRun {
            $qaRun = RenderQaRun::query()
                ->whereKey($qaRun->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($qaRun->status === QaStatus::COMPLETED) {
                if (! hash_equals((string) $qaRun->qa_report_hash, $payload['qa_report_hash'])) {
                    throw new RuntimeException('QA replay hash mismatch.');
                }

                return $qaRun;
            }

            if (! hash_equals($qaRun->request_hash, $payload['request_hash'])) {
                throw new RuntimeException('QA request hash mismatch.');
            }

            if (! hash_equals($qaRun->artifact_hash, $payload['artifact_hash'])) {
                throw new RuntimeException('QA artifact hash mismatch.');
            }

            $report = $payload['report'];
            $this->assertReportCounts($report);

            $qaRun->forceFill([
                'status' => QaStatus::COMPLETED,
                'decision' => QaDecision::from($payload['decision']),
                'repair_decision' => $payload['repair_decision'],
                'qa_report_hash' => $payload['qa_report_hash'],
                'vision_provider' => $payload['vision_provider'],
                'vision_model' => $payload['vision_model'],
                'hard_fail_count' => $report['hard_fail_count'],
                'soft_fail_count' => $report['soft_fail_count'],
                'uncertain_count' => $report['uncertain_count'],
                'not_visible_count' => $report['not_visible_count'],
                'report_json' => $report,
                'completed_at' => now(),
            ])->save();

            foreach ($report['findings'] as $finding) {
                RenderQaFinding::query()->updateOrCreate(
                    [
                        'qa_run_id' => $qaRun->id,
                        'target_id' => $finding['target_id'],
                    ],
                    [
                        'semantic_key' => $finding['semantic_key'],
                        'primitive' => $finding['primitive'],
                        'priority' => $finding['priority'],
                        'severity' => $finding['severity'],
                        'status' => $finding['status'],
                        'confidence' => $finding['confidence'],
                        'expected_value' => $finding['expected_value'],
                        'observed_value' => $finding['observed_value'],
                        'evidence' => $finding['evidence'],
                        'failure_type' => $finding['failure_type'],
                        'source_constraint_ids' => $finding['source_constraint_ids'],
                        'source_instruction_ids' => $finding['source_instruction_ids'],
                        'source_paths' => $finding['source_paths'],
                    ],
                );
            }

            $this->costs->record($qaRun, $payload['usage'] ?? []);

            VideoRender::query()
                ->whereKey($qaRun->render_id)
                ->update([
                    'qa_status' => $qaRun->decision === QaDecision::PASS ? 'passed' : $qaRun->decision->value,
                    'qa_approved' => $qaRun->decision === QaDecision::PASS,
                    'latest_qa_run_id' => $qaRun->id,
                ]);

            return $qaRun;
        });
    }

    private function assertReportCounts(array $report): void
    {
        $findings = collect($report['findings'] ?? []);

        $actualHardFailCount = $findings
            ->where('status', 'fail')
            ->where('severity', 'hard')
            ->count();

        if ($actualHardFailCount !== $report['hard_fail_count']) {
            throw new RuntimeException('QA report hard fail count mismatch.');
        }

        $actualSoftFailCount = $findings
            ->where('status', 'fail')
            ->where('severity', 'soft')
            ->count();

        if ($actualSoftFailCount !== $report['soft_fail_count']) {
            throw new RuntimeException('QA report soft fail count mismatch.');
        }

        $actualUncertainCount = $findings
            ->where('status', 'uncertain')
            ->count();

        if ($actualUncertainCount !== $report['uncertain_count']) {
            throw new RuntimeException('QA report uncertain count mismatch.');
        }

        $actualNotVisibleCount = $findings
            ->where('status', 'not_visible')
            ->count();

        if ($actualNotVisibleCount !== $report['not_visible_count']) {
            throw new RuntimeException('QA report not visible count mismatch.');
        }
    }
}
