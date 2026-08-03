<?php

namespace App\Video\Director;

use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Producer\ProducerOutput;
use App\Video\World\VerifiedWorldGraph;

/**
 * Director chay tren mot model that. Chi dung cho integration test / ghi lan
 * dau (co duyet) — giong ClaudeExtractor/ClaudeProducer. CI dung FakeDirector.
 */
final class ClaudeDirector implements DirectorInterface
{
    public const INSTRUCTION_VERSION = 'director-v2';

    public function __construct(
        private readonly LlmClient $llm,
        // Haiku (2026-07-29, user chốt): cả pipeline video chạy Haiku để tiết
        // kiệm. Director là cú gọi ĐƯỢC GỌI NHIỀU NHẤT (1 lần/scene — bài 9
        // scene là 9 cú), nhưng mỗi cú rất nhỏ (~400 token vào, ~50 ra), nên
        // tiết kiệm tuyệt đối không lớn. Việc Director làm cũng nhẹ: CHỌN index
        // trong danh sách candidate + viết 1 câu dàn cảnh, không sinh cấu trúc
        // phức tạp — hợp với Haiku hơn Extractor.
        private readonly string $model = 'haiku',
    ) {}

    public function select(array $candidates, VerifiedWorldGraph $world, ?ProducerOutput $producer, int $sceneOrdinal = 1, int $totalScenes = 1, array $priorScenes = []): ActionSelection
    {
        $request = new LlmRequest(
            $this->instruction(),
            $this->renderContext($candidates, $world, $producer, $sceneOrdinal, $totalScenes, $priorScenes),
            self::INSTRUCTION_VERSION,
            $this->model,
        );

        $response = $this->llm->complete($request);

        return $this->parse($response->text, $candidates);
    }

    /**
     * @param  list<array{ordinal: int, hero: string, emotion: string, composition_note: string}>  $priorScenes
     */
    private function renderContext(array $candidates, VerifiedWorldGraph $world, ?ProducerOutput $producer, int $sceneOrdinal, int $totalScenes, array $priorScenes = []): string
    {
        $lines = ['HERO CANDIDATES:'];
        foreach ($candidates['hero_candidates'] as $id) {
            $entity = $world->entity($id);
            $lines[] = sprintf('- id=%s, name="%s" (%s)', $id, $this->displayName($id, $world), $entity?->type->value ?? 'unknown');
        }

        $lines[] = '';
        $lines[] = 'ACTION CANDIDATES (chọn bằng index):';
        foreach ($candidates['action_candidates'] as $i => $action) {
            $modifiers = $action->modifiers === [] ? '' : ' ('.implode(', ', $action->modifiers).')';
            $actorName = $this->displayName($action->actor, $world);
            $targetName = $action->target === '' ? '' : $this->displayName($action->target, $world);
            $lines[] = sprintf('[%d] %s: %s -> %s%s', $i, $action->type->value, $actorName, $targetName, $modifiers);
        }

        if ($producer !== null) {
            $lines[] = '';
            $lines[] = 'STORY CONTEXT:';
            $lines[] = 'Core conflict: '.$producer->coreConflict;
            $lines[] = 'Visual promise: '.$producer->visualPromise;

            // Vị trí trong mạch cảm xúc — thuần số học tỉ lệ (Objective), KHÔNG
            // phải Director tự chọn cảm xúc nào — chỉ cho Director BIẾT đang ở
            // đâu để composition/tông nhất quán xuyên phim. 2026-07-23.
            $curve = $producer->emotionalCurve;
            if ($curve !== [] && $totalScenes > 0) {
                $index = min((int) floor(($sceneOrdinal - 1) / $totalScenes * count($curve)), count($curve) - 1);
                $lines[] = sprintf('Emotional arc position (scene %d/%d): %s', $sceneOrdinal, $totalScenes, $curve[$index]);
            }
        }

        if ($priorScenes !== []) {
            $lines[] = '';
            $lines[] = 'PREVIOUS SCENES (for continuity — do not repeat verbatim, avoid picking the same hero/composition every time unless the story calls for it):';
            foreach ($priorScenes as $prior) {
                $lines[] = sprintf(
                    '- Scene %d: hero=%s, emotion=%s, composition="%s"',
                    $prior['ordinal'],
                    $prior['hero'] === '' ? '(none)' : $prior['hero'],
                    $prior['emotion'] === '' ? '(none)' : $prior['emotion'],
                    $prior['composition_note'],
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Bug thật 2026-07-26 (bài Nixie): renderContext() trước đây chỉ đưa ID
     * thô (vd 'yacht_nixie') cho Claude — Claude echo lại nguyên văn ID đó
     * trong composition_note ("Yacht_nixie fills the frame...") vì đó là CÁI
     * TÊN DUY NHẤT nó được thấy. Giờ đưa cả id= (để Claude trả về đúng trong
     * JSON "hero") lẫn name= (để Claude dùng khi VIẾT VĂN xuôi).
     */
    private function displayName(string $id, VerifiedWorldGraph $world): string
    {
        $entity = $world->entity($id);

        return $entity?->identity?->name ?? str_replace('_', ' ', $id);
    }

    private function parse(string $text, array $candidates): ActionSelection
    {
        $s = trim($text);
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $s, $m)) {
            $s = trim($m[1]);
        }

        $data = json_decode($s, true);
        if (! is_array($data)) {
            $data = [];
        }

        return new ActionSelection(
            (string) ($data['hero'] ?? ($candidates['hero_candidates'][0] ?? '')),
            (int) ($data['primary_index'] ?? 0),
            is_array($data['secondary_indices'] ?? null) ? array_map('intval', $data['secondary_indices']) : [],
            (string) ($data['emotion'] ?? ''),
            (string) ($data['reveal'] ?? ''),
            (string) ($data['composition_note'] ?? ''),
        );
    }

    private function instruction(): string
    {
        return <<<'TEXT'
        You are a film director choosing among pre-generated, physically valid options for
        one scene. You do not invent new actions or entities — you only select indices from
        the list given to you, and describe the emotional intent.

        Return ONLY raw JSON, no markdown fences, no commentary:

        {
          "hero": "the id= value from HERO CANDIDATES (never the name= value)",
          "primary_index": 0,
          "secondary_indices": [1, 2],
          "emotion": "one or two words describing what the audience should feel",
          "reveal": "how this scene reveals information (immediate/delayed/withheld etc.)",
          "composition_note": "one sentence describing blocking/framing for this shot"
        }

        Only use indices that exist in ACTION CANDIDATES. Only use an id= value that exists
        in HERO CANDIDATES for "hero" — not the name= value. Ground your choice in the story
        context given, not on outside knowledge.

        composition_note — this is where you act as a director, not a database:

        Write ONE sentence describing how to BLOCK this shot: what sits in the foreground,
        what recedes into the background, what the eye should land on first. This is framing
        and emphasis, not new content. When this sentence refers to an entity, use its name=
        value (e.g. "Nixie") — never its id= slug (e.g. "yacht_nixie"), which reads like a
        database key, not prose a viewer would read.

        You may ONLY refer to the hero you picked, the action you picked (primary/secondary),
        and entities already listed in HERO CANDIDATES. Do NOT introduce any person, object,
        crowd, weather, or detail that is not already present in the candidates given to you —
        that would be inventing a fact, not composing a shot. If you cannot describe a
        composition using only what's given, leave composition_note as an empty string rather
        than add something new.

        Bad (invents a crowd never given to you): "the vehicle in foreground, a cheering crowd
        blurred behind it"
        Bad (echoes the id= slug instead of the name=): "yacht_nixie dominates the frame..."
        Good (uses only the hero already chosen, by its name=): "the vehicle dominates the
        frame, its surface catching the light, everything else receding into soft focus"

        If PREVIOUS SCENES is given, you are the same director who shot those scenes moments
        ago — keep them in mind the way a real director holds the whole film in their head
        while shooting one shot. Vary hero and composition across scenes unless repeating one
        is itself the storytelling choice (e.g. returning to the same hero to build tension).
        Do not contradict a prior scene's emotion without narrative reason.
        TEXT;
    }
}
