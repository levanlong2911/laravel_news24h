<?php

namespace App\Services\AI\PromptCompiler;

use App\Services\AI\PromptCompiler\Libraries\AssetLibrary;
use App\Services\AI\PromptCompiler\Libraries\EmotionLibrary;
use App\Services\AI\PromptCompiler\Libraries\EnvironmentLibrary;
use App\Services\AI\PromptCompiler\Libraries\QualityLibrary;
use App\Services\AI\PromptCompiler\Libraries\SubjectLibrary;
use App\Services\AI\PromptCompiler\PromptDocument\CameraBlock;
use App\Services\AI\PromptCompiler\PromptDocument\ContinuityBlock;
use App\Services\AI\PromptCompiler\PromptDocument\EmotionBlock;
use App\Services\AI\PromptCompiler\PromptDocument\EnvironmentBlock;
use App\Services\AI\PromptCompiler\PromptDocument\NegativeBlock;
use App\Services\AI\PromptCompiler\PromptDocument\PromptDocument;
use App\Services\AI\PromptCompiler\PromptDocument\QualityBlock;
use App\Services\AI\PromptCompiler\PromptDocument\SubjectBlock;

/**
 * Assembles a PromptDocument from Compact Cinematic DSL + Knowledge Libraries.
 * Stateless pure function: same input always produces the same PromptDocument.
 */
final class PromptDocumentBuilder
{
    public static function build(array $dsl, ?string $continuityAnchor = null): PromptDocument
    {
        return new PromptDocument(
            camera:      self::buildCamera($dsl),
            subject:     self::buildSubject($dsl),
            environment: self::buildEnvironment($dsl),
            emotion:     self::buildEmotion($dsl),
            quality:     self::buildQuality($dsl),
            negative:    self::buildNegative(),
            continuity:  $continuityAnchor !== null ? new ContinuityBlock($continuityAnchor) : null,
        );
    }

    // -------------------------------------------------------------------------

    private static function buildCamera(array $dsl): CameraBlock
    {
        $move = $dsl['move'] ?? 'STATIC';
        return new CameraBlock(
            type:     DslLexicon::cam($dsl['cam'] ?? 'MEDIUM'),
            lens:     DslLexicon::lens($dsl['lens'] ?? '50'),
            move:     DslLexicon::move($move),
            isStatic: $move === 'STATIC',
        );
    }

    private static function buildSubject(array $dsl): SubjectBlock
    {
        $sub    = $dsl['sub'] ?? [];
        $actor  = trim((string) ($sub['actor']  ?? ''));
        $action = trim((string) ($sub['action'] ?? ''));
        $objId  = trim((string) ($sub['obj']    ?? ''));
        $emo    = $dsl['emo'] ?? '';

        $adverb       = EmotionLibrary::actionAdverb($emo);
        $actionGerund = self::toGerund($action);

        $actorDisplay  = $actor !== '' ? SubjectLibrary::displayName($actor) : '';
        $objectDisplay = $objId  !== '' ? AssetLibrary::displayName($objId)   : '';

        $enriched = self::composeSubjectSentence(
            $actorDisplay, $adverb, $actionGerund,
            $objectDisplay, $objId,
            $dsl['camera_goal'] ?? '',
        );

        return new SubjectBlock(
            actorDisplay:     $actorDisplay,
            actionAdverb:     $adverb,
            actionGerund:     $actionGerund,
            objectDisplay:    $objectDisplay,
            enrichedSentence: $enriched,
        );
    }

    private static function buildEnvironment(array $dsl): EnvironmentBlock
    {
        $envKey = isset($dsl['environment']) ? trim((string) $dsl['environment']) : null;

        if ($envKey !== null && $envKey !== '') {
            return new EnvironmentBlock(
                envKey:      $envKey,
                description: EnvironmentLibrary::expand($envKey),
                isFallback:  false,
            );
        }

        // Sprint 1 fallback: derive from lighting code.
        // Replace with semantic 'environment' field when SceneShotPlanner is built (Phase B).
        $lightCode = $dsl['light'] ?? 'S1';
        return new EnvironmentBlock(
            envKey:      $lightCode . '_derived',
            description: EnvironmentLibrary::fromLightFallback($lightCode),
            isFallback:  true,
        );
    }

    private static function buildEmotion(array $dsl): EmotionBlock
    {
        $emo = $dsl['emo'] ?? 'CRAFT';
        return new EmotionBlock(
            code:        $emo,
            modifiers:   EmotionLibrary::modifiers($emo),
            actionAdverb: EmotionLibrary::actionAdverb($emo),
        );
    }

    private static function buildQuality(array $dsl): QualityBlock
    {
        $tier = $dsl['realism'] ?? 'high';
        return new QualityBlock(
            tier:    $tier,
            phrases: QualityLibrary::phrases($tier),
        );
    }

    private static function buildNegative(): NegativeBlock
    {
        return new NegativeBlock([
            'blurry', 'out of focus', 'low quality', 'distorted',
            'bad anatomy', 'text overlay', 'watermark', 'logo',
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Convert base-form verb to gerund. Handles trailing 'e' and already-gerund. */
    private static function toGerund(string $verb): string
    {
        if ($verb === '') return '';
        if (str_ends_with($verb, 'ing')) return $verb;
        return preg_replace('/e$/', '', $verb) . 'ing';
    }

    private static function composeSubjectSentence(
        string $actorDisplay,
        string $adverb,
        string $actionGerund,
        string $objectDisplay,
        string $rawObjId,
        string $cameraGoal,
    ): string {
        if ($actorDisplay !== '' && $actionGerund !== '') {
            $adverbPart = $adverb !== '' ? " {$adverb}" : '';

            if ($objectDisplay !== '') {
                $art = self::article($objectDisplay);
                return "A {$actorDisplay}{$adverbPart} {$actionGerund} {$art} {$objectDisplay}";
            }

            return "A {$actorDisplay}{$adverbPart} {$actionGerund}";
        }

        if ($rawObjId !== '') {
            // No human actor — focus on the object being filmed (use rich asset phrase)
            return 'A ' . AssetLibrary::toPromptPhrase($rawObjId);
        }

        return $cameraGoal;
    }

    private static function article(string $word): string
    {
        if (preg_match('/^8/', $word)) return 'an';
        if (preg_match('/^[aeiou]/i', $word)) return 'an';
        return 'a';
    }
}
