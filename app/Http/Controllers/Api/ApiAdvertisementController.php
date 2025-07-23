<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Advertisement;

class ApiAdvertisementController extends Controller
{
    public function index(Request $request)
    {
        $position = $request->query('position'); // Ví dụ ?position=top

        $query = Advertisement::query()
            ->where('active', true);

        if ($position) {
            $query->whereRaw('TRIM(position) = ?', [trim($position)]);
        }

        $ads = $query->orderBy('created_at', 'desc')->get(['id', 'position', 'script']);

        return response()->json([
            'data' => $ads,
        ]);
    }
}
