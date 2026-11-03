<?php

namespace App\Form;

use App\Services\Admin\PromptBuilderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PromptFrameworkForm
{
    /**
     * Validate dữ liệu form prompt framework.
     *
     * Soi placeholder ngay lúc lưu — sai thì admin thấy lỗi trên form, không phải
     * đợi tới lúc pipeline chạy. Dùng chung contract với PromptGuard::validatePlaceholders()
     * qua PromptBuilderService::inspectPlaceholders() nên hai đường không thể lệch nhau.
     *
     * Không validate 'version' và 'is_active': version do PromptFrameworkObserver
     * quản lý, is_active do luồng xoá quản lý. Nhận từ form là mở đường cho mass
     * assignment ghi đè chúng.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validate(Request $request): array
    {
        $rules = [
            'name' => [
                'bail',
                'required',
                'string',
                'max:60',
                Rule::unique('prompt_frameworks', 'name')->ignore($request->input('id')),
            ],
            'group_description' => ['bail', 'required', 'string', 'max:255'],
            'system_prompt'     => ['bail', 'required', 'string'],
            'phase1_analyze'    => ['bail', 'required', 'string'],
            'phase2_diagnose'   => ['bail', 'required', 'string'],
            'phase3_generate'   => ['bail', 'required', 'string'],
        ];

        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($validator) use ($request) {
            $problems = PromptBuilderService::inspectPlaceholders(
                $request->only(array_keys(PromptBuilderService::PLACEHOLDERS))
            );

            foreach ($problems as $field => $problem) {
                if ($problem['missing']) {
                    $validator->errors()->add($field, sprintf(
                        '%s thiếu placeholder bắt buộc: %s — giá trị đã tính ra sẽ không tới Claude.',
                        $field,
                        implode(', ', $problem['missing']),
                    ));
                }

                if ($problem['unknown']) {
                    $allowed = array_map(
                        fn ($key) => "{{$key}}",
                        PromptBuilderService::PLACEHOLDERS[$field],
                    );

                    $validator->errors()->add($field, sprintf(
                        '%s chứa placeholder không giải được: %s. Hợp lệ cho field này: %s',
                        $field,
                        implode(', ', $problem['unknown']),
                        $allowed ? implode(', ', $allowed) : '(không có placeholder nào)',
                    ));
                }
            }
        });

        // validate() ném ValidationException khi hỏng — Laravel tự redirect về form
        // kèm errors và old input. Trước đây chỗ này trả về mảng lỗi, mà controller
        // lại đem chính mảng đó đi create(), nên lỗi validate mất sạch.
        return $validator->validate();
    }
}
