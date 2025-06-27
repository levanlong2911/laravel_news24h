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

        return $validator->validate();
    }
}
