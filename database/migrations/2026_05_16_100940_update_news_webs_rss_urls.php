<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $updates = [
        // Category-specific RSS feeds
        'aerotime.aero'          => ['url' => 'https://www.aerotime.aero/category/airlines/feed',                   'type' => 'rss'],
        'aviationa2z.com'        => ['url' => 'https://aviationa2z.com/index.php/category/news/airline-news/feed/', 'type' => 'rss'],
        'aviationsourcenews.com' => ['url' => 'https://aviationsourcenews.com/category/airline/feed',              'type' => 'rss'],
        'essentiallysports.com'  => ['url' => 'https://www.essentiallysports.com/category/golf/feed/',             'type' => 'rss'],
        'paddleyourownkanoo.com' => ['url' => 'https://www.paddleyourownkanoo.com/category/airline-news/feed/',    'type' => 'rss'],
        'dairylandexpress.com'   => ['url' => 'https://dairylandexpress.com/news-sitemap.xml',                    'type' => 'sitemap'],
        // si.com: dùng team-specific feed thay vì general feed

        // Fix feed_type = none trên các site đã có rss_url đúng
        'acmepackingcompany.com' => ['url' => null,                                'type' => 'rss'],
        'bloggingtheboys.com'    => ['url' => null,                                'type' => 'rss'],
        'lombardiave.com'        => ['url' => null,                                'type' => 'rss'],
        'mensjournal.com'        => ['url' => null,                                'type' => 'rss'],
        'onemileatatime.com'     => ['url' => null,                                'type' => 'rss'],
        'viewfromthewing.com'    => ['url' => null,                                'type' => 'rss'],
    ];

    // Sites có nhiều record cùng domain, phân biệt bằng base_url
    private array $byBaseUrl = [
        ['domain' => 'heavy.com',        'base_url' => 'sports/nfl/dallas-cowboys/',      'url' => 'https://heavy.com/sports/nfl/dallas-cowboys/feed/',      'type' => 'rss', 'new_base' => null],
        ['domain' => 'heavy.com',        'base_url' => 'sports/nfl/green-bay-packers/',   'url' => 'https://heavy.com/sports/nfl/green-bay-packers/feed/',   'type' => 'rss', 'new_base' => null],
        ['domain' => 'clutchpoints.com', 'base_url' => 'nfl/green-bay-packers',           'url' => 'https://clutchpoints.com/nfl/green-bay-packers/feed',    'type' => 'rss', 'new_base' => null],
        ['domain' => 'clutchpoints.com', 'base_url' => 'nfl/dallas-cowboys',              'url' => 'https://clutchpoints.com/nfl/dallas-cowboys/feed',       'type' => 'rss', 'new_base' => null],
        ['domain' => 'atozsports.com',   'base_url' => 'nfl/dallas-cowboys-news/',        'url' => 'https://atozsports.com/nfl/dallas-cowboys-news/feed',    'type' => 'rss', 'new_base' => null],
        ['domain' => 'atozsports.com',   'base_url' => 'nfl/green-bay-packers-news/',     'url' => 'https://atozsports.com/nfl/green-bay-packers-news/feed', 'type' => 'rss', 'new_base' => null],
        // marca.com: dùng RSS NFL chung, filter theo base_url (bỏ .html)
        ['domain' => 'marca.com',        'base_url' => 'en/nfl/green-bay-packers.html',   'url' => 'https://www.marca.com/rss/en/nfl.xml',                   'type' => 'rss', 'new_base' => 'en/nfl/green-bay-packers'],
        ['domain' => 'marca.com',        'base_url' => 'en/nfl/dallas-cowboys.html',      'url' => 'https://www.marca.com/rss/en/nfl.xml',                   'type' => 'rss', 'new_base' => 'en/nfl/dallas-cowboys'],
    ];

    public function up(): void
    {
        foreach ($this->updates as $domain => $info) {
            $data = ['feed_type' => $info['type']];
            if ($info['url'] !== null) {
                $data['rss_url'] = $info['url'];
            }
            DB::table('news_webs')->where('domain', $domain)->update($data);
        }

        foreach ($this->byBaseUrl as $row) {
            $data = ['rss_url' => $row['url'], 'feed_type' => $row['type']];
            if ($row['new_base'] !== null) {
                $data['base_url'] = $row['new_base'];
            }
            DB::table('news_webs')
                ->where('domain', $row['domain'])
                ->where('base_url', $row['base_url'])
                ->update($data);
        }

        // Sites là single-topic → bỏ base_url filter (lấy hết từ feed)
        foreach (['lombardiave.com', 'thelandryhat.com', 'acmepackingcompany.com',
                  'bloggingtheboys.com', 'thegolfinggazette.com', 'onemileatatime.com',
                  'hitc.com', 'si.com'] as $domain) {
            DB::table('news_webs')->where('domain', $domain)->update(['base_url' => null]);
        }

        // dairylandexpress: không có category RSS, news-sitemap + filter slug "packers"
        DB::table('news_webs')->where('domain', 'dairylandexpress.com')->update([
            'base_url' => 'packers',
        ]);
    }

    public function down(): void
    {
        DB::table('news_webs')->update(['feed_type' => 'none']);
    }
};
