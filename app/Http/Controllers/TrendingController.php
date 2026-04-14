<?php

namespace App\Http\Controllers;

use App\Services\Admin\SerpApiService;
use App\Services\Admin\TrendingNewsService;
use Illuminate\Http\Request;

class TrendingController extends Controller
{
    private TrendingNewsService $trendingNewsService;
    private SerpApiService $serpApiService;

    public function __construct
    (
        TrendingNewsService $trendingNewsService,
        SerpApiService $serpApiService
    )
    {
        $this->trendingNewsService = $trendingNewsService;
        $this->serpApiService = $serpApiService;
    }

    public function index(Request $request)
    {
        $geo      = $request->get('geo', 'US');
        $articles = [];
        $topics   = [];
        $matched  = [];
        $error    = null;

        try {
            // Lấy trending topics
            $topics  = $this->serpApiService->getTrendingTopics($geo);
            dd($topics);
            $matched = $this->serpApiService->matchTrendsToTeams($topics);



            // Lấy news cho từng topic trending
            foreach (array_slice($topics, 0, 5) as $trend) {
                // dd($trend);
                $news = $this->serpApiService->searchNews($trend['keyword'], 5);
                $filtered = $this->serpApiService->filterAndScore($news, $trend);

                foreach ($filtered as $article) {
                    $article['trend_keyword'] = $trend['keyword'];
                    $article['trend_traffic'] = $trend['traffic'];
                    $article['trend_increase']= $trend['increase'];
                    $articles[] = $article;
                }

                usleep(300_000);
            }

            // Sort theo quality score
            usort($articles, fn($a, $b) => $b['quality_score'] <=> $a['quality_score']);
            $articles = array_slice($articles, 0, 30);
            dd($articles);

        } catch (\Exception $e) {
            $error = $e->getMessage();
        }

        return view("trending.index", [
            "route" => "trending",
            "action" => "admin-trending",
            "menu" => "menu-open",
            "topics" => $topics,
            "articles" => $articles,
            "geo" => $geo,
            "error" => $error,
        ]);
    }
}
