<?php

namespace App\Http\Middleware;

use App\Models\BranchBillingRecord;
use App\Models\SystemTrialSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class EnsureBranchBillingAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        View::share('billingBanner', null);

        if (! $user || $user->isSuperAdmin()) {
            return $next($request);
        }

        if ($request->routeIs('logout')) {
            return $next($request);
        }

        if (! Schema::hasTable('system_trial_settings') || ! Schema::hasTable('branch_billing_records')) {
            return $next($request);
        }

        $trial = SystemTrialSetting::current();

        if ($trial->isActive()) {
            View::share('billingBanner', [
                'type' => 'trial',
                'message' => 'System Free Trial Active Until '.$trial->trial_end_date->format('M d, Y'),
            ]);

            return $next($request);
        }

        if (! $trial->shouldEnforceBilling()) {
            return $next($request);
        }

        if (! $user->branch_id) {
            return response()->view('billing.locked', [
                'message' => 'Branch subscription has expired. Please contact your administrator to continue using the system.',
            ], 402);
        }

        $today = now();
        $record = BranchBillingRecord::query()
            ->where('branch_id', $user->branch_id)
            ->where('billing_month', (int) $today->month)
            ->where('billing_year', (int) $today->year)
            ->first();

        if (! $record) {
            return response()->view('billing.locked', [
                'message' => 'No active branch subscription was found for '.$today->format('F Y').'. Please contact your administrator to continue using the system.',
            ], 402);
        }

        if ($record->status === 'paid') {
            return $next($request);
        }

        if ($record->status === 'suspended') {
            return response()->view('billing.locked', [
                'message' => 'Branch subscription has been suspended. Please contact your administrator to continue using the system.',
            ], 402);
        }

        if ($record->status === 'unpaid' && $record->due_date->toDateString() < $today->toDateString()) {
            $record->update(['status' => 'overdue']);
            $record->status = 'overdue';
        }

        View::share('billingBanner', [
            'type' => 'billing',
            'dismissible' => true,
            'key' => 'billing-'.$record->branch_id.'-'.$record->billing_year.'-'.$record->billing_month.'-'.$record->status,
            'message' => 'Your branch subscription for '.$record->periodLabel().' is '.str_replace('_', ' ', $record->status).'. Please contact your administrator.',
        ]);

        return $next($request);
    }
}
