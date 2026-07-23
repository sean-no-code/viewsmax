<?php

namespace Database\Seeders;

use App\Models\GoalType;
use Illuminate\Database\Seeder;

class GoalTypeSeeder extends Seeder
{
    /**
     * Seed the built-in conversion goal/event types. Keyed on `value` so the
     * seeder is idempotent (safe to re-run without duplicating rows).
     */
    public function run(): void
    {
        $types = [
            ['value' => 'conversion', 'label' => 'Purchase', 'sort_order' => 1],
            ['value' => 'call booked', 'label' => 'Call booked', 'sort_order' => 2],
            ['value' => 'email-signup', 'label' => 'Email signup', 'sort_order' => 3],
            ['value' => 'newsletter', 'label' => 'Newsletter', 'sort_order' => 4],
            ['value' => 'trial', 'label' => 'Trial', 'sort_order' => 5],
        ];

        foreach ($types as $type) {
            GoalType::firstOrCreate(
                ['value' => $type['value']],
                $type
            );
        }
    }
}
