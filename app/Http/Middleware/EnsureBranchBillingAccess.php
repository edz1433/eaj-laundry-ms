<?php

namespace App\Http\Middleware;

use App\Models\BranchBillingRecord;
use App\Models\SystemTrialSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        if ($record?->status === 'paid') {
            return $next($request);
        }

        $graceDays = max(0, (int) $trial->grace_period_days);
        $dueDate = $record?->due_date ?: Carbon::create($today->year, $today->month, 1);
        $graceEndsAt = $dueDate->copy()->addDays($graceDays)->startOfDay();

        if ($today->copy()->startOfDay()->lessThanOrEqualTo($graceEndsAt) && ! in_array($record?->status, ['suspended'], true)) {
            View::share('billingBanner', [
                'type' => 'billing',
                'message' => 'Your branch subscription for '.$today->format('F Y').' is unpaid. Please contact your administrator.',
            ]);

            return $next($request);
        }

        if ($record && $record->status === 'unpaid') {
            $record->update(['status' => 'overdue']);
        }

        return response()->view('billing.locked', [
            'message' => 'Branch subscription has expired. Please contact your administrator to continue using the system.',
        ], 402);
    }
}
