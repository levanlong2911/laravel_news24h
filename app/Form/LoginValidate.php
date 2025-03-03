<?php

namespace App\Form;

use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class LoginValidate
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
            "email" => [
                "bail",
                "required",
                "email"
            ],
            "password" => [
                "bail",
                "required",
                "string",
                'min:8', // Ít nhất 8 ký tự
                'regex:/^[A-Z][A-Za-z\d!@#$%^&*()_+\-=\[\]{};:"\\|,.<>\/?]*[!@#$%^&*()_+\-=\[\]{};:"\\|,.<>\/?]+.*$/', // Regex kiểm tra chữ cái đầu viết hoa và 1 ký tự đặc biệt
            ],
        ],
        [
            "email.required" => __("messages.user_id_required"),
            "email.email" => __("messages.email_invalid"),
            "password.required" => __("messages.password_required"),
            "password.min" => __("messages.minlength"),
            "password.regex" => __("messages.pattern"),
        ]);

        return $validator->validate();
    }
}
