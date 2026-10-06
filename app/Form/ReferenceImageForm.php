<?php

namespace App\Form;

use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\ImageVariations;
use App\Video\Reference\ReferenceEnvironment;
use App\Video\Reference\ReferenceView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ReferenceImageForm
{
    /**
     * validate
     */
    public function validate(Request $request, string $mode = 'render')
    {
        if ($mode === 'prompt') {
            return Validator::make($request->all(),
                [
                    'view' => ['required', Rule::enum(ReferenceView::class)],
                ],
                [
                    'view.required' => __('messages.anchor_setting_required', ['field' => 'View']),
                    'view.*' => __('messages.anchor_setting_invalid', ['field' => 'View']),
                ])->validate();
        }

        $validator = Validator::make($request->all(),
            [
                'reference_prompt_stage_id' => ['required', 'string', 'max:64'],
                'prompt_sha256' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
                'view' => ['required', Rule::enum(ReferenceView::class)],
                'environment' => ['required', Rule::enum(ReferenceEnvironment::class)],
                'model' => ['required', Rule::enum(ImageModel::class)],
                'quality' => ['required', Rule::enum(ImageQuality::class), static function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    $model = ImageModel::tryFrom((string) $request->input('model'));
                    $quality = ImageQuality::tryFrom((string) $value);

                    if ($model !== null && $quality !== null && ! $model->supports($quality)) {
                        $fail("Quality {$quality->label()} không dùng được với model {$model->label()}.");
                    }
                }],
                'size' => ['required', Rule::enum(ImageSize::class)],
                'variations' => ['required', Rule::enum(ImageVariations::class)],
            ],
            [
                'reference_prompt_stage_id.required' => 'Chưa có prompt do AI viết cho góc này — bấm "AI viết prompt" trước.',
                'reference_prompt_stage_id.*' => 'Mã prompt reference không hợp lệ.',
                'prompt_sha256.required' => 'Thiếu mã băm của prompt đang xem trước.',
                'prompt_sha256.*' => 'Mã băm của prompt đang xem trước không hợp lệ.',
                'view.required' => __('messages.anchor_setting_required', ['field' => 'View']),
                'view.*' => __('messages.anchor_setting_invalid', ['field' => 'View']),
                'environment.required' => __('messages.anchor_setting_required', ['field' => 'Environment']),
                'environment.*' => __('messages.anchor_setting_invalid', ['field' => 'Environment']),
                'model.required' => __('messages.anchor_setting_required', ['field' => 'Model']),
                'model.*' => __('messages.anchor_setting_invalid', ['field' => 'Model']),
                'quality.required' => __('messages.anchor_setting_required', ['field' => 'Quality']),
                'quality.'.Enum::class => __('messages.anchor_setting_invalid', ['field' => 'Quality']),
                'size.required' => __('messages.anchor_setting_required', ['field' => 'Size']),
                'size.*' => __('messages.anchor_setting_invalid', ['field' => 'Size']),
                'variations.required' => __('messages.anchor_setting_required', ['field' => 'Variations']),
                'variations.*' => __('messages.anchor_setting_invalid', ['field' => 'Variations']),
            ]);

        return $validator->validate();
    }
}
