<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Tag;
use Illuminate\Support\Str;

class CategoryTagSeeder extends Seeder
{
    public function run(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $name = "Category $i";
            $slug = Str::slug($name);

            // ✅ Không tạo trùng category
            $category = Category::updateOrCreate(
                ['slug' => $slug], // điều kiện check tồn tại
                [
                    'name' => $name,
                ]
            );

            // 🔁 tạo tag
            for ($j = 1; $j <= 3; $j++) {
                $tagName = "Tag $j for Category $i";

                Tag::updateOrCreate(
                    [
                        'name' => $tagName,
                        'category_id' => $category->id,
                    ],
                    [] // không cần update gì thêm
                );
            }

            // 👉 log ra cho dễ debug
            $this->command->info("Seeded: $name");
        }
    }
}
