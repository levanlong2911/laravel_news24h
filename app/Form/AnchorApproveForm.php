<?php

namespace App\Form;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AnchorApproveForm
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
                'artifact_id.required' => __('messages.anchor_pick_candidate'),
                'artifact_id.uuid' => __('messages.anchor_pick_candidate'),
            ]);

        return $validator->validate();
    }
}
