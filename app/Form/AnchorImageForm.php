<?php

namespace App\Form;

use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Enums\ImageVariations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AnchorImageForm
{
    /**
     * validate
     */
    public function validate(Request $request)
    {
        $validator = Validator::make($request->all(),
            [
                'model' => ['required', Rule::enum(ImageModel::class)],
                'quality' => ['required', Rule::enum(ImageQuality::class)],
                'variations' => ['required', Rule::enum(ImageVariations::class)],
                'prompt_sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            ],
            [
                'model.required' => __('messages.anchor_setting_required', ['field' => 'Model']),
                'model.*' => __('messages.anchor_setting_invalid', ['field' => 'Model']),
                'quality.required' => __('messages.anchor_setting_required', ['field' => 'Quality']),
                'quality.*' => __('messages.anchor_setting_invalid', ['field' => 'Quality']),
                'variations.required' => __('messages.anchor_setting_required', ['field' => 'Variations']),
                'variations.*' => __('messages.anchor_setting_invalid', ['field' => 'Variations']),
                'prompt_sha256.required' => __('messages.anchor_prompt_missing'),
                'prompt_sha256.regex' => __('messages.anchor_prompt_stale'),
            ]);

        return $validator->validate();
    }
}
