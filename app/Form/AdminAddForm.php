<?php

namespace App\Form;

use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class AdminAddForm
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
            'role' => 'required|exists:roles,id',
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:admins,email',
            'website' => 'required|exists:domains,id',
            "password" => [
                "bail",
                'regex:/^[A-Z][A-Za-z\d!@#$%^&*()_+\-=\[\]{};:"\\|,.<>\/?]*[!@#$%^&*()_+\-=\[\]{};:"\\|,.<>\/?]+.*$/', // Regex kiểm tra chữ cái đầu viết hoa và 1 ký tự đặc biệt
            ],
            'confirm_password' => 'required|same:password',
        ],
        [
            "password.regex" => __("messages.pattern"),
        ]);

        return $validator->validate();
    }
}
