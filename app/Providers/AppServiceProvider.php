<?php

namespace App\Providers;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $settings = null;

        try {
            if (Schema::hasTable('system_settings')) {
                $settings = SystemSetting::current();
            }
        } catch (\Throwable) {
            $settings = null;
        }

        $businessName = $settings?->business_name ?: config('app.name', 'Laundry System');
        $businessLogo = $settings?->business_logo && Storage::disk('public')->exists($settings->business_logo)
            ? Storage::disk('public')->url($settings->business_logo)
            : asset('logo.png');

        View::share([
            'appSettings' => $settings,
            'appBusinessName' => $businessName,
            'appBusinessLogo' => $businessLogo,
            'appPrimaryColor' => $settings?->primary_color ?: '#2E7D32',
            'appDarkModeDefault' => (bool) ($settings?->dark_mode_default ?? false),
        ]);
    }
}
