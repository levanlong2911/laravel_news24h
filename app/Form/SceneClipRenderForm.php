<?php

namespace App\Form;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SceneClipRenderForm
{
    /**
     * validate
     */
    public function validate(Request $request)
    {
        $validator = Validator::make($request->all(),
            [
                'model_id' => ['required', 'string', 'max:120'],
                'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:60'],
                'aspect_ratio' => ['nullable', 'string', 'max:16'],
                'resolution' => ['nullable', 'string', 'max:16'],
            ]);

        $data = $validator->validate();

        // Form HTTP luon gui chuoi, con registry khai so nguyen. Registry so sanh
        // NGHIEM NGAT va nen giu nguyen nhu vay — cho nen kieu phai duoc dua ve
        // dung ngay tai bien, khong phai noi long phep so sanh o duoi.
        if (isset($data['duration_seconds'])) {
            $data['duration_seconds'] = (int) $data['duration_seconds'];
        }

        return $data;
    }
}
