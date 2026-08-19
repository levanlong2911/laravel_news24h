<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Hai cot cuoi cung con giu du lieu le ra thuoc ve bang rieng.
 *
 * `renderplan_json`: moi lan luu la GHI DE, nen ban ke hoach truoc do bien mat
 * khong dau vet — khong tra loi duoc "lan dung thu 3 khac lan 2 cho nao".
 * `video_render_plans` giu tung ban theo `revision`, kem `plan_hash`.
 *
 * `cost_actual`: chi la mot con so cong don, biet TONG ma khong biet tieu vao
 * dau. `video_cost_entries` ghi tung dong kem provider/model/stage.
 *
 * Hop dong API voi Python KHONG doi: `apiComposing` van tra khoa
 * `renderplan_json`, chi khac cho lay du lieu.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Chuyen ke hoach hien co sang bang moi TRUOC khi bo cot, neu khong la
        // mat trang 5 ban ke hoach dang dung.
        foreach (DB::table('video_sessions')->whereNotNull('renderplan_json')->get() as $s) {
            $plan = json_decode($s->renderplan_json, true);

            if (! is_array($plan)) {
                continue;
            }

            DB::table('video_render_plans')->insert([
                'id' => (string) Str::uuid(),
                'session_id' => $s->id,
                'revision' => max(1, (int) ($s->plan_revision ?? 1)),
                'schema_version' => (string) ($plan['schema_version'] ?? ''),
                'builder_version' => (string) ($plan['builder_version'] ?? ''),
                'status' => 'active',
                'scene_count' => count($plan['scenes'] ?? []),
                'aspect_ratio' => $plan['aspect_ratio'] ?? null,
                'width' => $plan['width'] ?? null,
                'height' => $plan['height'] ?? null,
                'target_duration_ms' => $plan['target_duration_ms'] ?? null,
                'plan_json' => $s->renderplan_json,
                'plan_hash' => hash('sha256', $s->renderplan_json),
                'created_at' => $s->created_at,
                'updated_at' => $s->updated_at,
            ]);
        }

        // Tong cu khong chia nho duoc thanh tung lan render: giu nguyen ven so
        // tien da tieu bang MOT dong gop, danh dau ro la du lieu chuyen doi.
        foreach (DB::table('video_sessions')->where('cost_actual', '>', 0)->get() as $s) {
            DB::table('video_cost_entries')->insert([
                'id' => (string) Str::uuid(),
                'project_id' => $s->project_id,
                'session_id' => $s->id,
                'entity_type' => 'session',
                'entity_id' => $s->id,
                'stage' => 'migrated',
                'provider' => '',
                'model' => '',
                'usage_type' => 'legacy_total',
                'quantity' => 1,
                'unit' => 'total',
                'cost_usd' => $s->cost_actual,
                'created_at' => $s->created_at,
                'updated_at' => $s->updated_at,
            ]);
        }

        Schema::table('video_sessions', function (Blueprint $table) {
            $table->dropColumn(['renderplan_json', 'cost_actual']);
        });
    }

    public function down(): void
    {
        Schema::table('video_sessions', function (Blueprint $table) {
            $table->longText('renderplan_json')->nullable();
            $table->decimal('cost_actual', 12, 6)->default(0);
        });

        foreach (DB::table('video_render_plans')->orderBy('revision')->get() as $p) {
            DB::table('video_sessions')->where('id', $p->session_id)
                ->update(['renderplan_json' => $p->plan_json]);
        }

        foreach (DB::table('video_cost_entries')->select('session_id')
            ->selectRaw('SUM(cost_usd) t')->groupBy('session_id')->get() as $c) {
            DB::table('video_sessions')->where('id', $c->session_id)->update(['cost_actual' => $c->t]);
        }
    }
};
