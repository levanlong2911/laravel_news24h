<?php

namespace App\Form;

use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class DomainAddForm
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
            'domain' => 'bail|required',
            'key_class' => 'bail|required|max:50',
        ]);
        // dd($validator->validate());
        return $validator->validate();
    }
}
