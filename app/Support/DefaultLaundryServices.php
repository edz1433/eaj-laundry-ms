<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\LaundryService;

class DefaultLaundryServices
{
    public static function all(): array
    {
        return [
            ['name' => 'Wash Dry Fold', 'pricing_type' => 'kilo', 'price' => 180],
            ['name' => 'Wash Only', 'pricing_type' => 'kilo', 'price' => 90],
            ['name' => 'Dry Only', 'pricing_type' => 'kilo', 'price' => 90],
            ['name' => 'Fold Only', 'pricing_type' => 'kilo', 'price' => 45],
            ['name' => 'Ironing', 'pricing_type' => 'piece', 'price' => 20],
            ['name' => 'Pressing', 'pricing_type' => 'piece', 'price' => 25],
            ['name' => 'Full Service Load', 'pricing_type' => 'load', 'price' => 220],
            ['name' => 'Self Service Wash Load', 'pricing_type' => 'load', 'price' => 80],
            ['name' => 'Self Service Dry Load', 'pricing_type' => 'load', 'price' => 80],
            ['name' => 'Heavy Load Wash', 'pricing_type' => 'load', 'price' => 120],
            ['name' => 'Heavy Load Dry', 'pricing_type' => 'load', 'price' => 120],
            ['name' => 'Extra Dry 10 Minutes', 'pricing_type' => 'custom', 'price' => 20],
            ['name' => 'Extra Dry 20 Minutes', 'pricing_type' => 'custom', 'price' => 35],
            ['name' => 'Extra Dry 30 Minutes', 'pricing_type' => 'custom', 'price' => 50],
            ['name' => 'Rush Service', 'pricing_type' => 'custom', 'price' => 100],
            ['name' => 'Same Day Service', 'pricing_type' => 'custom', 'price' => 150],
            ['name' => 'Pickup Service', 'pricing_type' => 'custom', 'price' => 80],
            ['name' => 'Delivery Service', 'pricing_type' => 'custom', 'price' => 80],
            ['name' => 'Pickup and Delivery', 'pricing_type' => 'custom', 'price' => 150],
            ['name' => 'Detergent', 'pricing_type' => 'custom', 'price' => 15],
            ['name' => 'Fabric Conditioner', 'pricing_type' => 'custom', 'price' => 15],
            ['name' => 'Bleach', 'pricing_type' => 'custom', 'price' => 20],
            ['name' => 'Stain Treatment', 'pricing_type' => 'piece', 'price' => 30],
            ['name' => 'Deep Cleaning', 'pricing_type' => 'kilo', 'price' => 220],
            ['name' => 'Sanitize Wash', 'pricing_type' => 'kilo', 'price' => 220],
            ['name' => 'Delicate Wash', 'pricing_type' => 'kilo', 'price' => 220],
            ['name' => 'Hand Wash', 'pricing_type' => 'piece', 'price' => 80],
            ['name' => 'Dry Cleaning', 'pricing_type' => 'piece', 'price' => 180],
            ['name' => 'Bedsheet Single', 'pricing_type' => 'piece', 'price' => 80],
            ['name' => 'Bedsheet Double', 'pricing_type' => 'piece', 'price' => 100],
            ['name' => 'Comforter Single', 'pricing_type' => 'piece', 'price' => 180],
            ['name' => 'Comforter Double', 'pricing_type' => 'piece', 'price' => 250],
            ['name' => 'Comforter Queen', 'pricing_type' => 'piece', 'price' => 300],
            ['name' => 'Comforter King', 'pricing_type' => 'piece', 'price' => 350],
            ['name' => 'Blanket Small', 'pricing_type' => 'piece', 'price' => 120],
            ['name' => 'Blanket Large', 'pricing_type' => 'piece', 'price' => 180],
            ['name' => 'Duvet', 'pricing_type' => 'piece', 'price' => 300],
            ['name' => 'Pillow Case', 'pricing_type' => 'piece', 'price' => 25],
            ['name' => 'Pillow', 'pricing_type' => 'piece', 'price' => 120],
            ['name' => 'Towel Small', 'pricing_type' => 'piece', 'price' => 30],
            ['name' => 'Towel Bath', 'pricing_type' => 'piece', 'price' => 50],
            ['name' => 'Curtain Small', 'pricing_type' => 'piece', 'price' => 120],
            ['name' => 'Curtain Large', 'pricing_type' => 'piece', 'price' => 220],
            ['name' => 'Rug Small', 'pricing_type' => 'piece', 'price' => 150],
            ['name' => 'Rug Large', 'pricing_type' => 'piece', 'price' => 300],
            ['name' => 'Jacket', 'pricing_type' => 'piece', 'price' => 120],
            ['name' => 'Coat', 'pricing_type' => 'piece', 'price' => 180],
            ['name' => 'Gown', 'pricing_type' => 'piece', 'price' => 350],
            ['name' => 'Suit Set', 'pricing_type' => 'piece', 'price' => 350],
            ['name' => 'Uniform Set', 'pricing_type' => 'piece', 'price' => 80],
            ['name' => 'Shoes Cleaning', 'pricing_type' => 'piece', 'price' => 250],
            ['name' => 'Bag Cleaning', 'pricing_type' => 'piece', 'price' => 250],
        ];
    }

    public static function seedForBranch(Branch $branch): void
    {
        foreach (self::all() as $service) {
            LaundryService::withTrashed()->updateOrCreate(
                [
                    'branch_id' => $branch->id,
                    'name' => $service['name'],
                ],
                [
                    'pricing_type' => $service['pricing_type'],
                    'price' => $service['price'],
                    'is_active' => true,
                    'deleted_at' => null,
                ]
            );
        }
    }
}
