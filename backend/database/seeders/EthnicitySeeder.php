<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Ethnicity;

class EthnicitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $ethnicities = [
            'White',
            'Black',
            'Asian American',
            'East Asian (Chinese, Japanese, Korean)',
            'Eurasian (half white, half Asian)',
            'South East Asian (Thai, Indonesian)',
            'South Asian (Indian)',
            'Middle Eastern (Arabic)',
            'Pacific (Polynesian)',
            'Hispanic',
            'Afro-European (half white, half black)',
            'Afro-Asian (half black, half Asian)',
        ];

        foreach ($ethnicities as $ethnicity) {
            Ethnicity::firstOrCreate(['name' => $ethnicity]);
        }
    }
}
