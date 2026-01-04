<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Advertisement;
use Illuminate\Support\Facades\Cache;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Log;

class ApiAdvertisementController extends Controller
{
    /**
     * GET ALL ADS BY DOMAIN (GROUPED BY POSITION)
     * /api/ads
     */
    public function index(Request $request)
    {
        $domain = $request->get('domain');
        if (!$domain) {
            return response()->json([
                'success' => false,
                'message' => 'Domain not found',
            ], 404);
        }

        $cacheKey = "ads:domain:{$domain->id}";
        $useTag   = Cache::getStore() instanceof TaggableStore;

        try {
            $ads = $useTag
                ? Cache::tags(["domain:{$domain->id}", "ads"])
                    ->remember($cacheKey, 600, fn () => $this->queryAds($domain))
                : Cache::remember($cacheKey, 600, fn () => $this->queryAds($domain));

            return response()->json([
                'success' => true,
                'data'    => $ads,
            ]);
        } catch (\Throwable $e) {
            Log::error('ADS API INDEX ERROR', [
                'domain_id' => $domain->id,
                'message'   => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Service unavailable',
            ], 503);
        }
    }

    /**
     * GET ADS BY POSITION
     * /api/ads/{position}
     */
    public function byPosition(Request $request, string $position)
    {
        $domain = $request->get('domain');
        if (!$domain) {
            return response()->json([
                'success' => false,
                'message' => 'Domain not found',
            ], 404);
        }

        $allowed = ['top', 'middle', 'bottom', 'header', 'in-post'];
        if (!in_array($position, $allowed)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid position',
            ], 404);
        }

        $cacheKey = "ads:{$domain->id}:{$position}";
        $useTag   = Cache::getStore() instanceof TaggableStore;

        try {
            $ads = $useTag
                ? Cache::tags(["domain:{$domain->id}", "ads:{$position}"])
                    ->remember($cacheKey, 600, fn () => $this->queryAds($domain, $position))
                : Cache::remember($cacheKey, 600, fn () => $this->queryAds($domain, $position));

            return response()->json([
                'success' => true,
                'data'    => $ads,
            ]);
        } catch (\Throwable $e) {
            Log::error('ADS API POSITION ERROR', [
                'domain_id' => $domain->id,
                'position'  => $position,
                'message'   => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Service unavailable',
            ], 503);
        }
    }

    /**
     * Query ads helper
     */
    protected function queryAds($domain, ?string $position = null)
    {
        $query = Advertisement::query()
            ->active()
            ->forDomain($domain->id)
            ->orderByRaw("
                CASE WHEN domain_id IS NOT NULL THEN 0 ELSE 1 END
            ");

        if ($position) {
            $query->where('position', $position);
        }

        $ads = $query->get();

        if ($position) {
            return $ads->map(fn($ad) => [
                'id'     => $ad->id,
                'script' => $ad->script,
            ]);
        }

        // group by position
        return $ads->groupBy('position')
                   ->map(fn($items) => $items->map(fn($ad) => [
                       'id'     => $ad->id,
                       'script' => $ad->script,
                   ]));
    }
}
