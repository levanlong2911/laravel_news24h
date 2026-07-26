<?php

namespace App\Video\Director;

use App\Video\Producer\ProducerOutput;
use App\Video\World\VerifiedWorldGraph;

/**
 * Director: "nguoi xem nen cam thay gi o canh nay" — CHI chon trong candidates
 * (da sinh boi EditorialInterpreter::candidatesFor(), deterministic), KHONG
 * tu viet hanh dong, KHONG quyet camera. Subjective duy nhat: hero nao dang
 * chu y nhat, emotion/reveal. Xem ARCHITECTURE.md SS18.4/SS18.7.
 */
interface DirectorInterface
{
    /**
     * @param array{hero_candidates: list<string>, action_candidates: list<\App\Video\Editorial\ActionCandidate>} $candidates
     * @param int $sceneOrdinal Vị trí scene này trong toàn video (1-based) —
     *        2026-07-23, để Director biết đang ở giai đoạn nào của
     *        `producer.emotional_curve`, KHÔNG phải để tự sắp thứ tự (đó vẫn
     *        là việc của StoryPlanner/ScenePlanner, deterministic).
     * @param int $totalScenes Tổng số scene trong video.
     * @param list<array{ordinal: int, hero: string, emotion: string, composition_note: string}> $priorScenes
     *        "Trí nhớ" của Director xuyên phim (2026-07-23) — Director thật
     *        giữ trong đầu toàn bộ phim khi quay 1 cảnh, không quyết độc lập
     *        từng cảnh. Chỉ 1-3 cảnh GẦN NHẤT (VideoPlanningPipeline tự giới
     *        hạn) — KHÔNG phải toàn bộ, tránh phình prompt. Đây là context để
     *        Director đọc, KHÔNG phải dữ liệu Director được ghi đè/sửa.
     */
    public function select(array $candidates, VerifiedWorldGraph $world, ?ProducerOutput $producer, int $sceneOrdinal = 1, int $totalScenes = 1, array $priorScenes = []): ActionSelection;
}
