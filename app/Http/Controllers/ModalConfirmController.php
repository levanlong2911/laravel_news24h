<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ModalConfirmController extends Controller
{
    /**
     * Get modal confirm
     */
    public function modalConfirm(Request $request)
    {
        // $userId = $request->userId ?? "";
        $url = $request->url;
        $ids = $request->ids;
        $content = $request->content;
        $memo = $request->memo;
        return view(
            "modal.confirm",
            compact("url", "ids", "content", "memo")
        );
    }

}
