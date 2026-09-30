<?php

namespace App\Form;

use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\ImageVariations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class AnchorImageForm
{
    /**
     * validate
     */
    public function validate(Request $request)
    {
        $validator = Validator::make($request->all(),
            [
                'size' => ['required', Rule::enum(ImageSize::class)],
                'model' => ['required', Rule::enum(ImageModel::class)],
                'quality' => ['required', Rule::enum(ImageQuality::class), static function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    $model = ImageModel::tryFrom((string) $request->input('model'));
                    $quality = ImageQuality::tryFrom((string) $value);

                    if ($model !== null && $quality !== null && ! $model->supports($quality)) {
                        $fail("Quality {$quality->label()} không dùng được với model {$model->label()}.");
                    }
                }],
                'variations' => ['required', Rule::enum(ImageVariations::class)],
                'prompt_sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
                'character_id' => ['nullable', 'string', 'regex:/^ch_[a-z0-9_]{1,56}$/'],
            ],
            [
                'size.required' => __('messages.anchor_setting_required', ['field' => 'Size']),
                'size.*' => __('messages.anchor_setting_invalid', ['field' => 'Size']),
                'model.required' => __('messages.anchor_setting_required', ['field' => 'Model']),
                'model.*' => __('messages.anchor_setting_invalid', ['field' => 'Model']),
                'quality.required' => __('messages.anchor_setting_required', ['field' => 'Quality']),
                'quality.'.Enum::class => __('messages.anchor_setting_invalid', ['field' => 'Quality']),
                'variations.required' => __('messages.anchor_setting_required', ['field' => 'Variations']),
                'variations.*' => __('messages.anchor_setting_invalid', ['field' => 'Variations']),
                'prompt_sha256.required' => __('messages.anchor_prompt_missing'),
                'prompt_sha256.regex' => __('messages.anchor_prompt_stale'),
            ]);

        return $validator->validate();
    }
}
