<?php

namespace App\Video\World;

/**
 * Trusted Truth. Nguồn sự thật DUY NHẤT cho mọi tầng phía sau.
 *
 * Từ điểm này trở đi không còn giả thuyết: Story Planner, Scene Planner,
 * Continuity và Python Compiler đều được quyền tin tuyệt đối vào graph này.
 * Đó chính là thứ Evidence Gatekeeper mua về.
 *
 * Chỉ EvidenceGatekeeper được tạo ra nó.
 */
final class VerifiedWorldGraph
{
    /** @var array<string, Entity> */
    private array $entities;

    /**
     * @param list<Entity>   $entities
     * @param list<Relation> $relations
     * @param list<Event>    $events
     */
    public function __construct(
        array $entities = [],
        public readonly array $relations = [],
        public readonly array $events = [],
    ) {
        $this->entities = [];

        foreach ($entities as $entity) {
            $this->entities[$entity->id] = $entity;
        }
    }

    /** @return list<Entity> */
    public function entities(): array
    {
        return array_values($this->entities);
    }

    public function entity(string $id): ?Entity
    {
        return $this->entities[$id] ?? null;
    }

    public function hasEntity(string $id): bool
    {
        return isset($this->entities[$id]);
    }

    public function isEmpty(): bool
    {
        return $this->entities === [];
    }

    /**
     * Kiểm tra một quyết định của Planner có mâu thuẫn sự thật không.
     *
     * Đây là ràng buộc DUY NHẤT áp lên Planning Layer: quyết định đạo diễn
     * không cần bằng chứng, nhưng không được nói ngược lại thế giới đã verify.
     * Xem ARCHITECTURE.md §1 "Truth Layer ⊥ Planning Layer".
     */
    public function contradicts(string $entityId, string $attribute, mixed $value): bool
    {
        $entity = $this->entity($entityId);

        if ($entity === null || ! $entity->has($attribute)) {
            return false; // chưa biết thì không mâu thuẫn — im lặng ≠ phủ định
        }

        return $entity->value($attribute) !== $value;
    }
}
