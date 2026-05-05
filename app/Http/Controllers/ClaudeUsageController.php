<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\ClaudeUsageLog;
use Illuminate\Http\Request;

class ClaudeUsageController extends Controller
{
    public function index(Request $request)
    {
        $month   = $request->input('month', now()->format('Y-m'));
        $adminId = $request->input('admin_id');

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }
        [$year, $mon] = explode('-', $month);

        $query = ClaudeUsageLog::whereYear('created_at', $year)
            ->whereMonth('created_at', $mon)
            ->orderByDesc('created_at');

        if ($adminId) {
            $query->where('admin_id', $adminId);
        }

        $logs   = $query->paginate(50)->withQueryString();
        $admins = Admin::orderBy('name')->get(['id', 'name']);

        // Tổng theo ngày × admin trong tháng
        $dailySummary = ClaudeUsageLog::selectRaw('DATE(created_at) as day, admin_id, COUNT(*) as total')
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $mon)
            ->when($adminId, fn($q) => $q->where('admin_id', $adminId))
            ->groupBy('day', 'admin_id')
            ->orderByDesc('day')
            ->get()
            ->groupBy('day');

        return view('admin.claude-usage.index', [
            'route'        => 'claude-usage',
            'action'       => 'admin-claude-usage',
            'menu'         => 'menu-open',
            'active'       => 'active',
            'logs'         => $logs,
            'admins'       => $admins,
            'dailySummary' => $dailySummary,
            'month'        => $month,
            'adminId'      => $adminId,
        ]);
    }
}
