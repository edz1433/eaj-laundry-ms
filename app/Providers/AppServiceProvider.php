<?php

namespace App\Providers;

use App\Models\SystemSetting;
use App\Support\PublicUpload;
use Illuminate\Support\Facades\Schema;
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
        View::composer('*', function ($view) {
            static $shared = null;

            if ($shared === null) {
                $settings = null;

                try {
                    if (Schema::hasTable('system_settings')) {
                        $settings = SystemSetting::current();
                    }
                } catch (\Throwable) {
                    $settings = null;
                }

                $businessName = $settings?->business_name ?: config('app.name', 'Laundry System');
                $businessLogo = PublicUpload::url($settings?->business_logo) ?: asset('logo.png');

                $shared = [
                    'appSettings' => $settings,
                    'appSystemName' => config('app.name', 'SKL Management System'),
                    'appBusinessName' => $businessName,
                    'appBusinessLogo' => $businessLogo,
                    'appPrimaryColor' => $settings?->primary_color ?: '#2E7D32',
                    'appDarkModeDefault' => (bool) ($settings?->dark_mode_default ?? false),
                ];
            }

            $view->with($shared);
        });
    }
}
