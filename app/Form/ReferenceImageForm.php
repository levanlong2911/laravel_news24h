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

class ReferenceImageForm
{
    /**
     * validate
     */
    public function validate(Request $request)
    {
        $validator = Validator::make($request->all(),
            [
                'view' => ['required', Rule::enum(ReferenceView::class)],
                'environment' => ['required', Rule::enum(ReferenceEnvironment::class)],
                'model' => ['required', Rule::enum(ImageModel::class)],
                'quality' => ['required', Rule::enum(ImageQuality::class)],
                'size' => ['required', Rule::enum(ImageSize::class)],
                'variations' => ['required', Rule::enum(ImageVariations::class)],
            ],
            [
                'view.required' => __('messages.anchor_setting_required', ['field' => 'View']),
                'view.*' => __('messages.anchor_setting_invalid', ['field' => 'View']),
                'environment.required' => __('messages.anchor_setting_required', ['field' => 'Environment']),
                'environment.*' => __('messages.anchor_setting_invalid', ['field' => 'Environment']),
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
