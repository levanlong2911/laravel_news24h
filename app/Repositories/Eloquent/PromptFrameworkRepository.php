<?php

namespace App\Repositories\Eloquent;

use App\Enums\Paginate;
use App\Models\PromptFramework;
use App\Repositories\Interfaces\PromptFrameworkRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PromptFrameworkRepository extends BaseRepository implements PromptFrameworkRepositoryInterface
{
    public function getModel(): string
    {
        return PromptFramework::class;
    }

    public function getAll(?string $name = null): LengthAwarePaginator
    {
        // newQuery() chứ không dùng $this->query: query đó khởi tạo một lần trong
        // constructor của BaseRepository nên điều kiện where sẽ dồn lại qua các lần gọi.
        $query = $this->model->newQuery()->orderBy('created_at', 'desc');

        if ($name) {
            $query->where('name', 'like', '%' . $name . '%');
        }

        return $query->paginate(Paginate::PAGE->value);
    }

    public function getById(string $id): ?PromptFramework
    {
        return $this->model->newQuery()->with('contentTypes')->find($id);
    }

    public function store(array $data): PromptFramework
    {
        return $this->model->create($data);
    }

    public function updateById(string $id, array $data): PromptFramework
    {
        $framework = $this->model->newQuery()->findOrFail($id);

        // version do PromptFrameworkObserver quản lý, không nhận từ form.
        unset($data['version']);

        $framework->update($data);

        return $framework;
    }

    /**
     * Framework từng được sửa (có prompt_versions) thì vô hiệu hoá thay vì xoá —
     * xoá sẽ kéo theo toàn bộ lịch sử phiên bản qua FK CASCADE.
     *
     * @return array{deactivated: int, deleted: int}
     */
    public function deleteByIds(array $ids): array
    {
        $ids = array_filter($ids);

        if (empty($ids)) {
            return ['deactivated' => 0, 'deleted' => 0];
        }

        $deactivated = 0;
        $deleted     = 0;

        $frameworks = $this->model->newQuery()
            ->whereIn('id', $ids)
            ->withCount('versions')
            ->get();

        foreach ($frameworks as $framework) {
            if ($framework->versions_count > 0) {
                $framework->update(['is_active' => false]);
                $deactivated++;
                continue;
            }

            $framework->delete();
            $deleted++;
        }

        return ['deactivated' => $deactivated, 'deleted' => $deleted];
    }
}
