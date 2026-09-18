<?php

namespace App\Form;

use App\Enums\ImageModel;
use App\Enums\ImageSize;
use App\Video\Concept\Viewpoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AnchorPromptForm
{
    /**
     * validate
     */
    public function validate(Request $request)
    {
        $validator = Validator::make($request->all(),
            [
                'viewpoint' => ['required', Rule::enum(Viewpoint::class)],
                'size' => ['required', Rule::enum(ImageSize::class)],
                'model' => ['required', Rule::enum(ImageModel::class)],
            ],
            [
                'viewpoint.required' => __('messages.anchor_setting_required', ['field' => 'Viewpoint']),
                'viewpoint.*' => __('messages.anchor_setting_invalid', ['field' => 'Viewpoint']),
                'size.required' => __('messages.anchor_setting_required', ['field' => 'Size']),
                'size.*' => __('messages.anchor_setting_invalid', ['field' => 'Size']),
                'model.required' => __('messages.anchor_setting_required', ['field' => 'Model']),
                'model.*' => __('messages.anchor_setting_invalid', ['field' => 'Model']),
            ]);

        return $validator->validate();
    }
}
