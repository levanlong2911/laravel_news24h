<?php

namespace App\Services\AI\AFOS\Ontology;

/**
 * EntityExtractor — maps DSL scene_title + primarySubject → a typed EntityRef.
 *
 * This is the Ontology layer's entry point: it bridges free-form DSL text and
 * the typed entity vocabulary used by passes and backends.
 *
 * Matching is done on combined text (scene_title + primarySubject). Order
 * matters — more specific patterns (reflection → WATER_FEATURE) precede broad
 * ones (villa → STRUCTURE). Patterns are anchored with \b to avoid false
 * positives (e.g. "material" shouldn't match "material in architecture").
 *
 * Phase A: simple keyword matching.
 * Phase B: replace with LLM-based EntityResolver that handles multi-entity
 *          scenes and ambiguity (e.g. "pool reflecting the villa facade").
 */
final class EntityExtractor
{
    public static function fromDsl(string $sceneTitle, string $primarySubject = ''): EntityRef
    {
        $text = strtolower($sceneTitle . ' ' . $primarySubject);

        return match (true) {
            // ── Vehicle (before structure/reveal — catches wheel, road, sundeck for marine) ──
            (bool) preg_match('/\b(car|vehicle|boat|yacht|ship|aircraft|motorcycle|hull|bow|stern|wheel|sundeck|road)\b/', $text)
                => new EntityRef('vehicle', EntityType::VEHICLE, 'vehicle'),

            // ── Person / athlete (team, celebration = grouped subjects) ──────────
            (bool) preg_match('/\b(person|athlete|model|player|runner|swimmer|human|portrait|team|celebration)\b/', $text)
                => new EntityRef('subject', EntityType::PERSON, 'subject'),

            // ── Water features (poolside = adjacent to pool) ────────────────────
            (bool) preg_match('/\b(pool|poolside|reflection|reflecting|water|fountain|lake|pond|ocean|sea)\b/', $text)
                => self::waterRef($text),

            // ── Interior spaces (lounge, staircase, garage, bridge = nav bridge) ─
            (bool) preg_match('/\b(interior|room|corridor|hallway|living|dining|kitchen|lobby|atrium|lounge|staircase|garage|bridge)\b/', $text)
                => new EntityRef('interior', EntityType::INTERIOR_SPACE, 'sun-lit interior'),

            // ── Materials: specific keywords only (no "detail" — too greedy) ──
            (bool) preg_match('/\b(stone|marble|wood|teak|glass|texture|material|craft|surface|carbon|fabric)\b/', $text)
                => self::materialRef($text),

            // ── Outdoor architectural elements ─────────────────────────────────
            (bool) preg_match('/\b(terrace|balcony|deck|patio|courtyard)\b/', $text)
                => new EntityRef('terrace', EntityType::ARCHITECTURAL_ELEMENT, 'travertine terrace'),

            // ── Structure / building (crowd → sports venue via structureRef) ────
            (bool) preg_match('/\b(villa|building|house|structure|architecture|property|stadium|arena|court|pitch|crowd)\b/', $text)
                => self::structureRef($text),

            // ── Facade / exterior surface ──────────────────────────────────────
            (bool) preg_match('/\b(facade|exterior|elevation)\b/', $text)
                => new EntityRef('villa_facade', EntityType::ARCHITECTURAL_ELEMENT, "villa's travertine facade"),

            // ── Sky and aerial (after structure so "building aerial" hits structure) ─
            (bool) preg_match('/\b(sky|aerial|drone|bird|cloud|rooftop)\b/', $text)
                => new EntityRef('sky', EntityType::LANDSCAPE, 'open sky'),

            // ── Landscape and views ────────────────────────────────────────────
            (bool) preg_match('/\b(view|horizon|landscape|panorama|mountain|valley|garden|nature)\b/', $text)
                => new EntityRef('view', EntityType::LANDSCAPE, 'panoramic view beyond'),

            // ── Studio / hero setup (before sports action — "shot" ambiguity) ──
            (bool) preg_match('/\b(studio|turntable|showcase|cyclorama|lightbox)\b/', $text)
                => new EntityRef('studio_setup', EntityType::STRUCTURE, 'studio environment'),

            // ── Sports action (equipment, finish = sports-specific gear/moment) ──
            (bool) preg_match('/\b(action|sprint|jump|kick|pass|tackle|score|play|equipment|finish)\b/', $text)
                => new EntityRef('sports_action', EntityType::PERSON, 'athlete in motion'),

            default => EntityRef::generic($sceneTitle ?: $primarySubject),
        };
    }

    private static function waterRef(string $text): EntityRef
    {
        if (preg_match('/\b(ocean|sea|harbour|harbor)\b/', $text)) {
            return new EntityRef('ocean', EntityType::WATER_FEATURE, 'open ocean');
        }
        return new EntityRef('pool_reflection', EntityType::WATER_FEATURE, 'villa pool and its mirror-perfect reflection');
    }

    private static function structureRef(string $text): EntityRef
    {
        if (preg_match('/\b(stadium|arena|court|pitch|crowd)\b/', $text)) {
            return new EntityRef('sports_venue', EntityType::STRUCTURE, 'sports venue');
        }
        return new EntityRef('villa_facade', EntityType::STRUCTURE, 'villa');
    }

    private static function materialRef(string $text): EntityRef
    {
        if (preg_match('/\b(glass)\b/', $text)) {
            return new EntityRef('glass_panel', EntityType::MATERIAL, 'glass panel');
        }
        if (preg_match('/\b(teak|wood)\b/', $text)) {
            return new EntityRef('teak', EntityType::MATERIAL, 'teak surface');
        }
        if (preg_match('/\b(carbon|fabric|leather)\b/', $text)) {
            return new EntityRef('composite_material', EntityType::MATERIAL, 'composite material surface');
        }
        return new EntityRef('stone', EntityType::MATERIAL, 'stone surface');
    }
}
