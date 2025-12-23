<?php

namespace App\Form;

use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class AdminUpdateForm
{
    /**
     * validate
     *
     * @param \Illuminate\Http\Request $request
     */
    public function validate(Request $request)
    {
        // Điều kiện xác thực
        $rules = [
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:admins,email,' . $request->id,
            'role' => 'required|exists:roles,id',
            'domain' => 'required|string',
        ];

        // Trường hợp không phải là cập nhật hoặc có yêu cầu thay đổi mật khẩu
        if ($request->filled('password')) {
            $rules['password'] = [
                "bail",
                'required',
                'min:8',
                'regex:/^[A-Z][A-Za-z\d!@#$%^&*()_+\-=\[\]{};:"\\|,.<>\/?]*[!@#$%^&*()_+\-=\[\]{};:"\\|,.<>\/?]+.*$/', // Regex kiểm tra chữ cái đầu viết hoa và 1 ký tự đặc biệt
            ];
            $rules['confirm_password'] = 'required|same:password';
        }

        // Thông báo lỗi
        $messages = [
            "password.regex" => __("messages.pattern"),
        ];

        // Thực hiện validate
        $validator = Validator::make($request->all(), $rules, $messages);

        return $validator->validate();

    }
}

// $validator = Validator::make($request->all(),
        // [
        //     'name' => 'required|string|max:100',
        //     'email' => 'required|email|unique:admins,email',
        //     'role' => 'required|exists:roles,id',
        //     "password" => [
        //         "bail",
        //         'regex:/^[A-Z][A-Za-z\d!@#$%^&*()_+\-=\[\]{};:"\\|,.<>\/?]*[!@#$%^&*()_+\-=\[\]{};:"\\|,.<>\/?]+.*$/', // Regex kiểm tra chữ cái đầu viết hoa và 1 ký tự đặc biệt
        //     ],
        //     'confirm_password' => 'required|same:password',
        // ],
        // [
        //     "password.regex" => __("messages.pattern"),
        // ]);

        // return $validator->validate();
