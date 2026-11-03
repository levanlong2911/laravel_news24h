<?php

namespace App\Repositories\Interfaces;

use App\Models\PromptFramework;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * store() / updateById() cố tình không đặt tên create() / update():
 * BaseRepository đã có sẵn hai tên đó với chữ ký khác — update() trả bool —
 * nên đặt trùng sẽ xung đột kiểu trả về và gây fatal error lúc load class.
 */
interface PromptFrameworkRepositoryInterface extends RepositoryInterface
{
    public function getAll(?string $name = null): LengthAwarePaginator;

    public function getById(string $id): ?PromptFramework;

    public function store(array $data): PromptFramework;

    public function updateById(string $id, array $data): PromptFramework;

    /**
     * @return array{deactivated: int, deleted: int}
     */
    public function deleteByIds(array $ids): array;
}
