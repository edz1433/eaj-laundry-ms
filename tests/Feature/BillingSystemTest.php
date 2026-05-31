<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchBillingRecord;
use App\Models\BranchExpense;
use App\Models\SystemSetting;
use App\Models\SystemTrialSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BillingSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_trial_allows_branch_user_and_shows_banner_but_not_super_admin(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial('2026-05-01', '2026-05-31');

        $branch = $this->createBranch('Trial Branch', 'TRIAL');
        $user = User::factory()->create([
            'role' => 'admin',
            'branch_id' => $branch->id,
            'access' => ['dashboard'],
        ]);
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $this->travelTo(Carbon::parse('2026-05-15'));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('System Free Trial Active Until May 31, 2026');

        $this->actingAs($superAdmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('System Free Trial Active Until');
    }

    public function test_trial_date_range_skips_billing_even_when_toggle_is_off(): void
    {
        $this->completeSystemSettings();
        SystemTrialSetting::query()->create([
            'trial_enabled' => false,
            'trial_start_date' => '2026-05-01',
            'trial_end_date' => '2026-05-31',
            'trial_status' => 'inactive',
            'grace_period_days' => 0,
        ]);

        $branch = $this->createBranch('Date Trial Branch', 'DTRIAL');
        $user = User::factory()->create([
            'role' => 'admin',
            'branch_id' => $branch->id,
            'access' => ['dashboard'],
        ]);

        $this->travelTo(Carbon::parse('2026-05-15'));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('System Free Trial Active Until May 31, 2026');
    }

    public function test_blank_trial_settings_do_not_lock_branch_users_before_trial_is_configured(): void
    {
        $this->completeSystemSettings();

        $branch = $this->createBranch('Setup Branch', 'SETUP');
        $user = User::factory()->create([
            'role' => 'admin',
            'branch_id' => $branch->id,
            'access' => ['dashboard'],
        ]);

        SystemTrialSetting::query()->create([
            'trial_enabled' => false,
            'trial_status' => 'inactive',
            'grace_period_days' => 0,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Subscription Expired');
    }

    public function test_expired_trial_locks_unpaid_branch_after_grace_only(): void
    {
        $this->completeSystemSettings();
        $this->expiredTrial(graceDays: 2);
        $this->travelTo(Carbon::parse('2026-05-10'));

        $paidBranch = $this->createBranch('Paid Branch', 'PAID');
        $unpaidBranch = $this->createBranch('Unpaid Branch', 'UNPAID');
        $paidUser = User::factory()->create([
            'role' => 'admin',
            'branch_id' => $paidBranch->id,
            'access' => ['dashboard'],
        ]);
        $unpaidUser = User::factory()->create([
            'role' => 'admin',
            'branch_id' => $unpaidBranch->id,
            'access' => ['dashboard'],
        ]);

        BranchBillingRecord::create([
            'branch_id' => $paidBranch->id,
            'billing_month' => 5,
            'billing_year' => 2026,
            'amount' => 1000,
            'due_date' => '2026-05-05',
            'status' => 'paid',
        ]);

        BranchBillingRecord::create([
            'branch_id' => $unpaidBranch->id,
            'billing_month' => 5,
            'billing_year' => 2026,
            'amount' => 1000,
            'due_date' => '2026-05-05',
            'status' => 'unpaid',
        ]);

        $this->actingAs($paidUser)
            ->get(route('dashboard'))
            ->assertOk();

        $this->actingAs($unpaidUser)
            ->get(route('dashboard'))
            ->assertStatus(402)
            ->assertSee('Branch subscription has expired');
    }

    public function test_unpaid_branch_can_access_during_grace_period_with_warning(): void
    {
        $this->completeSystemSettings();
        $this->expiredTrial(graceDays: 5);
        $this->travelTo(Carbon::parse('2026-05-07'));

        $branch = $this->createBranch('Grace Branch', 'GRACE');
        $user = User::factory()->create([
            'role' => 'admin',
            'branch_id' => $branch->id,
            'access' => ['dashboard'],
        ]);

        BranchBillingRecord::create([
            'branch_id' => $branch->id,
            'billing_month' => 5,
            'billing_year' => 2026,
            'amount' => 1000,
            'due_date' => '2026-05-05',
            'status' => 'unpaid',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Your branch subscription for May 2026 is unpaid');
    }

    public function test_super_admin_generates_billing_and_updates_only_unpaid_records(): void
    {
        $this->completeSystemSettings();
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $branch = $this->createBranch('Generated Branch', 'GEN');

        BranchBillingRecord::create([
            'branch_id' => $branch->id,
            'billing_month' => 5,
            'billing_year' => 2026,
            'amount' => 900,
            'due_date' => '2026-05-05',
            'status' => 'paid',
        ]);

        $this->actingAs($superAdmin)
            ->post(route('admin.billing.generate'), [
                'branches' => [$branch->id],
                'billing_year' => 2026,
                'start_month' => 5,
                'end_month' => 6,
                'prices' => [$branch->id => 1500],
                'due_day' => 10,
                'update_unpaid' => 1,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('branch_billing_records', [
            'branch_id' => $branch->id,
            'billing_month' => 5,
            'billing_year' => 2026,
            'amount' => 900,
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('branch_billing_records', [
            'branch_id' => $branch->id,
            'billing_month' => 6,
            'billing_year' => 2026,
            'amount' => 1500,
            'status' => 'unpaid',
        ]);
    }

    public function test_marking_paid_creates_one_linked_branch_expense(): void
    {
        $this->completeSystemSettings();
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $branch = $this->createBranch('Expense Branch', 'EXP');
        $record = BranchBillingRecord::create([
            'branch_id' => $branch->id,
            'billing_month' => 5,
            'billing_year' => 2026,
            'amount' => 1200,
            'due_date' => '2026-05-05',
            'status' => 'unpaid',
        ]);

        $payload = [
            'payment_date' => '2026-05-12',
            'payment_method' => 'Bank Transfer',
            'reference_no' => 'REF-001',
            'remarks' => 'Paid in full',
        ];

        $this->actingAs($superAdmin)
            ->patch(route('admin.billing.records.mark-paid', $record), $payload)
            ->assertRedirect();

        $this->actingAs($superAdmin)
            ->patch(route('admin.billing.records.mark-paid', $record), $payload)
            ->assertRedirect();

        $record->refresh();

        $this->assertSame('paid', $record->status);
        $this->assertNotNull($record->expense_id);
        $this->assertSame(1, BranchExpense::where('source', 'branch_billing')->where('source_id', $record->id)->count());
        $this->assertDatabaseHas('branch_expenses', [
            'branch_id' => $branch->id,
            'category' => 'System Monthly Subscription',
            'title' => 'System Billing - May 2026',
            'amount' => 1200,
            'source' => 'branch_billing',
            'source_id' => $record->id,
            'created_by' => $superAdmin->id,
        ]);
    }

    private function completeSystemSettings(): void
    {
        SystemSetting::query()->create([
            'business_name' => 'EAJ Laundry',
            'contact_number' => '09171234567',
            'business_address' => 'Manila',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'primary_color' => '#2E7D32',
            'is_completed' => true,
        ]);
    }

    private function createBranch(string $name, string $code): Branch
    {
        return Branch::query()->create([
            'name' => $name,
            'code' => $code,
            'address' => 'Manila',
            'contact_number' => '09171234567',
            'is_active' => true,
        ]);
    }

    private function activeTrial(string $start, string $end): void
    {
        SystemTrialSetting::query()->create([
            'trial_enabled' => true,
            'trial_start_date' => $start,
            'trial_end_date' => $end,
            'trial_status' => 'active',
            'grace_period_days' => 0,
        ]);
    }

    private function expiredTrial(int $graceDays): void
    {
        SystemTrialSetting::query()->create([
            'trial_enabled' => false,
            'trial_start_date' => '2026-04-01',
            'trial_end_date' => '2026-04-30',
            'trial_status' => 'expired',
            'grace_period_days' => $graceDays,
        ]);
    }
}
