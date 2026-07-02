<?php

namespace App\Services\AI\PromptCompiler;

/**
 * Expands compact Cinematic DSL codes to natural-language phrases.
 * Each method corresponds to one DSL field from SceneShot.schema.json.
 * Returns the code unchanged if not found (safe fallback).
 */
final class DslLexicon
{
    private const CAM = [
        'WIDE'     => 'wide-angle establishing shot',
        'MEDIUM'   => 'medium shot',
        'CLOSE'    => 'close-up',
        'MACRO'    => 'extreme macro close-up revealing fine detail',
        'ORBITAL'  => 'orbital camera sweep',
        'TRACKING' => 'tracking shot following the subject',
        'AERIAL'   => 'aerial drone perspective',
        'POV'      => 'first-person point of view',
    ];

    private const LENS = [
        '24'  => '24mm ultra-wide lens',
        '35'  => '35mm wide lens',
        '50'  => '50mm standard lens',
        '85'  => '85mm portrait lens',
        '135' => '135mm telephoto lens',
        '200' => '200mm super-telephoto lens',
    ];

    private const LIGHT = [
        'W1' => 'warm golden workshop lighting',
        'W2' => 'warm amber sunset glow',
        'G1' => 'golden hour magic light',
        'N1' => 'dramatic night city neon lights',
        'N2' => 'moonlit blue-silver night',
        'D1' => 'dramatic single-source rim lighting with deep shadows',
        'S1' => 'soft natural window light',
        'S2' => 'soft diffused studio lighting',
        'C1' => 'cool precise clinical lighting',
        'C2' => 'cool steel-blue industrial ambient',
    ];

    private const MOVE = [
        'STATIC' => 'locked-off static shot',
        'P1'     => 'slow smooth push-in toward subject',
        'P2'     => 'slow smooth pull-back reveal',
        'D1'     => 'steady dolly right',
        'D2'     => 'steady dolly left',
        'O1'     => 'clockwise orbital movement',
        'O2'     => 'counterclockwise orbital movement',
        'H1'     => 'subtle organic handheld movement',
        'T1'     => 'tilt-up reveal',
        'T2'     => 'tilt-down descend',
    ];

    private const EMO = [
        'HOOK'   => 'immediately arresting, draws the viewer in',
        'CRAFT'  => 'quiet craftsmanship, intimate precision',
        'AWE'    => 'breathtaking scale and beauty',
        'TENSE'  => 'edge-of-seat tension and suspense',
        'DRAMA'  => 'cinematic drama and impact',
        'REVEAL' => 'satisfying revelation',
        'CALM'   => 'peaceful tranquility',
        'POWER'  => 'raw power and energy',
        'JOY'    => 'pure joy and euphoria',
        'FEAR'   => 'primal dread and unease',
        'EPIC'   => 'epic grandeur and scale',
    ];

    private const REALISM = [
        'photoreal' => 'hyperrealistic photographic quality, shot on professional camera',
        'high'      => 'highly detailed, sharp focus, professional photography',
        'medium'    => 'stylized realistic, balanced detail',
        'low'       => 'artistic painterly interpretation',
    ];

    public static function cam(string $code): string    { return self::CAM[$code]     ?? $code; }
    public static function lens(string $code): string   { return self::LENS[$code]    ?? $code; }
    public static function light(string $code): string  { return self::LIGHT[$code]   ?? $code; }
    public static function move(string $code): string   { return self::MOVE[$code]    ?? $code; }
    public static function emo(string $code): string    { return self::EMO[$code]     ?? $code; }
    public static function realism(string $code): string { return self::REALISM[$code] ?? $code; }

    /** Build subject clause from sub{} sub-object, falling back to camera_goal. */
    public static function subject(array $dsl): string
    {
        $sub = $dsl['sub'] ?? [];
        $actor  = trim((string) ($sub['actor']  ?? ''));
        $action = trim((string) ($sub['action'] ?? ''));
        $obj    = trim((string) ($sub['obj']    ?? ''));

        $parts = array_filter([$actor, $action, $obj]);
        if ($parts !== []) {
            return implode(' ', $parts);
        }

        return trim((string) ($dsl['camera_goal'] ?? ''));
    }
}
