<?php

namespace Database\Seeders;

use App\Models\FileCategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FileCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'AI Model Thumbnail',
                'slug' => 'ai-model-thumbnail',
                'description' => 'Thumbnail images for AI models',
            ],
            [
                'name' => 'AI Upload Image',
                'slug' => 'ai-upload-image',
                'description' => 'Training images uploaded for AI model creation',
            ],
        ];

        foreach ($categories as $category) {
            FileCategory::firstOrCreate(
                ['name' => $category['name']],
                $category
            );
        }
    }
}
