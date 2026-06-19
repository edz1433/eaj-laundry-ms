<?php

namespace Tests\Feature;

use App\Models\AccountsPayable;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\EmployeeAttendanceRecord;
use App\Models\AttendanceEmployee;
use App\Models\SystemSetting;
use App\Models\SystemTrialSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountsPayableTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_cash_funding_supports_partial_and_full_repayment(): void
    {
        [$branch, $manager] = $this->financeUser(['accounts_payable', 'petty_cash']);

        $this->actingAs($manager)
            ->post(route('admin.accounts-payable.store'), [
                'branch_id' => $branch->id,
                'creditor_name' => 'Owner',
                'description' => 'Emergency working capital',
                'amount' => 5000,
                'funding_method' => 'cash',
                'funded_at' => today()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $payable = AccountsPayable::query()->firstOrFail();
        $this->assertSame('5000.00', $payable->balance);
        $this->assertDatabaseHas('money_movements', [
            'branch_id' => $branch->id,
            'direction' => 'in',
            'amount' => 5000,
        ]);

        $this->actingAs($manager)
            ->post(route('admin.accounts-payable.payments.store', $payable), [
                'amount' => 2000,
                'payment_date' => today()->toDateString(),
                'payment_method' => 'cash',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('partial', $payable->fresh()->status);
        $this->assertSame('3000.00', $payable->fresh()->balance);

        $this->actingAs($manager)
            ->post(route('admin.accounts-payable.payments.store', $payable), [
                'amount' => 3000,
                'payment_date' => today()->toDateString(),
                'payment_method' => 'cash',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('paid', $payable->fresh()->status);
        $this->assertSame('0.00', $payable->fresh()->balance);
        $this->assertDatabaseCount('accounts_payable_payments', 2);
        $this->assertDatabaseHas('money_movements', [
            'branch_id' => $branch->id,
            'direction' => 'out',
            'amount' => 3000,
        ]);
    }

    public function test_expense_record_is_forced_to_store_funded_even_when_owner_is_submitted(): void
    {
        [$branch, $manager] = $this->financeUser(['expenses', 'accounts_payable']);

        $this->actingAs($manager)
            ->post(route('admin.expenses.store'), [
                'branch_id' => $branch->id,
                'category' => 'supplies',
                'title' => 'Detergent supplies',
                'amount' => 1250,
                'expense_date' => today()->toDateString(),
                'payment_method' => 'cash',
                'paid_from' => 'owner',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('branch_expenses', [
            'branch_id' => $branch->id,
            'category' => 'supplies',
            'paid_from' => 'store_cash',
        ]);
        $this->assertDatabaseCount('accounts_payables', 0);
        $this->assertDatabaseCount('money_movements', 0);
    }

    public function test_accounts_payable_accepts_cheque_repayment_without_cash_movement(): void
    {
        [$branch, $manager] = $this->financeUser(['accounts_payable']);

        $payable = AccountsPayable::query()->create([
            'branch_id' => $branch->id,
            'created_by' => $manager->id,
            'payable_number' => 'AP-CHEQUE-001',
            'creditor_name' => 'Owner',
            'source_type' => 'owner_funding',
            'funding_method' => 'gcash',
            'description' => 'Working capital',
            'original_amount' => 1500,
            'paid_amount' => 0,
            'balance' => 1500,
            'status' => 'unpaid',
            'funded_at' => today(),
        ]);

        $this->actingAs($manager)
            ->post(route('admin.accounts-payable.payments.store', $payable), [
                'amount' => 1500,
                'payment_date' => today()->toDateString(),
                'payment_method' => 'cheque',
                'reference_no' => 'CHK-1001',
            ])
            ->assertSessionHasNoErrors();

        $payable->refresh();
        $this->assertSame('paid', $payable->status);
        $this->assertSame('0.00', $payable->balance);
        $this->assertDatabaseHas('accounts_payable_payments', [
            'accounts_payable_id' => $payable->id,
            'payment_method' => 'cheque',
            'reference_no' => 'CHK-1001',
            'amount' => 1500,
        ]);
        $this->assertDatabaseCount('money_movements', 0);
    }

    public function test_attendance_page_displays_twelve_hour_times(): void
    {
        [$branch, $manager] = $this->financeUser(['attendance']);
        $employee = AttendanceEmployee::query()->create([
            'branch_id' => $branch->id,
            'first_name' => 'Ana',
            'last_name' => 'Staff',
            'username' => 'ana-staff',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        EmployeeAttendanceRecord::query()->create([
            'branch_id' => $branch->id,
            'attendance_employee_id' => $employee->id,
            'work_date' => today(),
            'clock_in' => ['13:05:00'],
            'clock_out' => ['18:30:00'],
        ]);

        $this->actingAs($manager)
            ->get(route('admin.attendance.index'))
            ->assertOk()
            ->assertSee('01:05 PM')
            ->assertSee('06:30 PM')
            ->assertDontSee('13:05:00');
    }

    public function test_settings_page_has_no_pricing_tab(): void
    {
        [$branch, $manager] = $this->financeUser(['settings']);

        $this->actingAs($manager)
            ->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertDontSee("tab = 'pricing'", false)
            ->assertDontSee('Default Price Per Kilo');
    }

    private function financeUser(array $access): array
    {
        SystemSetting::query()->create([
            'business_name' => 'SPIN KLEAN LAUNDRY',
            'contact_number' => '09171234567',
            'business_address' => 'Manila',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'is_completed' => true,
        ]);
        SystemTrialSetting::query()->create([
            'trial_enabled' => true,
            'trial_start_date' => now()->subDay(),
            'trial_end_date' => now()->addDay(),
            'trial_status' => 'active',
            'grace_period_days' => 0,
        ]);
        $branch = Branch::query()->create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        $manager = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $branch->id,
            'access' => $access,
        ]);

        return [$branch, $manager];
    }
}
