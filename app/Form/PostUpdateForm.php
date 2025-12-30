<?php

namespace App\Form;

use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PostUpdateForm
{
    /**
     * validate
     *
     * @param \Illuminate\Http\Request $request
     */
    public function validate(Request $request, string $postId, $domainId)
    {
        $validator = Validator::make($request->all(),
        [
            "title" => [
                "bail",
                "required",
                Rule::unique('posts', 'title')
                    ->where(fn ($q) => $q->where('domain_id', $domainId))
                    ->ignore($postId),
            ],
            'slug' => [
                'bail',
                'required',
                Rule::unique('posts', 'slug')
                    ->where(fn ($q) => $q->where('domain_id', $domainId))
                    ->ignore($postId),
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
        ]);

        return $validator->validate();
    }
}
