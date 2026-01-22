<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Advertisement;
use Illuminate\Support\Facades\Cache;
use App\Support\CacheVersion;
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
                'success' => true,
                'data'    => [],
            ]);
        }

        $cacheKey = sprintf(
            'ads:%s:domain:%s',
            CacheVersion::ADS,
            $domain->id
        );

        try {
            $ads = Cache::remember($cacheKey, 600, function () use ($domain) {
                return $this->queryAds($domain);
            });

            return response()->json([
                'success' => true,
                'data'    => $ads ?: [],
            ]);
        } catch (\Throwable $e) {
            Log::error('ADS API INDEX ERROR', [
                'domain_id' => $domain->id,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'success' => true,
                'data'    => [],
            ]);
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
                'success' => true,
                'data'    => [],
            ]);
        }

        $allowed = ['top', 'middle', 'bottom', 'header', 'in-post'];
        if (!in_array($position, $allowed, true)) {
            return response()->json([
                'success' => true,
                'data'    => [],
            ]);
        }

        $cacheKey = sprintf(
            'ads:%s:%s:%s',
            CacheVersion::ADS,
            $domain->id,
            $position
        );

        try {
            $ads = Cache::remember($cacheKey, 600, function () use ($domain, $position) {
                return $this->queryAds($domain, $position);
            });

            return response()->json([
                'success' => true,
                'data'    => $ads ?: [],
            ]);
        } catch (\Throwable $e) {
            Log::error('ADS POSITION FAIL', [
                'domain_id' => $domain->id,
                'position'  => $position,
                'error'     => $e->getMessage(),
            ]);

            return response()->json([
                'success' => true,
                'data'    => [],
            ]);
        }
    }

    /**
     * Query ads helper
     */
    protected function queryAds($domain, ?string $position = null)
    {
        $query = Advertisement::query()
            ->is_active()
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
