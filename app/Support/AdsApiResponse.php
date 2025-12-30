<?php
namespace App\Support;

use Illuminate\Http\JsonResponse;

class AdsApiResponse
{
    public static function success($data = null)
    {
        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }
}
