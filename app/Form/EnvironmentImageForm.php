<?php

namespace App\Form;

use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\ImageVariations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EnvironmentImageForm
{
    /**
     * validate
     */
    public function validate(Request $request)
    {
        $validator = Validator::make($request->all(),
            [
                'environment_key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{2,59}$/'],
                'model' => ['required', Rule::enum(ImageModel::class)],
                'quality' => ['required', Rule::enum(ImageQuality::class)],
                'size' => ['required', Rule::enum(ImageSize::class)],
                'variations' => ['required', Rule::enum(ImageVariations::class)],
            ],
            [
                'environment_key.required' => __('messages.anchor_setting_required', ['field' => 'Environment']),
                'environment_key.*' => __('messages.anchor_setting_invalid', ['field' => 'Environment']),
                'model.required' => __('messages.anchor_setting_required', ['field' => 'Model']),
                'model.*' => __('messages.anchor_setting_invalid', ['field' => 'Model']),
                'quality.required' => __('messages.anchor_setting_required', ['field' => 'Quality']),
                'quality.*' => __('messages.anchor_setting_invalid', ['field' => 'Quality']),
                'size.required' => __('messages.anchor_setting_required', ['field' => 'Size']),
                'size.*' => __('messages.anchor_setting_invalid', ['field' => 'Size']),
                'variations.required' => __('messages.anchor_setting_required', ['field' => 'Variations']),
                'variations.*' => __('messages.anchor_setting_invalid', ['field' => 'Variations']),
            ]);

        return $validator->validate();
    }
}
