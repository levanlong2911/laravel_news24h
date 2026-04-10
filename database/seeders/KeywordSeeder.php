<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Keyword;
use Illuminate\Support\Str;

class KeywordSeeder extends Seeder
{
    public function run(): void
    {
        // 🔥 đảm bảo có category NFL
        $nfl = Category::firstOrCreate(
            ['slug' => 'nfl'],
            [
                'id'   => Str::uuid(),
                'name' => 'Category 1',
                'slug' => Str::slug('Category 1'),
            ]
        );

        $teams = [
            ['name' => 'Dallas Cowboys',      'short_name' => 'cowboys',  'sort_order' => 1],
            ['name' => 'Kansas City Chiefs',  'short_name' => 'chiefs',   'sort_order' => 2],
            ['name' => 'Philadelphia Eagles', 'short_name' => 'eagles',   'sort_order' => 3],
            ['name' => 'San Francisco 49ers', 'short_name' => '49ers',    'sort_order' => 4],
            ['name' => 'Buffalo Bills',       'short_name' => 'bills',    'sort_order' => 5],
            ['name' => 'Baltimore Ravens',    'short_name' => 'ravens',   'sort_order' => 6],
            ['name' => 'Green Bay Packers',   'short_name' => 'packers',  'sort_order' => 7],
            ['name' => 'Miami Dolphins',      'short_name' => 'dolphins', 'sort_order' => 8],
            ['name' => 'Los Angeles Rams',    'short_name' => 'rams',     'sort_order' => 9],
            ['name' => 'Cincinnati Bengals',  'short_name' => 'bengals',  'sort_order' => 10],
            ['name' => 'New England Patriots','short_name' => 'patriots', 'sort_order' => 11],
            ['name' => 'Seattle Seahawks',    'short_name' => 'seahawks', 'sort_order' => 12],
        ];

        foreach ($teams as $team) {
            Keyword::updateOrCreate(
                ['name' => $team['name']], // tránh duplicate
                [
                    'id'          => Str::uuid(),
                    'short_name'  => $team['short_name'],
                    'search_keyword'  => $team['name'],
                    'sort_order'  => $team['sort_order'],
                    'category_id' => $nfl->id,
                    'is_base'     => true,
                    'is_active'   => true,
                ]
            );
        }
    }
}
