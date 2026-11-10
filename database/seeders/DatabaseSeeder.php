<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CustomerSeeder::class,
            MenuCategorySeeder::class,
            MenuItemSeeder::class,
            TableLayoutSeeder::class,
            TypeOfSupplySeeder::class,
            SupplySeeder::class,
            UsageIngredientSeeder::class,
            RolesPermissionsSeeder::class,
            WaiterSeeder::class,
            ChefSeeder::class,
            LargeDatasetSeeder::class,
            ChefSeeder::class,
            AdminSeeder::class,
           
            OfferSeeder::class,
            OrderSeeder::class,
            OrderItemSeeder::class,
            ReportSeeder::class,
            ReservationSeeder::class,
            MenuItemOfferPivotSeeder::class
        ]);

    }
}
