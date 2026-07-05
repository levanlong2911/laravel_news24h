<?php
/**
 * Stage 2 — Generate prompt fixture files for regression testing.
 *
 * Run via: php python/generate_prompt_fixtures.php
 * (does NOT need database — runs purely through the PHP planner/serializer stack)
 *
 * Output: tests/prompts/*.txt (one file per DSL)
 * Use these for:
 *   - Manual review of prompt quality
 *   - Regression diffing after any planner/serializer change
 *   - Stage 3 Kling API tests (pass file to test_kling.py)
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$outDir = __DIR__ . '/../tests/prompts';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

// Each fixture: [filename, DSL array]
$fixtures = [

    // ── Sports ────────────────────────────────────────────────────────────────

    'nfl_quarterback_throw' => [
        'shot_id' => 'fixture-nfl-001', 'scene_id' => 'nfl-001', 'shot_order' => 1,
        'scene_title' => 'NFL Quarterback Throw', 'scene_emotion' => 'POWER',
        'emo' => 'POWER', 'dur' => 5.0, 'light' => 'W1', 'cam' => 'TRACKING',
        'lens' => '85mm', 'cam_height' => 'eye_level', 'move' => 'P1',
        'motion_level' => 'high', 'stabilization' => 'handheld',
        'sub' => ['actor' => 'quarterback', 'obj' => 'football', 'action' => 'fb_throw'],
        'provider' => 'kling', 'camera_goal' => 'capture the explosive spiral release',
    ],

    'nba_slam_dunk' => [
        'shot_id' => 'fixture-nba-001', 'scene_id' => 'nba-001', 'shot_order' => 1,
        'scene_title' => 'NBA Slam Dunk', 'scene_emotion' => 'POWER',
        'emo' => 'POWER', 'dur' => 5.0, 'light' => 'W1', 'cam' => 'CLOSE',
        'lens' => '85mm', 'move' => 'T1', 'motion_level' => 'high',
        'sub' => ['actor' => 'basketball player', 'obj' => 'basketball', 'action' => 'bball_dunk'],
        'provider' => 'kling', 'camera_goal' => 'capture the explosive power of the dunk',
    ],

    'soccer_penalty_kick' => [
        'shot_id' => 'fixture-soccer-001', 'scene_id' => 'soccer-001', 'shot_order' => 1,
        'scene_title' => 'Soccer Penalty Kick', 'scene_emotion' => 'TENSE',
        'emo' => 'TENSE', 'dur' => 5.0, 'light' => 'G1', 'cam' => 'TRACKING',
        'lens' => '85mm', 'move' => 'P1', 'motion_level' => 'high',
        'sub' => ['actor' => 'soccer player', 'obj' => 'ball', 'action' => 'soccer_penalty_kick'],
        'provider' => 'kling', 'camera_goal' => 'show the pressure of a penalty kick',
    ],

    'f1_race_start' => [
        'shot_id' => 'fixture-f1-001', 'scene_id' => 'f1-001', 'shot_order' => 1,
        'scene_title' => 'F1 Race Start', 'scene_emotion' => 'EPIC',
        'emo' => 'EPIC', 'dur' => 5.0, 'light' => 'G1', 'cam' => 'AERIAL',
        'lens' => '35mm', 'move' => 'D1', 'motion_level' => 'high',
        'sub' => ['actor' => 'F1 car', 'obj' => 'race track', 'action' => 'vehicle_race'],
        'provider' => 'kling', 'camera_goal' => 'cinematic race start formation',
    ],

    // ── News ──────────────────────────────────────────────────────────────────

    'breaking_news_studio' => [
        'shot_id' => 'fixture-news-001', 'scene_id' => 'news-001', 'shot_order' => 1,
        'scene_title' => 'Breaking News Studio', 'scene_emotion' => 'DRAMA',
        'emo' => 'DRAMA', 'dur' => 5.0, 'light' => 'C1', 'cam' => 'MEDIUM',
        'lens' => '50mm', 'move' => 'STATIC', 'motion_level' => 'low',
        'sub' => ['actor' => 'news anchor', 'obj' => 'camera', 'action' => 'person_walk'],
        'provider' => 'kling', 'camera_goal' => 'professional news broadcast composition',
    ],

    'stock_market_crash' => [
        'shot_id' => 'fixture-finance-001', 'scene_id' => 'finance-001', 'shot_order' => 1,
        'scene_title' => 'Stock Market Crash', 'scene_emotion' => 'DRAMA',
        'emo' => 'DRAMA', 'dur' => 5.0, 'light' => 'D1', 'cam' => 'TRACKING',
        'lens' => '35mm', 'move' => 'P2', 'motion_level' => 'medium',
        'sub' => ['actor' => 'stock trader', 'obj' => 'trading screen', 'action' => 'person_walk'],
        'provider' => 'kling', 'camera_goal' => 'convey financial panic and urgency',
    ],

    // ── Documentary ───────────────────────────────────────────────────────────

    'space_rocket_launch' => [
        'shot_id' => 'fixture-space-001', 'scene_id' => 'space-001', 'shot_order' => 1,
        'scene_title' => 'Rocket Launch', 'scene_emotion' => 'AWE',
        'emo' => 'AWE', 'dur' => 10.0, 'light' => 'G1', 'cam' => 'AERIAL',
        'lens' => '35mm', 'move' => 'T1', 'motion_level' => 'high',
        'sub' => ['actor' => 'rocket', 'obj' => 'launch pad', 'action' => 'vehicle_flight'],
        'provider' => 'kling', 'camera_goal' => 'reveal the scale and power of a rocket launch',
    ],

    'wildfire_aerial' => [
        'shot_id' => 'fixture-wildfire-001', 'scene_id' => 'wildfire-001', 'shot_order' => 1,
        'scene_title' => 'Wildfire Aerial View', 'scene_emotion' => 'DRAMA',
        'emo' => 'DRAMA', 'dur' => 5.0, 'light' => 'D1', 'cam' => 'AERIAL',
        'lens' => '35mm', 'move' => 'O1', 'motion_level' => 'medium',
        'sub' => ['actor' => 'firefighter', 'obj' => 'wildfire', 'action' => 'person_walk'],
        'provider' => 'kling', 'camera_goal' => 'show the terrifying scale of the wildfire',
    ],

    // ── Luxury / Travel ───────────────────────────────────────────────────────

    'supercar_reveal' => [
        'shot_id' => 'fixture-auto-001', 'scene_id' => 'auto-001', 'shot_order' => 1,
        'scene_title' => 'Supercar Reveal', 'scene_emotion' => 'EPIC',
        'emo' => 'EPIC', 'dur' => 5.0, 'light' => 'G1', 'cam' => 'ORBITAL',
        'lens' => '50mm', 'move' => 'O2', 'motion_level' => 'medium',
        'sub' => ['actor' => 'Ferrari', 'obj' => 'road', 'action' => 'vehicle_race'],
        'provider' => 'kling', 'camera_goal' => 'cinematic luxury car reveal in golden hour',
    ],

    'luxury_yacht_ocean' => [
        'shot_id' => 'fixture-luxury-001', 'scene_id' => 'luxury-001', 'shot_order' => 1,
        'scene_title' => 'Luxury Yacht on Open Ocean', 'scene_emotion' => 'CALM',
        'emo' => 'CALM', 'dur' => 10.0, 'light' => 'G1', 'cam' => 'AERIAL',
        'lens' => '35mm', 'move' => 'D1', 'motion_level' => 'low', 'stabilization' => 'gimbal',
        'sub' => ['actor' => 'yacht', 'obj' => 'ocean', 'action' => 'vehicle_flight'],
        'provider' => 'kling', 'camera_goal' => 'show the peaceful luxury of the open sea',
    ],

    'tokyo_cityscape_night' => [
        'shot_id' => 'fixture-travel-001', 'scene_id' => 'travel-001', 'shot_order' => 1,
        'scene_title' => 'Tokyo Cityscape at Night', 'scene_emotion' => 'AWE',
        'emo' => 'AWE', 'dur' => 10.0, 'light' => 'N1', 'cam' => 'AERIAL',
        'lens' => '35mm', 'move' => 'P2', 'motion_level' => 'low', 'stabilization' => 'gimbal',
        'sub' => ['actor' => 'Tokyo skyline', 'obj' => 'city lights', 'action' => 'person_walk'],
        'provider' => 'kling', 'camera_goal' => 'reveal the scale of Tokyo at night',
    ],

    // ── Technology ────────────────────────────────────────────────────────────

    'tesla_autopilot' => [
        'shot_id' => 'fixture-tech-001', 'scene_id' => 'tech-001', 'shot_order' => 1,
        'scene_title' => 'Tesla Autopilot Activation', 'scene_emotion' => 'REVEAL',
        'emo' => 'REVEAL', 'dur' => 5.0, 'light' => 'S2', 'cam' => 'CLOSE',
        'lens' => '85mm', 'move' => 'P1', 'motion_level' => 'low', 'stabilization' => 'gimbal',
        'sub' => ['actor' => 'driver', 'obj' => 'Tesla steering wheel', 'action' => 'person_sit'],
        'provider' => 'kling', 'camera_goal' => 'reveal the moment autopilot takes control',
    ],

    'ai_robot_assembly' => [
        'shot_id' => 'fixture-robot-001', 'scene_id' => 'robot-001', 'shot_order' => 1,
        'scene_title' => 'AI Robot Assembly Line', 'scene_emotion' => 'CRAFT',
        'emo' => 'CRAFT', 'dur' => 5.0, 'light' => 'C2', 'cam' => 'MACRO',
        'lens' => '135mm', 'move' => 'P1', 'motion_level' => 'low', 'stabilization' => 'gimbal',
        'sub' => ['actor' => 'robot arm', 'obj' => 'circuit board', 'action' => 'craft_throw'],
        'provider' => 'kling', 'camera_goal' => 'show the precision of robotic manufacturing',
    ],
];

// ── Run pipeline for each fixture ────────────────────────────────────────────

$planner     = app(\App\Services\AI\ScenePlanner\ScenePlanner::class);
$builder     = app(\App\Services\AI\SceneGraph\SceneGraphBuilder::class);
$serializer  = new \App\Services\AI\PromptAST\Serializers\KlingSerializer();
$normalizer  = new \App\Services\AI\PromptAST\PromptNormalizer();

$generated = 0;
$failed    = 0;

foreach ($fixtures as $name => $dsl) {
    try {
        $result   = $planner->plan($dsl);
        $graph    = $builder->build($result);
        $ast      = \App\Services\AI\PromptAST\PromptBlockAssembler::assemble($graph);
        $ast      = $normalizer->normalize($ast);
        $prompt   = $serializer->serialize($ast);

        $file = "{$outDir}/{$name}.txt";
        file_put_contents($file, $prompt);

        $chars = strlen($prompt);
        $sections = substr_count($prompt, "\n\n") + 1;
        echo "✓ {$name}.txt ({$chars} chars, {$sections} sections)\n";
        $generated++;

    } catch (\Throwable $e) {
        echo "✗ {$name}: " . $e->getMessage() . " at " . basename($e->getFile()) . ":{$e->getLine()}\n";
        $failed++;
    }
}

echo "\n{$generated} prompts saved to tests/prompts/\n";
if ($failed > 0) {
    echo "{$failed} failures — fix before regression testing\n";
    exit(1);
}
echo "Run Stage 3 with: python python/test_kling.py tests/prompts/<name>.txt\n";
