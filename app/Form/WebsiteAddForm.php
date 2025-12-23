<?php

namespace App\Form;

use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class WebsiteAddForm
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
            'name' => 'required|string|unique:domains,name',
            'hots' => 'required|unique:domains,host',
        ]);
        return $validator->validate();
    }
}
