<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\ClaudeUsageLog;
use App\Models\PromptMetric;
use Illuminate\Http\Request;

class ClaudeUsageController extends Controller
{
    public function index(Request $request)
    {
        $month   = $request->input('month', now()->format('Y-m'));
        $date    = $request->input('date');   // ngày cụ thể, format Y-m-d
        $adminId = $request->input('admin_id');

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }
        // Nếu chọn ngày cụ thể thì month bám theo ngày đó
        if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $month = substr($date, 0, 7);
        }
        [$year, $mon] = explode('-', $month);

        // ── ClaudeUsageLog (manual actions) ───────────────────────────────────
        $logQuery = ClaudeUsageLog::whereYear('created_at', $year)
            ->whereMonth('created_at', $mon)
            ->orderByDesc('created_at');

        if ($adminId) $logQuery->where('admin_id', $adminId);
        if ($date)    $logQuery->whereDate('created_at', $date);

        $logs   = $logQuery->paginate(50)->withQueryString();
        $admins = Admin::orderBy('name')->get(['id', 'name']);

        $dailySummary = ClaudeUsageLog::selectRaw('DATE(created_at) as day, admin_id, COUNT(*) as total')
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $mon)
            ->when($adminId, fn($q) => $q->where('admin_id', $adminId))
            ->when($date,    fn($q) => $q->whereDate('created_at', $date))
            ->groupBy('day', 'admin_id')
            ->orderByDesc('day')
            ->get()
            ->groupBy('day');

        $monthlySummary = ClaudeUsageLog::selectRaw('admin_id, COUNT(*) as total')
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $mon)
            ->when($adminId, fn($q) => $q->where('admin_id', $adminId))
            ->when($date,    fn($q) => $q->whereDate('created_at', $date))
            ->groupBy('admin_id')
            ->orderByDesc('total')
            ->get();

        $grandTotal = $monthlySummary->sum('total');

        // ── PromptMetric — token cost stats ───────────────────────────────────
        $metricBase = PromptMetric::whereYear('created_at', $year)
            ->whereMonth('created_at', $mon)
            ->when($date, fn($q) => $q->whereDate('created_at', $date));

        $tokenStats = (clone $metricBase)
            ->selectRaw('
                COUNT(*)                               as total_articles,
                SUM(haiku_input_tokens)                as haiku_in,
                SUM(haiku_output_tokens)               as haiku_out,
                SUM(sonnet_input_tokens)               as sonnet_in,
                SUM(sonnet_output_tokens)              as sonnet_out,
                ROUND(SUM(total_cost_usd), 4)          as total_cost,
                ROUND(AVG(total_cost_usd), 6)          as avg_cost,
                ROUND(AVG(processing_time_ms)/1000, 1) as avg_sec
            ')
            ->first();

        $dailyCost = (clone $metricBase)
            ->selectRaw('
                DATE(created_at)                       as day,
                COUNT(*)                               as articles,
                SUM(haiku_input_tokens + haiku_output_tokens + sonnet_input_tokens + sonnet_output_tokens) as total_tokens,
                ROUND(SUM(total_cost_usd), 4)          as ai_cost,
                ROUND(AVG(processing_time_ms)/1000, 1) as avg_sec
            ')
            ->groupBy('day')
            ->orderByDesc('day')
            ->get()
            ->keyBy('day');

        // Gộp thêm chi phí thủ công từ claude_usage_logs
        $logCostByDay = ClaudeUsageLog::selectRaw('DATE(created_at) as day, ROUND(SUM(total_cost_usd), 4) as manual_cost, COUNT(*) as manual_calls')
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $mon)
            ->when($date, fn($q) => $q->whereDate('created_at', $date))
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        // Merge: tất cả ngày xuất hiện ở 1 trong 2 nguồn
        $allDays = $dailyCost->keys()->merge($logCostByDay->keys())->unique()->sort()->reverse();
        $dailyCost = $allDays->map(function ($day) use ($dailyCost, $logCostByDay) {
            $ai     = $dailyCost->get($day);
            $manual = $logCostByDay->get($day);
            return (object) [
                'day'          => $day,
                'articles'     => $ai?->articles     ?? 0,
                'total_tokens' => $ai?->total_tokens ?? 0,
                'ai_cost'      => $ai?->ai_cost      ?? 0,
                'manual_cost'  => $manual?->manual_cost  ?? 0,
                'manual_calls' => $manual?->manual_calls ?? 0,
                'total_cost'   => round(($ai?->ai_cost ?? 0) + ($manual?->manual_cost ?? 0), 4),
                'avg_sec'      => $ai?->avg_sec      ?? 0,
            ];
        });

        // ── Chi tiết từng bài khi lọc theo ngày cụ thể ───────────────────────
        $articleDetails = collect();
        if ($date) {
            $articleDetails = PromptMetric::with('article:id,title,slug,keyword_id')
                ->whereDate('created_at', $date)
                ->orderByDesc('created_at')
                ->get([
                    'id', 'article_id', 'created_at',
                    'haiku_input_tokens', 'haiku_output_tokens',
                    'sonnet_input_tokens', 'sonnet_output_tokens',
                    'total_cost_usd', 'processing_time_ms',
                    'retry_count', 'needs_review', 'hook_score',
                ]);
        }

        return view('admin.claude-usage.index', [
            'route'           => 'claude-usage',
            'action'          => 'admin-claude-usage',
            'menu'            => 'menu-open',
            'active'          => 'active',
            'logs'            => $logs,
            'admins'          => $admins,
            'dailySummary'    => $dailySummary,
            'monthlySummary'  => $monthlySummary,
            'grandTotal'      => $grandTotal,
            'month'           => $month,
            'date'            => $date,
            'adminId'         => $adminId,
            'tokenStats'      => $tokenStats,
            'dailyCost'       => $dailyCost,
            'articleDetails'  => $articleDetails,
        ]);
    }
}
