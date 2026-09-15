<?php

namespace App\Form;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SceneClipRenderForm
{
    /**
     * validate
     */
    public function validate(Request $request)
    {
        $validator = Validator::make($request->all(),
            [
                'model_id' => ['required', 'string', 'max:120'],
                'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:60'],
                'aspect_ratio' => ['nullable', 'string', 'max:16'],
                'resolution' => ['nullable', 'string', 'max:16'],
            ]);

        return $validator->validate();
    }
}
