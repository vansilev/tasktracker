<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Services\BillingBot;
use Illuminate\Database\Seeder;

class BillingSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CategorySeeder::class);

        Category::query()->firstOrCreate(
            ['name' => 'Оплаты'],
            ['is_active' => true, 'sort_order' => 90],
        );

        app(BillingBot::class)->user();
    }
}
