<?php

namespace App\Services\Admin;

use App\Enums\Paginate;
use App\Models\ConvertFont;
use App\Repositories\Interfaces\FontRepositoryInterface;
use Exception;
use Illuminate\Support\Facades\DB;

class FontService
{
    private FontRepositoryInterface $fontRepository;

    public function __construct(
        FontRepositoryInterface $fontRepository,
    )
    {
        $this->fontRepository = $fontRepository;
    }

    public function getListFont()
    {
        return $this->fontRepository->getListFont();
    }

    public function getListFontIds()
    {
        return $this->fontRepository->all();
    }

    public function addConvertFont($request)
    {
        $input = $request->all();
        DB::beginTransaction();
        try {
            $finds = array_map('trim', explode(',', $input['find']));
            $replaces = array_map('trim', explode(',', $input['replace']));

            // Đảm bảo hai mảng có cùng số phần tử
            if (count($finds) !== count($replaces)) {
                return back()->with('error', 'Số lượng từ cần thay và từ thay thế không khớp.');
            }

            foreach ($finds as $index => $find) {
                $find = $find;
                $replace = $replaces[$index];

                // Kiểm tra xem đã tồn tại chưa, nếu chưa thì tạo
                ConvertFont::updateOrCreate(
                    ['find' => $find],
                    ['replace' => $replace]
                );
            }
            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollback();
            return false;
        }
    }

    public function getByIdFont($id)
    {
        return $this->fontRepository->find($id);
    }

    /**
     * Xóa một danh mục
     *
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    public function deleteFontByIds($request)
    {
        DB::beginTransaction();
        try {
            $dataFont = $this->fontRepository->getDataListIds($request->ids);
            foreach ($dataFont as $font) {
                $font->delete();
            }
            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollback();
            return false;
        }
    }

}
