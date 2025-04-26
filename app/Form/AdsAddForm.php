<?php

namespace App\Form;

use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class AdsAddForm
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
            "name" => [
                "bail",
                "required",
                "string",
                "max:255"
            ],
            "position" => [
                "bail",
                "required",
                "in:top,middle,bottom"
            ],
            "code" => [
                "bail",
                "required"
            ],
        ]);
        return $validator->validate();
    }
}
