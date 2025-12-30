<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Advertisement;
use App\Support\AdsApiResponse;
use Illuminate\Support\Facades\Cache;

class ApiAdvertisementController extends Controller
{
    /**
     * GET ALL ADS BY DOMAIN (GROUPED BY POSITION)
     * /api/ads
     */
    public function index(Request $request)
    {
        $domain = currentDomain();
        abort_if(!$domain, 404);

        $cacheKey = "ads:domain:{$domain->id}";

        $ads = Cache::remember(
            $cacheKey,
            now()->addMinutes(10),
            function () use ($domain) {

                return Advertisement::query()
                    ->active()
                    ->forDomain($domain->id)
                    ->orderByRaw("
                        CASE
                            WHEN domain_id IS NOT NULL THEN 0
                            ELSE 1
                        END
                    ") // ưu tiên ads theo domain
                    ->get()
                    ->groupBy('position')
                    ->map(function ($items) {
                        return $items->map(fn ($ad) => [
                            'id'     => $ad->id,
                            'script' => $ad->script,
                        ]);
                    });
            }
        );

        return AdsApiResponse::success($ads);
    }

    /**
     * GET ADS BY POSITION
     * /api/ads/{position}
     */
    public function byPosition(Request $request, string $position)
    {
        $domain = currentDomain();
        abort_if(!$domain, 404);

        $allowed = ['top', 'middle', 'bottom', 'header', 'in-post'];
        abort_unless(in_array($position, $allowed), 404);

        $cacheKey = "ads:{$domain->id}:{$position}";

        $ads = Cache::remember(
            $cacheKey,
            now()->addMinutes(10),
            function () use ($domain, $position) {

                return Advertisement::query()
                    ->active()
                    ->forDomain($domain->id)
                    ->where('position', $position)
                    ->orderByRaw("
                        CASE
                            WHEN domain_id IS NOT NULL THEN 0
                            ELSE 1
                        END
                    ")
                    ->get()
                    ->map(fn ($ad) => [
                        'id'     => $ad->id,
                        'script' => $ad->script,
                    ]);
            }
        );

        return AdsApiResponse::success($ads);
    }
}
