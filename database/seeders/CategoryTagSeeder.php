<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Tag;
use Illuminate\Support\Str;

class CategoryTagSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Tạo 5 danh mục
        for ($i = 1; $i <= 5; $i++) {
            $category = Category::create([
                'id' => (string) Str::uuid(),
                'name' => "Category $i",
            ]);

            // Tạo 3 thẻ tag cho mỗi danh mục
            for ($j = 1; $j <= 3; $j++) {
                Tag::create([
                    'id' => (string) Str::uuid(),
                    'name' => "Tag $j for Category $i",
                    'category_id' => $category->id,
                ]);
            }
        }
    }
}
