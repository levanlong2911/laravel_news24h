<?php

namespace App\Form;

use App\Models\Advertisement;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
                // Rule::in(Advertisement::positions()),
            ],
            "code" => [
                "bail",
                "required"
            ],
        ]);
        return $validator->validate();
    }
}
