<?php

namespace App\Video\Gatekeeper;

use App\Video\Evidence\EvidenceIndex;
use App\Video\Evidence\Value\ValueVerifier;
use App\Video\Extraction\CandidateClaim;
use App\Video\Extraction\CandidateEntity;
use App\Video\Extraction\CandidateWorldGraph;
use App\Video\World\Entity;
use App\Video\World\EntityType;
use App\Video\World\Event;
use App\Video\World\Identity;
use App\Video\World\Relation;
use App\Video\World\VerifiedAttribute;
use App\Video\World\VerifiedWorldGraph;

/**
 * TRÁI TIM của Semantic OS.
 *
 *      "Không có bằng chứng → không tồn tại."
 *
 * LLM ngày càng mạnh và sẽ bị thay. Ontology sẽ mở rộng. Provider sẽ đổi.
 * Nhưng chừng nào class này còn giữ được bất biến trên, mọi tầng phía sau —
 * Story Planner, Scene Planner, Continuity, Python Compiler — đều được quyền
 * tin rằng VerifiedWorldGraph là nguồn sự thật duy nhất.
 *
 * DETERMINISTIC 100%. Không gọi AI. Không gọi Claude. Không gọi GPT. Không I/O.
 * Cùng input luôn cho cùng output. Có Architecture Test canh điều này — nếu một
 * ngày ai đó thấy cần "hỏi LLM cho chắc" ở đây thì kiến trúc đã chết.
 *
 * Xem docs/video/ARCHITECTURE.md §11.
 */
final class EvidenceGatekeeper
{
    public function __construct(
        private readonly ValueVerifier $values = new ValueVerifier(),
    ) {
    }

    public function verify(CandidateWorldGraph $candidates, EvidenceIndex $index): GatekeeperReport
    {
        $rejections = [];
        $entities   = [];
        $counted    = 0;

        foreach ($candidates->entities as $candidate) {
            $counted++;
            $entity = $this->verifyEntity($candidate, $index, $rejections, $counted);

            if ($entity !== null) {
                $entities[$entity->id] = $entity;
            }
        }

        // Relation/Event chỉ được xét SAU khi entity đã chốt: một quan hệ trỏ
        // tới entity đã bị loại là quan hệ treo, và giữ nó lại sẽ đẻ ra dangling
        // ref trong RenderPlan mà mãi tới Python mới nổ.
        $relations = $this->verifyRelations($candidates, $index, $entities, $rejections, $counted);
        $events    = $this->verifyEvents($candidates, $index, $entities, $rejections, $counted);

        return new GatekeeperReport(
            new VerifiedWorldGraph(array_values($entities), $relations, $events),
            $rejections,
            $counted,
        );
    }

    /**
     * @param list<Rejection> $rejections
     */
    private function verifyEntity(CandidateEntity $candidate, EvidenceIndex $index, array &$rejections, int &$counted): ?Entity
    {
        $type = EntityType::tryFrom($candidate->type);

        if ($type === null) {
            $rejections[] = new Rejection(
                "entity:{$candidate->id}",
                RejectionReason::UnknownEntityType,
                "'{$candidate->type}' không có trong ontology chung — chi tiết riêng của chủ đề thuộc về attributes, không thuộc về type",
            );

            return null;
        }

        /** @var array<string, list<VerifiedAttribute>> $attributes */
        $attributes = [];

        foreach ($candidate->claims as $claim) {
            $counted++;
            $verified = $this->verifyClaim($claim, $index, $rejections);

            if ($verified === null) {
                continue;
            }

            // Một tên MANG NHIỀU giá trị là chuyện thường: beach club VÀ spa VÀ
            // helipad. Trước đây code này gọi đó là mâu thuẫn và vứt hết trừ cái
            // đầu — một con tàu mất sạch tiện nghi vì model của tôi giả định mỗi
            // thuộc tính đúng một giá trị.
            //
            // Chỉ chống trùng lặp y hệt (Claude hay nhắc lại cùng một sự thật ở
            // hai chỗ trong bài).
            foreach ($attributes[$claim->attribute] ?? [] as $existing) {
                if ($existing->value === $verified->value) {
                    continue 2;
                }
            }

            $attributes[$claim->attribute][] = $verified;
        }

        $identity = $this->verifyIdentity($candidate, $index);

        // Entity tồn tại KHÔNG đồng nghĩa với entity có thứ để render.
        //
        // Feadship có tên trong bài và neo quan hệ "Moonrise built_by Feadship",
        // nhưng bài không mô tả thuộc tính nào của chính Feadship. Luật cũ
        // ("hết claim thì loại") đã ném mất Feadship, De Voogt, Remi Tessier —
        // kéo theo 5 quan hệ thành dangling, trong đó có sự thật quan trọng nhất
        // của bài báo.
        if ($attributes === [] && $identity === null) {
            $rejections[] = new Rejection(
                "entity:{$candidate->id}",
                RejectionReason::NoVerifiedClaims,
                'không có tên truy được về bài, cũng không có thuộc tính nào — không có bằng chứng nào cho thấy nó tồn tại',
            );

            return null;
        }

        return new Entity($candidate->id, $type, $attributes, $identity);
    }

    /**
     * @param list<Rejection> $rejections
     */
    private function verifyClaim(CandidateClaim $claim, EvidenceIndex $index, array &$rejections): ?VerifiedAttribute
    {
        $subject = "{$claim->entityId}.{$claim->attribute}";

        if (trim($claim->evidenceQuote) === '') {
            $rejections[] = new Rejection($subject, RejectionReason::NoEvidence);

            return null;
        }

        // Bước 1 — đoạn chữ này có THẬT trong bài không? Gatekeeper tự tìm;
        // LLM không được cấp offset vì nó đếm ký tự rất tệ và sẽ bịa ra offset
        // trông hợp lý. Tìm không thấy = LLM bịa cả câu trích.
        $evidence = $index->find($claim->evidenceQuote);

        if ($evidence === null) {
            $rejections[] = new Rejection(
                $subject,
                RejectionReason::QuoteNotFound,
                sprintf('"%s"', mb_strimwidth($claim->evidenceQuote, 0, 60, '…')),
            );

            return null;
        }

        // Bước 2 — đoạn chữ đó có NÓI LÊN giá trị này không? Thiếu bước này thì
        // LLM chỉ cần trích một câu có thật rồi gắn vào bất kỳ giá trị nào.
        $level = $this->values->verify($claim->evidenceQuote, $claim->value);

        if ($level === null) {
            $rejections[] = new Rejection(
                $subject,
                RejectionReason::ValueNotSupported,
                sprintf('quote "%s" không suy ra được %s', mb_strimwidth($claim->evidenceQuote, 0, 40, '…'), var_export($claim->value, true)),
            );

            return null;
        }

        return new VerifiedAttribute($claim->attribute, $claim->value, $evidence, $level);
    }

    private function verifyIdentity(CandidateEntity $candidate, EvidenceIndex $index): ?Identity
    {
        if ($candidate->name === null || trim($candidate->nameQuote) === '') {
            return null;
        }

        $evidence = $index->find($candidate->nameQuote);

        if ($evidence === null) {
            return null; // tên không truy được về bài ⇒ entity vô danh, không phải lỗi
        }

        // visualReferent là phán đoán NGỮ NGHĨA thuần: tên riêng có ghim xuống
        // một hình dạng cụ thể. Đây KHÔNG phải khẳng định model AI biết cái tên
        // đó — quyết định ấy nằm ở ProviderPass allowlist bên Python. §4.
        return new Identity(
            $candidate->name,
            $this->namesAVisualReferent($candidate->type),
            $evidence,
        );
    }

    /**
     * Chỉ những thứ có hình dạng vật lý mới ghim được ngoại hình bằng tên riêng.
     * Một con người có tên nhưng cái tên không cho biết họ trông thế nào —
     * "Jan Koum" không mô tả gì cả.
     */
    private function namesAVisualReferent(string $type): bool
    {
        return in_array($type, [
            EntityType::Vehicle->value,
            EntityType::Building->value,
            EntityType::Landscape->value,
            EntityType::PhysicalObject->value,
        ], true);
    }

    /**
     * @param array<string, Entity> $entities
     * @param list<Rejection>       $rejections
     * @return list<Relation>
     */
    private function verifyRelations(CandidateWorldGraph $candidates, EvidenceIndex $index, array $entities, array &$rejections, int &$counted): array
    {
        $relations = [];

        foreach ($candidates->relations as $candidate) {
            $counted++;
            $subject = "relation:{$candidate->id}";

            if (! isset($entities[$candidate->from]) || ! isset($entities[$candidate->to])) {
                $rejections[] = new Rejection($subject, RejectionReason::DanglingReference, "{$candidate->from} → {$candidate->to}");

                continue;
            }

            // Quan hệ phải được BÀI BÁO nói ra. LLM thấy hai entity cùng xuất
            // hiện rồi suy ra successor_of → đó là suy luận, không phải trích xuất.
            $evidence = $index->find($candidate->evidenceQuote);

            if ($evidence === null) {
                $rejections[] = new Rejection(
                    $subject,
                    RejectionReason::QuoteNotFound,
                    sprintf('"%s" — quan hệ do LLM tự suy ra', mb_strimwidth($candidate->evidenceQuote, 0, 50, '…')),
                );

                continue;
            }

            $relations[] = new Relation($candidate->id, $candidate->from, $candidate->to, $candidate->type, $evidence);
        }

        return $relations;
    }

    /**
     * @param array<string, Entity> $entities
     * @param list<Rejection>       $rejections
     * @return list<Event>
     */
    private function verifyEvents(CandidateWorldGraph $candidates, EvidenceIndex $index, array $entities, array &$rejections, int &$counted): array
    {
        $events = [];

        foreach ($candidates->events as $candidate) {
            $counted++;
            $subject = "event:{$candidate->id}";

            if (! isset($entities[$candidate->entityId])) {
                $rejections[] = new Rejection($subject, RejectionReason::DanglingReference, $candidate->entityId);

                continue;
            }

            // Event `construction` KHÔNG sinh ra vì entity là `vehicle`. Bài báo
            // phải thật sự nói tới việc đóng/hạ thủy/bàn giao.
            $evidence = $index->find($candidate->evidenceQuote);

            if ($evidence === null) {
                $rejections[] = new Rejection(
                    $subject,
                    RejectionReason::QuoteNotFound,
                    sprintf('"%s" — sự kiện suy ra từ loại entity, không từ bài báo', mb_strimwidth($candidate->evidenceQuote, 0, 50, '…')),
                );

                continue;
            }

            $events[] = new Event($candidate->id, $candidate->type, $candidate->entityId, $evidence);
        }

        return $events;
    }
}
