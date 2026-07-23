<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\AiModelType;

class AiModelTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            'male',
            'female',
        ];

        foreach ($types as $type) {
            AiModelType::firstOrCreate(['name' => $type]);
        }
    }
}
