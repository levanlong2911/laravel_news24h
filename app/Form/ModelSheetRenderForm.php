<?php

namespace App\Form;

use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\ImageVariations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ModelSheetRenderForm
{
    /**
     * validate
     */
    public function validate(Request $request)
    {
        $validator = Validator::make($request->all(),
            [
                'view_id' => ['required', 'string', 'regex:/^MS[1-9][0-9]?$/'],
                'model' => ['required', 'string', Rule::in(ImageModel::values())],
                'size' => ['required', 'string', Rule::in(array_column(ImageSize::cases(), 'value'))],
                'quality' => ['required', 'string', Rule::in(array_column(ImageQuality::cases(), 'value'))],
                'variations' => ['required', 'integer', Rule::in(array_column(ImageVariations::cases(), 'value'))],
                'attempt' => ['required', 'uuid'],
                'prompt_sha256' => ['required', 'string', 'size:64'],
            ],
            [
                'view_id.*' => 'Góc model sheet không hợp lệ.',
                'model.*' => 'Chọn model.',
                'size.*' => 'Chọn size.',
                'quality.*' => 'Chọn quality.',
                'variations.*' => 'Chọn số ảnh.',
                'attempt.*' => 'Thiếu mã lượt render — tải lại trang rồi bấm lại.',
                'prompt_sha256.*' => 'Thiếu prompt — tải lại trang rồi bấm lại.',
            ]);

        return $validator->validate();
    }
}
