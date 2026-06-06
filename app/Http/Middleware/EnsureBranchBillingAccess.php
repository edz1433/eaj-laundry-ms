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
            if ($user->canManageAllBranches()) {
                return $next($request);
            }

            View::share('billingBanner', [
                'type' => 'danger',
                'dismissible' => false,
                'key' => 'billing-missing-branch-'.$user->id,
                'message' => 'Branch subscription has expired. Please contact your administrator to continue using the system.',
            ]);

            return $next($request);
        }

        $today = now();
        $matchingRecords = BranchBillingRecord::query()
            ->where('branch_id', $user->branch_id)
            ->where(function ($query) use ($today) {
                $query
                    ->where(function ($query) use ($today) {
                        $query
                            ->whereDate('subscription_start_date', '<=', $today->toDateString())
                            ->whereDate('subscription_end_date', '>=', $today->toDateString());
                    })
                    ->orWhere(function ($query) use ($today) {
                        $query
                            ->whereNull('subscription_start_date')
                            ->whereNull('subscription_end_date')
                            ->where('billing_month', (int) $today->month)
                            ->where('billing_year', (int) $today->year);
                    });
            })
            ->orderByRaw("CASE status WHEN 'paid' THEN 0 WHEN 'unpaid' THEN 1 WHEN 'overdue' THEN 2 WHEN 'suspended' THEN 3 ELSE 4 END")
            ->latest('subscription_end_date')
            ->latest('due_date');

        if ((clone $matchingRecords)->where('status', 'paid')->exists()) {
            return $next($request);
        }

        $record = $matchingRecords->first();

        if (! $record) {
            View::share('billingBanner', [
                'type' => 'danger',
                'dismissible' => false,
                'key' => 'billing-missing-'.$user->branch_id.'-'.$today->year.'-'.$today->month,
                'message' => 'No active branch subscription was found for '.$today->format('F Y').'. Please contact your administrator to continue using the system.',
            ]);

            return $next($request);
        }

        if ($record->status === 'paid') {
            return $next($request);
        }

        if ($record->status === 'suspended') {
            View::share('billingBanner', [
                'type' => 'danger',
                'dismissible' => false,
                'key' => 'billing-'.$record->id.'-'.$record->status,
                'message' => 'Branch subscription has been suspended. Please contact your administrator to continue using the system.',
            ]);

            return $next($request);
        }

        if ($record->status === 'unpaid' && $record->due_date->toDateString() < $today->toDateString()) {
            $record->update(['status' => 'overdue']);
            $record->status = 'overdue';
        }

        View::share('billingBanner', [
            'type' => 'danger',
            'dismissible' => true,
            'key' => 'billing-'.$record->id.'-'.$record->status,
            'message' => 'Your branch subscription for '.$record->periodLabel().' is '.str_replace('_', ' ', $record->status).'. Please contact your administrator.',
        ]);

        return $next($request);
    }
}
