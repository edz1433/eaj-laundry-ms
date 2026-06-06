<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchSetting;
use App\Models\SystemSetting;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SystemSettingController extends Controller
{
    public function edit(Request $request)
    {
        $user = $request->user();
        $settings = SystemSetting::current();
        $canManageGlobal = $user->isAdmin();
        $canManageSms = $user->isSuperAdmin();
        $canChooseBranch = $user->isAdmin();

        $branches = Branch::query()
            ->where('is_active', true)
            ->when(! $canChooseBranch, fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        $branch = $canChooseBranch
            ? Branch::query()->find($request->integer('branch_id')) ?? $branches->first()
            : $user->branch;

        if (! $branch) {
            $branch = Branch::firstOrCreate(
                ['code' => 'MAIN'],
                ['name' => 'Main Branch', 'is_active' => true]
            );
        }

        $branchSetting = BranchSetting::firstOrCreate(
            ['branch_id' => $branch->id],
            [
                'receipt_header' => $settings->receipt_header,
                'receipt_footer' => $settings->receipt_footer,
                'operating_hours' => $settings->operating_hours,
                'default_price_per_kilo' => $settings->default_price_per_kilo,
                'default_price_per_load' => $settings->default_price_per_load,
                'default_price_per_piece' => $settings->default_price_per_piece,
                'job_order_prefix' => $settings->job_order_prefix,
                'invoice_prefix' => $settings->invoice_prefix,
            ]
        );

        return view('admin.settings.edit', compact('settings', 'branch', 'branches', 'branchSetting', 'canManageGlobal', 'canManageSms', 'canChooseBranch'));
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $settings = SystemSetting::current();
        $canManageGlobal = $user->isAdmin();
        $canManageSms = $user->isSuperAdmin();
        $canChooseBranch = $user->isAdmin();
        $branch = $canChooseBranch
            ? Branch::query()->findOrFail($request->integer('branch_id'))
            : $user->branch;

        abort_unless($branch, 403);

        $rules = [
            'branch_id' => [$canChooseBranch ? 'required' : 'nullable', 'exists:branches,id'],
            'branch_name' => ['required', 'string', 'max:255'],
            'branch_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('branches', 'code')->ignore($branch->id),
            ],
            'branch_address' => ['nullable', 'string', 'max:255'],
            'branch_contact' => ['nullable', 'string', 'max:50'],
            'branch_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'branch_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'attendance_radius_meters' => ['nullable', 'integer', 'min:25', 'max:5000'],
            'machine_count' => ['nullable', 'integer', 'min:0', 'max:100'],
            'receipt_header' => ['nullable', 'string'],
            'receipt_footer' => ['nullable', 'string'],
            'operating_hours' => ['nullable', 'array'],
            'operating_hours.*.open' => ['nullable', 'date_format:H:i'],
            'operating_hours.*.close' => ['nullable', 'date_format:H:i'],
            'default_price_per_kilo' => ['nullable', 'numeric', 'min:0'],
            'default_price_per_load' => ['nullable', 'numeric', 'min:0'],
            'default_price_per_piece' => ['nullable', 'numeric', 'min:0'],
            'job_order_prefix' => ['nullable', 'string', 'max:20'],
            'invoice_prefix' => ['nullable', 'string', 'max:20'],
        ];

        if ($canManageGlobal) {
            $rules = array_merge($rules, [
                'business_name' => ['required', 'string', 'max:255'],
                'business_logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
                'business_email' => ['nullable', 'email', 'max:255'],
                'contact_number' => ['required', 'string', 'max:50'],
                'business_address' => ['required', 'string'],
                'currency' => ['required', 'string', 'max:10'],
                'vat_enabled' => ['nullable', 'boolean'],
                'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'primary_color' => ['required', 'string', 'max:20'],
                'dark_mode_default' => ['nullable', 'boolean'],
            ]);

            if ($canManageSms) {
                $rules = array_merge($rules, [
                    'sms_provider' => ['nullable', 'string', 'max:100'],
                    'sms_api_key' => ['nullable', 'string'],
                    'twilio_account_sid' => ['nullable', 'string', 'max:255'],
                    'twilio_auth_token' => ['nullable', 'string'],
                    'twilio_from_number' => ['nullable', 'string', 'max:50'],
                    'sms_enabled' => ['nullable', 'boolean'],
                ]);
            }
        }

        $validated = $request->validate($rules);

        if ($canManageGlobal && $request->hasFile('business_logo')) {
            if ($settings->business_logo) {
                Storage::disk('public')->delete($settings->business_logo);
            }

            $validated['business_logo'] = $request->file('business_logo')
                ->store('settings', 'public');
            Storage::disk('public')->setVisibility($validated['business_logo'], 'public');
        }

        $branch->update([
            'name' => $validated['branch_name'],
            'code' => $validated['branch_code'],
            'address' => $validated['branch_address'] ?? null,
            'contact_number' => $validated['branch_contact'] ?? null,
            'latitude' => $validated['branch_latitude'] ?? null,
            'longitude' => $validated['branch_longitude'] ?? null,
            'attendance_radius_meters' => $validated['attendance_radius_meters'] ?? null,
            'machine_count' => $validated['machine_count'] ?? 0,
        ]);

        BranchSetting::updateOrCreate(
            ['branch_id' => $branch->id],
            [
                'receipt_header' => $validated['receipt_header'] ?? null,
                'receipt_footer' => $validated['receipt_footer'] ?? null,
                'operating_hours' => $request->input('operating_hours', []),
                'default_price_per_kilo' => $validated['default_price_per_kilo'] ?? null,
                'default_price_per_load' => $validated['default_price_per_load'] ?? null,
                'default_price_per_piece' => $validated['default_price_per_piece'] ?? null,
                'job_order_prefix' => $validated['job_order_prefix'] ?? null,
                'invoice_prefix' => $validated['invoice_prefix'] ?? null,
            ]
        );

        unset(
            $validated['branch_id'],
            $validated['branch_name'],
            $validated['branch_code'],
            $validated['branch_address'],
            $validated['branch_contact'],
            $validated['branch_latitude'],
            $validated['branch_longitude'],
            $validated['attendance_radius_meters'],
            $validated['machine_count'],
            $validated['receipt_header'],
            $validated['receipt_footer'],
            $validated['operating_hours'],
            $validated['default_price_per_kilo'],
            $validated['default_price_per_load'],
            $validated['default_price_per_piece'],
            $validated['job_order_prefix'],
            $validated['invoice_prefix'],
        );

        if ($canManageGlobal) {
            $validated['vat_enabled'] = $request->boolean('vat_enabled');
            $validated['dark_mode_default'] = $request->boolean('dark_mode_default');
            if ($canManageSms) {
                $validated['sms_enabled'] = $request->boolean('sms_enabled');
            }

            $settings->fill($validated);
            $settings->is_completed = $settings->isComplete();
            $settings->save();
        }

        Activity::log($request, $canManageGlobal ? 'global_settings_updated' : 'branch_settings_updated', $branch, [
            'branch' => $branch->name,
            'global' => $canManageGlobal ? 'yes' : 'no',
        ], $branch->id);

        return redirect()
            ->route('admin.settings.edit', ['branch_id' => $branch->id])
            ->with('success', 'System settings saved successfully.');
    }
}
