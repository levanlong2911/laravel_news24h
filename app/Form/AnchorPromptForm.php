<?php

namespace App\Form;

use App\Enums\AnchorStage;
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
                'stage' => ['required', Rule::enum(AnchorStage::class)],
                'viewpoint' => ['required', Rule::enum(Viewpoint::class)],
                'size' => ['required', Rule::enum(ImageSize::class)],
            ],
            [
                'stage.required' => __('messages.anchor_setting_required', ['field' => 'Stage']),
                'stage.*' => __('messages.anchor_setting_invalid', ['field' => 'Stage']),
                'viewpoint.required' => __('messages.anchor_setting_required', ['field' => 'Viewpoint']),
                'viewpoint.*' => __('messages.anchor_setting_invalid', ['field' => 'Viewpoint']),
                'size.required' => __('messages.anchor_setting_required', ['field' => 'Size']),
                'size.*' => __('messages.anchor_setting_invalid', ['field' => 'Size']),
            ]);

        return $validator->validate();
    }
}
