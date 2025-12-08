<?php

namespace App\Form;

use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class PostUpdateForm
{
    /**
     * validate
     *
     * @param \Illuminate\Http\Request $request
     */
    public function validate(Request $request, $id = null)
    {
        $validator = Validator::make($request->all(),
        [
            "title" => [
                "bail",
                "required",
            ],
            "editor_content" => [
                "bail",
                "required"
            ],
            "category" => [
                "bail",
                "required"
            ],
            "tag" => [
                "bail",
                "required"
            ],
            'image' => [
                'required',
                'url',
            ],
        ]);

        return $validator->validate();
    }
}
