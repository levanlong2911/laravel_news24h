<?php

namespace App\Form;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SceneKeyframeApproveForm
{
    /**
     * validate
     */
    public function validate(Request $request)
    {
        $validator = Validator::make($request->all(),
            [
                'artifact_id' => ['required', 'uuid'],
            ],
            [
                'artifact_id.required' => __('messages.scene_keyframe_pick_candidate'),
                'artifact_id.uuid' => __('messages.scene_keyframe_pick_candidate'),
            ]);

        return $validator->validate();
    }
}
