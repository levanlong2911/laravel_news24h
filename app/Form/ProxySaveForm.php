<?php

namespace App\Form;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProxySaveForm
{
    /**
     * validate
     */
    public function validate(Request $request)
    {
        $validator = Validator::make($request->all(),
            [
                'input_sha256' => ['required', 'string', 'size:64'],
                'report' => ['required', 'string', 'json'],
                'cameras' => ['required', 'string', 'json'],
                'images' => ['required', 'array'],
                'images.*' => ['file'],
                'glb' => ['required', 'file'],
            ],
            [
                'input_sha256.*' => 'Thiếu mã dữ liệu của trang — tải lại trang rồi lưu lại.',
                'report.*' => 'Thiếu báo cáo kiểm.',
                'cameras.*' => 'Thiếu thông số camera.',
                'images.*' => 'Thiếu ảnh kiểm.',
                'glb.*' => 'Thiếu file proxy.glb.',
            ]);

        return $validator->validate();
    }
}
