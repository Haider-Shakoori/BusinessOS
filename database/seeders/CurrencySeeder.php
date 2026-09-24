<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

/**
 * Global currency reference data (Batch 19).
 *
 * The shared, business-agnostic registry that businesses can enable. Idempotent
 * (updateOrCreate by code) and safe to run on an existing database — it never
 * touches per-business data. The conventional ISO 4217 numeric code is stored
 * as the 3-letter code; decimals is the standard minor-unit digit count.
 */
class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            ['code' => 'AFN', 'name' => 'Afghan Afghani', 'symbol' => '؋', 'decimals' => 2],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'decimals' => 2],
            ['code' => 'PKR', 'name' => 'Pakistani Rupee', 'symbol' => '₨', 'decimals' => 2],
            ['code' => 'IRR', 'name' => 'Iranian Rial', 'symbol' => '﷼', 'decimals' => 2],
            ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'decimals' => 2],
            ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'د.إ', 'decimals' => 2],
            ['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => '﷼', 'decimals' => 2],
            ['code' => 'CNY', 'name' => 'Chinese Yuan', 'symbol' => '¥', 'decimals' => 2],
            ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥', 'decimals' => 0],
        ];

        foreach ($currencies as $row) {
            Currency::updateOrCreate(['code' => $row['code']], $row);
        }
    }
}
