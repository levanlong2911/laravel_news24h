<?php

namespace App\Form;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SceneKeyframeRenderForm
{
    /**
     * validate
     */
    public function validate(Request $request)
    {
        $validator = Validator::make($request->all(),
            [
                'prompt_sha256' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
                'anchor_artifact_id' => ['nullable', 'uuid'],
            ],
            [
                'prompt_sha256.required' => __('messages.scene_keyframe_preview_stale'),
                'prompt_sha256.string' => __('messages.scene_keyframe_preview_stale'),
                'prompt_sha256.regex' => __('messages.scene_keyframe_preview_stale'),
                'anchor_artifact_id.uuid' => __('messages.scene_keyframe_anchor_confirmation_stale'),
            ]);

        return $validator->validate();
    }
}
