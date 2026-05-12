<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Support\DefaultInventoryItems;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    public function run(): void
    {
        Branch::query()
            ->where('is_active', true)
            ->each(fn (Branch $branch) => DefaultInventoryItems::seedForBranch($branch));
    }
}
