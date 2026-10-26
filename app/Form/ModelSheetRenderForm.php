<?php

namespace App\Form;

use App\Enums\ImageModel;
use App\Enums\ImageQuality;
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
            // Kich thuoc (3840x2160) va so anh (1) co dinh o server theo config master.
            [
                'model' => ['required', 'string', Rule::in(ImageModel::values())],
                'quality' => ['required', 'string', Rule::in(array_column(ImageQuality::cases(), 'value'))],
                'attempt' => ['required', 'uuid'],
                'qa_stage_id' => ['required', 'uuid'],
                'prompt_sha256' => ['required', 'string', 'size:64'],
            ],
            [
                'model.*' => 'Chọn model.',
                'quality.*' => 'Chọn quality.',
                'attempt.*' => 'Thiếu mã lượt render — tải lại trang rồi bấm lại.',
                'qa_stage_id.*' => 'Chưa có lượt QA prompt đạt kiểm — bấm "QA & hoàn thiện prompt" trước.',
                'prompt_sha256.*' => 'Thiếu prompt — tải lại trang rồi bấm lại.',
            ]);

        return $validator->validate();
    }
}
