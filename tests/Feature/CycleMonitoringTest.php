<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CycleRecord;
use App\Models\JobOrder;
use App\Models\SystemSetting;
use App\Models\SystemTrialSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CycleMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_cycle_monitoring_only_allows_finish_statuses_manually(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $branch = $this->createBranch();
        $customer = $this->createCustomer($branch);
        $user = User::factory()->create([
            'role' => 'admin',
            'branch_id' => $branch->id,
            'access' => ['cycles'],
        ]);
        $order = $this->createJobOrder($branch, $customer);

        $this->actingAs($user)
            ->patch(route('admin.cycles.status', $order), [
                'status' => 'washing',
            ])
            ->assertSessionHasErrors('status');

        $this->actingAs($user)
            ->patch(route('admin.cycles.status', $order), [
                'status' => 'ready_for_pickup',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('job_orders', [
            'id' => $order->id,
            'status' => 'ready_for_pickup',
        ]);
    }

    public function test_starting_iron_cycle_tracks_cycle_and_sets_reliable_work_status(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $branch = $this->createBranch();
        $customer = $this->createCustomer($branch);
        $user = User::factory()->create([
            'role' => 'admin',
            'branch_id' => $branch->id,
            'access' => ['cycles'],
        ]);
        $order = $this->createJobOrder($branch, $customer);

        $this->actingAs($user)
            ->post(route('admin.cycles.store', $order), [
                'cycle_type' => 'iron',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('cycle_records', [
            'job_order_id' => $order->id,
            'cycle_type' => 'iron',
            'cycle_number' => 1,
        ]);

        $this->assertDatabaseHas('job_orders', [
            'id' => $order->id,
            'status' => 'folding',
        ]);
    }

    public function test_user_can_remove_spammed_cycle_and_remaining_cycles_are_renumbered(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $branch = $this->createBranch();
        $customer = $this->createCustomer($branch);
        $user = User::factory()->create([
            'role' => 'admin',
            'branch_id' => $branch->id,
            'access' => ['cycles'],
        ]);
        $order = $this->createJobOrder($branch, $customer);

        $first = CycleRecord::query()->create([
            'job_order_id' => $order->id,
            'user_id' => $user->id,
            'cycle_type' => 'wash',
            'cycle_number' => 1,
            'started_at' => now()->subMinutes(2),
        ]);
        $second = CycleRecord::query()->create([
            'job_order_id' => $order->id,
            'user_id' => $user->id,
            'cycle_type' => 'wash',
            'cycle_number' => 2,
            'started_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)
            ->delete(route('admin.cycles.destroy', $first))
            ->assertRedirect();

        $this->assertDatabaseMissing('cycle_records', [
            'id' => $first->id,
        ]);

        $this->assertDatabaseHas('cycle_records', [
            'id' => $second->id,
            'cycle_number' => 1,
        ]);
    }

    public function test_branch_user_cannot_remove_other_branch_cycle(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $branch = $this->createBranch();
        $otherBranch = Branch::query()->create([
            'name' => 'Other Branch',
            'code' => 'OTHER',
            'address' => 'Manila',
            'contact_number' => '09171234567',
            'is_active' => true,
        ]);
        $customer = $this->createCustomer($otherBranch);
        $user = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $branch->id,
            'access' => ['cycles'],
        ]);
        $order = $this->createJobOrder($otherBranch, $customer);
        $cycle = CycleRecord::query()->create([
            'job_order_id' => $order->id,
            'user_id' => $user->id,
            'cycle_type' => 'wash',
            'cycle_number' => 1,
            'started_at' => now(),
        ]);

        $this->actingAs($user)
            ->delete(route('admin.cycles.destroy', $cycle))
            ->assertForbidden();

        $this->assertDatabaseHas('cycle_records', [
            'id' => $cycle->id,
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
            'primary_color' => '#0EA5E9',
            'is_completed' => true,
        ]);
    }

    private function activeTrial(): void
    {
        SystemTrialSetting::query()->create([
            'trial_enabled' => true,
            'trial_start_date' => now()->subDay()->toDateString(),
            'trial_end_date' => now()->addDay()->toDateString(),
            'trial_status' => 'active',
            'grace_period_days' => 0,
        ]);
    }

    private function createBranch(): Branch
    {
        return Branch::query()->create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'address' => 'Manila',
            'contact_number' => '09171234567',
            'is_active' => true,
        ]);
    }

    private function createCustomer(Branch $branch): Customer
    {
        return Customer::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Laundry Customer',
            'phone' => '09171234567',
            'billing_type' => 'regular',
            'is_active' => true,
        ]);
    }

    private function createJobOrder(Branch $branch, Customer $customer): JobOrder
    {
        return JobOrder::query()->create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'job_order_number' => 'JO-TEST-001',
            'status' => 'pending',
            'subtotal' => 0,
            'discount' => 0,
            'tax' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'balance' => 0,
        ]);
    }
}
