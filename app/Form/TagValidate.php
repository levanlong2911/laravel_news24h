<?php

namespace App\Form;

use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class TagValidate
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
            'category_id' => 'required|exists:categories,id',
            'tags' => 'required|string',
        ]);
        // [
        //     "email.required" => __("messages.user_id_required"),
        //     "email.email" => __("messages.email_invalid"),
        //     "password.required" => __("messages.password_required"),
        //     "password.min" => __("messages.minlength"),
        //     "password.regex" => __("messages.pattern"),
        // ]);
        dd($validator->validate());

        return $validator->validate();
    }
}
