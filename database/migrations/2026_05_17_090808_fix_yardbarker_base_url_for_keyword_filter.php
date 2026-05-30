<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // yardbarker.com dùng sitemap_news.xml chung cho toàn site.
    // Article URLs đều có dạng /nfl/articles/... — không chứa tên team.
    // Phải filter bằng <news:keywords> thay vì URL path.
    // base_url = "green bay packers" / "dallas cowboys" → Python sẽ match keywords field.
    private array $keywordMap = [
        'green_bay_packers' => 'green bay packers',
        'dallas_cowboys'    => 'dallas cowboys',
    ];

    public function up(): void
    {
        foreach ($this->keywordMap as $slug => $keyword) {
            DB::table('news_webs')
                ->where('domain', 'yardbarker.com')
                ->where('base_url', 'LIKE', "%{$slug}%")
                ->update([
                    'rss_url'   => 'https://yardbarker.com/sitemap_news.xml',
                    'feed_type' => 'sitemap',
                    'base_url'  => $keyword,
                ]);
        }
    }

    public function down(): void
    {
        DB::table('news_webs')
            ->where('domain', 'yardbarker.com')
            ->whereIn('base_url', array_values($this->keywordMap))
            ->update(['base_url' => null]);
    }
};
