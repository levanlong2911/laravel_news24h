<?php

namespace App\Services\Admin;

use App\Models\PromptFramework;
use App\Repositories\Interfaces\PromptFrameworkRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PromptFrameworkService
{
    public function __construct(
        private PromptFrameworkRepositoryInterface $repository,
    ) {}

    public function getAll(?string $name = null): LengthAwarePaginator
    {
        return $this->repository->getAll($name);
    }

    public function getById(string $id): ?PromptFramework
    {
        return $this->repository->getById($id);
    }

    public function create(array $data): bool
    {
        try {
            $this->repository->store($data);

            return true;
        } catch (Throwable $e) {
            // Log trước khi nuốt: controller chỉ hiện được "lỗi khi tạo", không mang
            // theo nguyên nhân. Không ghi lại thì lỗi biến mất hoàn toàn.
            Log::error('[PromptFramework] Tạo thất bại', [
                'exception' => $e->getMessage(),
                'fields'    => array_keys($data),
            ]);

            return false;
        }
    }

    public function update(string $id, array $data): bool
    {
        try {
            $this->repository->updateById($id, $data);

            return true;
        } catch (Throwable $e) {
            Log::error('[PromptFramework] Cập nhật thất bại', [
                'id'        => $id,
                'exception' => $e->getMessage(),
                'fields'    => array_keys($data),
            ]);

            return false;
        }
    }

    /**
     * @return array{deactivated: int, deleted: int}
     *
     * @throws RuntimeException khi còn category_context tham chiếu (FK RESTRICT)
     */
    public function deleteByIds(array $ids): array
    {
        try {
            return $this->repository->deleteByIds($ids);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'FOREIGN KEY')) {
                Log::warning('[PromptFramework] Xoá bị FK chặn', ['ids' => $ids]);

                throw new RuntimeException(
                    'Một số framework đang được danh mục sử dụng. Vô hiệu hoá hoặc cập nhật danh mục trước.'
                );
            }

            throw $e;
        }
    }
}
