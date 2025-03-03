<?php

namespace App\Form;

use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class GetLinkForm
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
            'url' => 'bail|required|url',
        ]);
        return $validator->validate();
    }
}
