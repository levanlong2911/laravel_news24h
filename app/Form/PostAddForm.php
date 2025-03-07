<?php

namespace App\Form;

use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class PostAddForm
{
    /**
     * validate
     *
     * @param \Illuminate\Http\Request $request
     */
    public function validate(Request $request)
    {
        $validator = Validator::make($request->all(),
        [
            "title" => [
                "bail",
                "required"
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
        // dd($validator->validate());

        return $validator->validate();
    }
}
