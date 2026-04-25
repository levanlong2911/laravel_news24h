<?php

namespace App\Form;

use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WebUpdateValidate
{
    public function validate(Request $request, ?string $ignoreId = null)
    {
        $validator = Validator::make($request->all(),
        [
            'category_id' => 'required|exists:categories,id',
            'domain'      => [
                'required', 'string',
                Rule::unique('news_webs', 'domain')
                    ->where('category_id', $request->category_id)
                    ->ignore($ignoreId),
            ],
            'url'         => 'required|string',
        ]);

        return $validator->validate();
    }
}
