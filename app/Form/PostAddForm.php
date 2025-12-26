<?php

namespace App\Form;

use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class PostAddForm
{
    /**
     * validate
     *
     * @param \Illuminate\Http\Request $request
     */
    public function validate(Request $request, $domainId)
    {
        $rules = [
            "title" => [
                "bail",
                "required",
                Rule::unique('posts')
                    ->where(fn ($q) => $q->where('domain_id', $domainId)),
            ],
            "slug" => [
                "bail",
                "required",
                Rule::unique('posts')
                    ->where(fn ($q) => $q->where('domain_id', $domainId)),
            ],
            "editor_content" => [
                "bail",
                "required"
            ],
            "category" => [
                "bail",
                "required"
            ],
            "tagIds" => [
                "bail",
                "required"
            ],
            'image' => [
                'required',
                'url',
            ],
        ];

        /** 🔐 Validate riêng cho ADMIN */
        $admin = Auth::user();

        if ($admin && $admin->isAdmin()) {
            $rules['domain_id'] = [
                'bail',
                'required',
                'exists:domains,id',
            ];
        }

        return Validator::make($request->all(), $rules)->validate();
    }
}
