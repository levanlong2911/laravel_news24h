<?php

namespace Database\Seeders;

use App\Models\Benchmark\BmFixture;
use App\Models\Benchmark\BmInstruction;
use App\Models\Benchmark\BmPlanner;
use App\Models\Benchmark\BmSession;
use Illuminate\Database\Seeder;

class BenchmarkSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSession();
        $this->seedFixtures();
        $this->seedPlanners();
        $this->seedInstructionCatalog();
    }

    // ── Session ──────────────────────────────────────────────────────────────

    private function seedSession(): void
    {
        $commit = $this->gitCommit();

        BmSession::firstOrCreate(['code' => 'sprint3-baseline'], [
            'name'       => 'Sprint 3 Baseline',
            'sprint'     => 'sprint3',
            'description'=> 'Frozen Sprint 3 pipeline. 13 fixtures × 3 renders = 39 videos. '
                          . 'Serves as the control group for all future A/B experiments.',
            'git_commit' => $commit,
        ]);
    }

    private function gitCommit(): ?string
    {
        $redirect = PHP_OS_FAMILY === 'Windows' ? '2>NUL' : '2>/dev/null';
        $hash = @shell_exec("git rev-parse HEAD {$redirect}");
        return $hash ? trim($hash) : null;
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function seedFixtures(): void
    {
        $fixtures = [
            ['slug' => 'nfl_quarterback_throw',  'name' => 'NFL Quarterback Throw',    'scene_category' => 'athletic_action'],
            ['slug' => 'nba_slam_dunk',           'name' => 'NBA Slam Dunk',             'scene_category' => 'athletic_action'],
            ['slug' => 'soccer_penalty_kick',     'name' => 'Soccer Penalty Kick',       'scene_category' => 'athletic_action'],
            ['slug' => 'f1_race_start',           'name' => 'F1 Race Start',             'scene_category' => 'athletic_action'],
            ['slug' => 'breaking_news_studio',    'name' => 'Breaking News Studio',      'scene_category' => 'generic'],
            ['slug' => 'stock_market_crash',      'name' => 'Stock Market Crash',        'scene_category' => 'generic'],
            ['slug' => 'space_rocket_launch',     'name' => 'Space Rocket Launch',       'scene_category' => 'aerial_vehicle'],
            ['slug' => 'wildfire_aerial',         'name' => 'Wildfire Aerial',           'scene_category' => 'aerial_vehicle'],
            ['slug' => 'supercar_reveal',         'name' => 'Supercar Reveal',           'scene_category' => 'product_craft'],
            ['slug' => 'luxury_yacht_ocean',      'name' => 'Luxury Yacht Ocean',        'scene_category' => 'aerial_vehicle'],
            ['slug' => 'tokyo_cityscape_night',   'name' => 'Tokyo Cityscape Night',     'scene_category' => 'landscape_nature'],
            ['slug' => 'tesla_autopilot',         'name' => 'Tesla Autopilot',           'scene_category' => 'product_craft'],
            ['slug' => 'ai_robot_assembly',       'name' => 'AI Robot Assembly',         'scene_category' => 'product_craft'],
        ];

        foreach ($fixtures as $f) {
            BmFixture::firstOrCreate(['slug' => $f['slug']], $f);
        }
    }

    // ── Planners ─────────────────────────────────────────────────────────────

    private function seedPlanners(): void
    {
        $planners = [
            ['name' => 'BeatFusionEngine',           'file_path' => 'Services/AI/ScenePlanner/BeatFusionEngine.php',           'version' => '2.0'],
            ['name' => 'CameraMotivationPlanner',    'file_path' => 'Services/AI/ScenePlanner/CameraMotivationPlanner.php',    'version' => '1.0'],
            ['name' => 'CinematicBeatPlanner',       'file_path' => 'Services/AI/ScenePlanner/CinematicBeatPlanner.php',       'version' => '1.0'],
            ['name' => 'CompositionEvolutionPlanner','file_path' => 'Services/AI/ScenePlanner/CompositionEvolutionPlanner.php','version' => '1.0'],
            ['name' => 'CompositionPlanner',         'file_path' => 'Services/AI/ScenePlanner/CompositionPlanner.php',         'version' => '1.0'],
            ['name' => 'CuriosityPlanner',           'file_path' => 'Services/AI/ScenePlanner/CuriosityPlanner.php',           'version' => '1.0'],
            ['name' => 'EmotionArcPlanner',          'file_path' => 'Services/AI/ScenePlanner/EmotionArcPlanner.php',          'version' => '1.0'],
            ['name' => 'EyeGuidancePlanner',         'file_path' => 'Services/AI/ScenePlanner/EyeGuidancePlanner.php',         'version' => '1.0'],
            ['name' => 'RevealPlanner',              'file_path' => 'Services/AI/ScenePlanner/RevealPlanner.php',              'version' => '1.0'],
            ['name' => 'RhythmPlanner',              'file_path' => 'Services/AI/ScenePlanner/RhythmPlanner.php',              'version' => '1.0'],
            ['name' => 'VisualContrastPlanner',      'file_path' => 'Services/AI/ScenePlanner/VisualContrastPlanner.php',      'version' => '1.0'],
            ['name' => 'PhysicsPlanner',             'file_path' => 'Services/AI/ScenePlanner/PhysicsPlanner.php',             'version' => '1.0'],
        ];

        foreach ($planners as $p) {
            $abs         = app_path($p['file_path']);
            $fingerprint = file_exists($abs) ? hash_file('sha256', $abs) : null;

            BmPlanner::updateOrCreate(['name' => $p['name']], [
                'file_path'   => $p['file_path'],
                'fingerprint' => $fingerprint,
                'version'     => $p['version'],
            ]);
        }
    }

    // ── Instruction Catalog ───────────────────────────────────────────────────

    private function seedInstructionCatalog(): void
    {
        $plannerMap = BmPlanner::pluck('id', 'name');

        $catalog = [
            // Camera instructions (BeatFusionEngine drives camera sentences)
            ['code' => 'snap_zoom',          'planner' => 'BeatFusionEngine',        'category' => 'camera',      'introduced_in' => 'sprint1', 'description' => 'Snap zoom onto subject at hook beat'],
            ['code' => 'slow_orbit',         'planner' => 'BeatFusionEngine',        'category' => 'camera',      'introduced_in' => 'sprint1', 'description' => 'Slow orbital camera movement'],
            ['code' => 'push_in',            'planner' => 'BeatFusionEngine',        'category' => 'camera',      'introduced_in' => 'sprint1', 'description' => 'Camera pushes toward subject'],
            ['code' => 'pull_back',          'planner' => 'BeatFusionEngine',        'category' => 'camera',      'introduced_in' => 'sprint1', 'description' => 'Camera pulls back to wide reveal'],
            ['code' => 'rack_focus',         'planner' => 'RevealPlanner',           'category' => 'camera',      'introduced_in' => 'sprint1', 'description' => 'Rack focus pull at reveal beat'],
            ['code' => 'through_cloud',      'planner' => 'RevealPlanner',           'category' => 'camera',      'introduced_in' => 'sprint1', 'description' => 'Camera pierces cloud base at reveal'],
            ['code' => 'abrupt_decel',       'planner' => 'BeatFusionEngine',        'category' => 'camera',      'introduced_in' => 'sprint2', 'description' => 'Abrupt camera deceleration into hold'],
            ['code' => 'camera_motivation',  'planner' => 'CameraMotivationPlanner', 'category' => 'camera',      'introduced_in' => 'sprint3', 'description' => 'Purpose clause added to camera verb'],

            // Eye guidance (implicit)
            ['code' => 'eye_hook_implicit',  'planner' => 'BeatFusionEngine',        'category' => 'eye_guidance','introduced_in' => 'sprint3', 'description' => 'Implicit eye anchor at hook — identity withheld'],
            ['code' => 'eye_payoff_implicit','planner' => 'BeatFusionEngine',        'category' => 'eye_guidance','introduced_in' => 'sprint3', 'description' => 'Implicit eye anchor at payoff — scale overwhelms'],

            // Atmosphere / light
            ['code' => 'atmosphere_active',  'planner' => 'BeatFusionEngine',        'category' => 'atmosphere',  'introduced_in' => 'sprint3', 'description' => 'Active atmospheric verb (light as agent, not descriptor)'],
            ['code' => 'light_phrase',       'planner' => 'VisualContrastPlanner',   'category' => 'atmosphere',  'introduced_in' => 'sprint2', 'description' => 'Compact light phrase embedded in fusion sentence'],

            // Physics / environment
            ['code' => 'wake_foam',          'planner' => 'PhysicsPlanner',          'category' => 'physics',     'introduced_in' => 'sprint1', 'description' => 'Wake foam and spray physics on yacht/boat'],
            ['code' => 'crowd_motion_blur',  'planner' => 'PhysicsPlanner',          'category' => 'physics',     'introduced_in' => 'sprint1', 'description' => 'Crowd motion blur behind athletic action'],
            ['code' => 'cold_breath',        'planner' => 'PhysicsPlanner',          'category' => 'physics',     'introduced_in' => 'sprint1', 'description' => 'Visible breath condensation in cold environment'],
            ['code' => 'dust_lift',          'planner' => 'PhysicsPlanner',          'category' => 'physics',     'introduced_in' => 'sprint1', 'description' => 'Dust lifting from surface on impact/motion'],
            ['code' => 'jersey_tension',     'planner' => 'PhysicsPlanner',          'category' => 'physics',     'introduced_in' => 'sprint1', 'description' => 'Jersey fabric tension under athletic load'],
            ['code' => 'sun_glint_pulse',    'planner' => 'PhysicsPlanner',          'category' => 'physics',     'introduced_in' => 'sprint1', 'description' => 'Sun glint pulsing rhythmically on water surface'],
            ['code' => 'exhaust_plume',      'planner' => 'PhysicsPlanner',          'category' => 'physics',     'introduced_in' => 'sprint1', 'description' => 'Engine exhaust plume on rocket/F1'],

            // Depth / composition
            ['code' => 'depth_hook',         'planner' => 'CompositionEvolutionPlanner','category'=> 'composition','introduced_in'=> 'sprint2', 'description' => 'Hook beat: extreme close, no depth layers'],
            ['code' => 'depth_escalation',   'planner' => 'CompositionEvolutionPlanner','category'=> 'composition','introduced_in'=> 'sprint2', 'description' => 'Escalation beat: foreground emerges'],
            ['code' => 'depth_reveal',       'planner' => 'CompositionEvolutionPlanner','category'=> 'composition','introduced_in'=> 'sprint2', 'description' => 'Reveal beat: all 3 depth layers present'],
            ['code' => 'depth_payoff',       'planner' => 'CompositionEvolutionPlanner','category'=> 'composition','introduced_in'=> 'sprint2', 'description' => 'Payoff beat: foreground removed, subject small in vast bg'],

            // Curiosity
            ['code' => 'identity_withheld',  'planner' => 'CuriosityPlanner',        'category' => 'curiosity',   'introduced_in' => 'sprint1', 'description' => 'Subject identity withheld at hook — face/form concealed'],
            ['code' => 'partial_reveal',     'planner' => 'CuriosityPlanner',        'category' => 'curiosity',   'introduced_in' => 'sprint1', 'description' => 'Partial information disclosed at escalation'],
        ];

        foreach ($catalog as $entry) {
            $plannerId = $plannerMap[$entry['planner']] ?? null;
            if (! $plannerId) {
                continue;
            }
            BmInstruction::firstOrCreate(['code' => $entry['code']], [
                'planner_id'    => $plannerId,
                'category'      => $entry['category'],
                'description'   => $entry['description'],
                'introduced_in' => $entry['introduced_in'],
                'deprecated_in' => null,
            ]);
        }
    }
}
