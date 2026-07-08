<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Graph;

/**
 * Graph Platform service locator — cấp service, không làm việc.
 *
 * Mỗi accessor trả về service instance (lazy init).
 * Không delegate, không proxy — caller phải biết mình cần service nào.
 *
 * Pattern:
 *   $engine = GraphEngine::make();
 *   $sorted = $engine->algorithms()->topoSort($graph);
 *   $roots  = $engine->query()->sources($graph);
 *   $json   = $engine->serializer()->toJson($graph);
 *   $errors = $engine->validator()->validate($graph);
 *
 * Khi GraphPlatform mở rộng (TemporalGraph, KnowledgeGraph, ExecutionGraph...),
 * GraphEngine chỉ thêm accessor mới — không bao giờ thêm business logic.
 */
final class GraphEngine
{
    private ?GraphAlgorithmsService  $algorithms  = null;
    private ?GraphQueryService       $query       = null;
    private ?GraphSerializerService  $serializer  = null;
    private ?GraphValidationService  $validation  = null;
    private ?GraphTraversalService   $traversal   = null;

    public static function make(): self
    {
        return new self();
    }

    public function algorithms(): GraphAlgorithmsService
    {
        return $this->algorithms ??= new GraphAlgorithmsService();
    }

    public function query(): GraphQueryService
    {
        return $this->query ??= new GraphQueryService();
    }

    public function serializer(): GraphSerializerService
    {
        return $this->serializer ??= new GraphSerializerService();
    }

    public function validator(): GraphValidationService
    {
        return $this->validation ??= new GraphValidationService();
    }

    public function traversal(): GraphTraversalService
    {
        return $this->traversal ??= new GraphTraversalService();
    }

    /**
     * Replace a service — dùng trong tests để inject mock.
     */
    public function withAlgorithms(GraphAlgorithmsService $svc): self
    {
        $clone = clone $this;
        $clone->algorithms = $svc;
        return $clone;
    }

    public function withQuery(GraphQueryService $svc): self
    {
        $clone = clone $this;
        $clone->query = $svc;
        return $clone;
    }

    public function withValidator(GraphValidationService $svc): self
    {
        $clone = clone $this;
        $clone->validation = $svc;
        return $clone;
    }
}
