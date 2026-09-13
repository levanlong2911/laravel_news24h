<?php

namespace App\Form;

use App\Video\Environment\EnvironmentPlatePrompt;
use App\Video\Media\MediaModelRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EnvironmentImageForm
{
    /** @var list<string> */
    private const ROUTING_FIELDS = ['provider', 'model', 'api_version', 'shape', 'pricing'];

    /** @var array<string, array<string, string>> */
    private const CONTROL_FIELDS = [
        'openai' => ['size' => 'sizes', 'quality' => 'qualities'],
        'gemini' => ['aspect_ratio' => 'aspect_ratios', 'image_size' => 'image_sizes'],
    ];

    /**
     * validate
     *
     * @throws \InvalidArgumentException registry model hong
     */
    public function validate(Request $request)
    {
        $models = app(MediaModelRegistry::class)->forTask(EnvironmentPlatePrompt::TASK);
        $chosenId = $request->input('provider_model');
        $chosen = null;

        foreach ($models as $entry) {
            if ($entry['id'] === $chosenId) {
                $chosen = $entry;
            }
        }

        $rules = [
            'environment_key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{2,59}$/'],
            'provider_model' => ['required', 'string', Rule::in(array_column($models, 'id'))],
        ];

        foreach (self::ROUTING_FIELDS as $field) {
            $rules[$field] = ['prohibited'];
        }

        if ($chosen !== null) {
            $rules['variations'] = ['required', 'integer', 'between:1,'.$chosen['max_variations']];

            foreach (self::CONTROL_FIELDS as $provider => $fields) {
                foreach ($fields as $field => $list) {
                    $rules[$field] = $provider === $chosen['provider']
                        ? ['required', 'string', Rule::in($chosen['controls'][$list])]
                        : ['prohibited'];
                }
            }
        }

        $messages = [
            'environment_key.required' => __('messages.anchor_setting_required', ['field' => 'Environment']),
            'environment_key.*' => __('messages.anchor_setting_invalid', ['field' => 'Environment']),
            'provider_model.required' => __('messages.anchor_setting_required', ['field' => 'Model']),
            'provider_model.*' => __('messages.anchor_setting_invalid', ['field' => 'Model']),
            'variations.required' => __('messages.anchor_setting_required', ['field' => 'Variations']),
            'variations.*' => __('messages.anchor_setting_invalid', ['field' => 'Variations']),
            'prohibited' => __('messages.environment_setting_not_allowed'),
        ];

        foreach ([
            'size' => 'Size',
            'quality' => 'Quality',
            'aspect_ratio' => 'Aspect ratio',
            'image_size' => 'Image size',
        ] as $field => $label) {
            $messages[$field.'.required'] = __('messages.anchor_setting_required', ['field' => $label]);
            $messages[$field.'.string'] = __('messages.anchor_setting_invalid', ['field' => $label]);
            $messages[$field.'.in'] = __('messages.anchor_setting_invalid', ['field' => $label]);
        }

        return Validator::make($request->all(), $rules, $messages)->validate();
    }
}
