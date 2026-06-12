<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\AttendanceEmployee;
use App\Models\BranchSetting;
use App\Models\Customer;
use App\Models\CycleRecord;
use App\Models\JobOrder;
use App\Models\Inventory;
use App\Models\Payment;
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

    public function test_cycle_monitoring_filters_by_branch_customer_and_date_for_admin(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $branchA = $this->createBranch(['name' => 'Branch A', 'code' => 'A']);
        $branchB = $this->createBranch(['name' => 'Branch B', 'code' => 'B']);
        $customerA = $this->createCustomer($branchA);
        $customerB = $this->createCustomer($branchB);
        $customerA->update(['name' => 'Cycle Customer A']);
        $customerB->update(['name' => 'Cycle Customer B']);
        $admin = User::factory()->create([
            'role' => 'admin',
            'access' => ['cycles'],
        ]);
        $matchingOrder = $this->createJobOrder($branchA, $customerA, 'JO-CYCLE-MATCH');
        $otherCustomerOrder = $this->createJobOrder($branchB, $customerB, 'JO-CYCLE-HIDDEN');
        $oldOrder = $this->createJobOrder($branchA, $customerA, 'JO-CYCLE-OLD');

        $matchingOrder->forceFill(['created_at' => now()->setDate(2026, 6, 6)])->save();
        $otherCustomerOrder->forceFill(['created_at' => now()->setDate(2026, 6, 6)])->save();
        $oldOrder->forceFill(['created_at' => now()->setDate(2026, 6, 5)])->save();

        $this->actingAs($admin)
            ->get(route('admin.cycles.index', [
                'branch_id' => $branchA->id,
                'customer_id' => $customerA->id,
                'date_range' => '2026-06-06 to 2026-06-06',
            ]))
            ->assertOk()
            ->assertSee('name="branch_id"', false)
            ->assertSee('name="customer_id"', false)
            ->assertSee('name="date_range"', false)
            ->assertSee('JO-CYCLE-MATCH')
            ->assertDontSee('JO-CYCLE-HIDDEN')
            ->assertDontSee('JO-CYCLE-OLD');
    }

    public function test_cycle_monitoring_admin_customer_dropdown_depends_on_selected_branch(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $branchA = $this->createBranch(['name' => 'Branch A', 'code' => 'A']);
        $branchB = $this->createBranch(['name' => 'Branch B', 'code' => 'B']);
        $customerA = $this->createCustomer($branchA);
        $customerB = $this->createCustomer($branchB);
        $customerA->update(['name' => 'Branch A Customer']);
        $customerB->update(['name' => 'Branch B Customer']);
        $admin = User::factory()->create([
            'role' => 'admin',
            'access' => ['cycles'],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.cycles.index'))
            ->assertOk()
            ->assertSee('All customers')
            ->assertSee('Select a branch to list customers')
            ->assertDontSee('Branch A Customer')
            ->assertDontSee('Branch B Customer');

        $this->actingAs($admin)
            ->get(route('admin.cycles.index', ['branch_id' => $branchA->id]))
            ->assertOk()
            ->assertSee('All customers')
            ->assertSee('Branch A Customer')
            ->assertDontSee('Branch B Customer');
    }

    public function test_cycle_monitoring_branch_user_gets_customer_filter_without_branch_selector(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $branchA = $this->createBranch(['name' => 'Branch A', 'code' => 'A']);
        $branchB = $this->createBranch(['name' => 'Branch B', 'code' => 'B']);
        $customerA = $this->createCustomer($branchA);
        $customerB = $this->createCustomer($branchB);
        $customerA->update(['name' => 'Branch Customer A']);
        $customerB->update(['name' => 'Branch Customer B']);
        $user = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $branchA->id,
            'access' => ['cycles'],
        ]);
        $visibleOrder = $this->createJobOrder($branchA, $customerA, 'JO-BRANCH-CUSTOMER');
        $hiddenOrder = $this->createJobOrder($branchB, $customerB, 'JO-OTHER-CUSTOMER');

        $visibleOrder->forceFill(['created_at' => now()->setDate(2026, 6, 6)])->save();
        $hiddenOrder->forceFill(['created_at' => now()->setDate(2026, 6, 6)])->save();

        $this->actingAs($user)
            ->get(route('admin.cycles.index', [
                'customer_id' => $customerA->id,
                'date_range' => '2026-06-06 to 2026-06-06',
            ]))
            ->assertOk()
            ->assertDontSee('name="branch_id"', false)
            ->assertSee('name="customer_id"', false)
            ->assertSee($customerA->name)
            ->assertDontSee($customerB->name)
            ->assertSee('JO-BRANCH-CUSTOMER')
            ->assertDontSee('JO-OTHER-CUSTOMER');
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

    public function test_wash_cycle_requires_machine_when_branch_has_machines(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $branch = $this->createBranch(['machine_count' => 5]);
        $customer = $this->createCustomer($branch);
        $user = User::factory()->create([
            'role' => 'admin',
            'branch_id' => $branch->id,
            'access' => ['cycles'],
        ]);
        $order = $this->createJobOrder($branch, $customer);

        $this->actingAs($user)
            ->post(route('admin.cycles.store', $order), [
                'cycle_type' => 'wash',
            ])
            ->assertSessionHasErrors('machine_number');
    }

    public function test_wash_cycle_tracks_selected_machine(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $branch = $this->createBranch(['machine_count' => 5]);
        $customer = $this->createCustomer($branch);
        $user = User::factory()->create([
            'role' => 'admin',
            'branch_id' => $branch->id,
            'access' => ['cycles'],
        ]);
        $order = $this->createJobOrder($branch, $customer);

        $this->actingAs($user)
            ->post(route('admin.cycles.store', $order), [
                'cycle_type' => 'wash',
                'machine_number' => 3,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('cycle_records', [
            'job_order_id' => $order->id,
            'cycle_type' => 'wash',
            'machine_number' => 3,
            'cycle_number' => 1,
        ]);
    }

    public function test_active_machine_cannot_be_assigned_twice(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $branch = $this->createBranch(['machine_count' => 5]);
        $customer = $this->createCustomer($branch);
        $user = User::factory()->create([
            'role' => 'admin',
            'branch_id' => $branch->id,
            'access' => ['cycles'],
        ]);
        $firstOrder = $this->createJobOrder($branch, $customer, 'JO-TEST-001');
        $secondOrder = $this->createJobOrder($branch, $customer, 'JO-TEST-002');

        CycleRecord::query()->create([
            'job_order_id' => $firstOrder->id,
            'user_id' => $user->id,
            'cycle_type' => 'wash',
            'machine_number' => 2,
            'cycle_number' => 1,
            'started_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.cycles.store', $secondOrder), [
                'cycle_type' => 'wash',
                'machine_number' => 2,
            ])
            ->assertSessionHasErrors('machine_number');
    }

    public function test_machine_can_be_reused_after_cycle_ends(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $branch = $this->createBranch(['machine_count' => 5]);
        $customer = $this->createCustomer($branch);
        $user = User::factory()->create([
            'role' => 'admin',
            'branch_id' => $branch->id,
            'access' => ['cycles'],
        ]);
        $firstOrder = $this->createJobOrder($branch, $customer, 'JO-TEST-001');
        $secondOrder = $this->createJobOrder($branch, $customer, 'JO-TEST-002');

        CycleRecord::query()->create([
            'job_order_id' => $firstOrder->id,
            'user_id' => $user->id,
            'cycle_type' => 'wash',
            'machine_number' => 2,
            'cycle_number' => 1,
            'started_at' => now()->subHour(),
            'ended_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.cycles.store', $secondOrder), [
                'cycle_type' => 'wash',
                'machine_number' => 2,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('cycle_records', [
            'job_order_id' => $secondOrder->id,
            'machine_number' => 2,
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

    public function test_pickup_dropoff_order_can_be_processed_by_full_service_branch(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $dropoffBranch = $this->createBranch([
            'name' => 'Pickup Counter',
            'code' => 'PICKUP',
            'branch_type' => 'pickup_dropoff',
            'machine_count' => 0,
        ]);
        $productionBranch = $this->createBranch([
            'name' => 'Main Production',
            'code' => 'MAINPROD',
            'branch_type' => 'full_service',
            'machine_count' => 4,
        ]);
        $customer = $this->createCustomer($dropoffBranch);
        $order = $this->createJobOrder($dropoffBranch, $customer, 'JO-PICKUP-001');
        $order->update([
            'processing_branch_id' => $productionBranch->id,
            'current_branch_id' => $dropoffBranch->id,
            'release_branch_id' => $dropoffBranch->id,
        ]);
        $productionUser = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $productionBranch->id,
            'access' => ['cycles'],
        ]);

        $this->actingAs($productionUser)
            ->get(route('admin.cycles.index'))
            ->assertOk()
            ->assertDontSee('JO-PICKUP-001');

        $this->actingAs($productionUser)
            ->get(route('admin.cycles.scan', $order))
            ->assertRedirect(route('admin.cycles.index', ['search' => 'JO-PICKUP-001']));

        $this->actingAs($productionUser)
            ->get(route('admin.cycles.index'))
            ->assertOk()
            ->assertSee('JO-PICKUP-001')
            ->assertSee('Drop-off: Pickup Counter')
            ->assertSee('Processing: Main Production');

        $this->actingAs($productionUser)
            ->post(route('admin.cycles.store', $order), [
                'cycle_type' => 'wash',
                'machine_number' => 3,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('cycle_records', [
            'job_order_id' => $order->id,
            'cycle_type' => 'wash',
            'machine_number' => 3,
        ]);
        $this->assertDatabaseHas('job_orders', [
            'id' => $order->id,
            'branch_id' => $dropoffBranch->id,
            'processing_branch_id' => $productionBranch->id,
            'status' => 'washing',
        ]);
    }

    public function test_processing_branch_manager_cannot_edit_dropoff_branch_job_order(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $dropoffBranch = $this->createBranch([
            'name' => 'Pickup Counter',
            'code' => 'PICK',
            'branch_type' => 'pickup_dropoff',
        ]);
        $productionBranch = $this->createBranch([
            'name' => 'Main Production',
            'code' => 'PROD',
            'branch_type' => 'full_service',
            'machine_count' => 4,
        ]);
        $customer = $this->createCustomer($dropoffBranch);
        $order = $this->createJobOrder($dropoffBranch, $customer, 'JO-PICKUP-EDIT');
        $order->update([
            'processing_branch_id' => $productionBranch->id,
            'current_branch_id' => $productionBranch->id,
            'release_branch_id' => $productionBranch->id,
            'production_accepted_at' => now(),
        ]);
        $productionUser = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $productionBranch->id,
            'access' => ['job_orders', 'cycles'],
        ]);

        $this->actingAs($productionUser)
            ->get(route('admin.job-orders.edit', $order))
            ->assertForbidden();

        $this->actingAs($productionUser)
            ->get(route('admin.cycles.index'))
            ->assertOk()
            ->assertSee('JO-PICKUP-EDIT');
    }

    public function test_full_service_branch_user_cannot_assign_own_order_to_other_processing_branch(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $branchA = $this->createBranch([
            'name' => 'Branch A',
            'code' => 'BRA',
            'branch_type' => 'full_service',
            'machine_count' => 2,
        ]);
        $branchB = $this->createBranch([
            'name' => 'Branch B',
            'code' => 'BRB',
            'branch_type' => 'full_service',
            'machine_count' => 2,
        ]);
        $customer = $this->createCustomer($branchA);
        $service = \App\Models\LaundryService::query()->create([
            'branch_id' => $branchA->id,
            'name' => 'Wash',
            'pricing_type' => 'kilo',
            'price' => 100,
            'is_active' => true,
        ]);
        $manager = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $branchA->id,
            'access' => ['job_orders'],
        ]);

        $this->actingAs($manager)
            ->post(route('admin.job-orders.store'), [
                'branch_id' => $branchA->id,
                'processing_branch_id' => $branchB->id,
                'customer_id' => $customer->id,
                'items' => [[
                    'laundry_service_id' => $service->id,
                    'description' => $service->name,
                    'quantity' => 1,
                    'unit_price' => 100,
                ]],
                'discount' => 0,
                'paid_amount' => 0,
                'transaction_type' => 'walk_in',
            ])
            ->assertRedirect(route('admin.job-orders.index'));

        $order = JobOrder::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame($branchA->id, $order->branch_id);
        $this->assertSame($branchA->id, $order->processing_branch_id);
    }

    public function test_pickup_dropoff_branch_user_can_assign_processing_branch_on_new_order(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $dropoffBranch = $this->createBranch([
            'name' => 'Pickup Branch',
            'code' => 'DROP',
            'branch_type' => 'pickup_dropoff',
            'machine_count' => 0,
        ]);
        $productionBranch = $this->createBranch([
            'name' => 'Production Branch',
            'code' => 'PROD2',
            'branch_type' => 'full_service',
            'machine_count' => 3,
        ]);
        $customer = $this->createCustomer($dropoffBranch);
        $service = \App\Models\LaundryService::query()->create([
            'branch_id' => $dropoffBranch->id,
            'name' => 'Wash',
            'pricing_type' => 'kilo',
            'price' => 100,
            'is_active' => true,
        ]);
        $manager = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $dropoffBranch->id,
            'access' => ['job_orders'],
        ]);

        $this->actingAs($manager)
            ->post(route('admin.job-orders.store'), [
                'branch_id' => $dropoffBranch->id,
                'processing_branch_id' => $productionBranch->id,
                'customer_id' => $customer->id,
                'items' => [[
                    'laundry_service_id' => $service->id,
                    'description' => $service->name,
                    'quantity' => 1,
                    'unit_price' => 100,
                ]],
                'discount' => 0,
                'paid_amount' => 0,
                'transaction_type' => 'walk_in',
            ])
            ->assertRedirect(route('admin.job-orders.index'));

        $order = JobOrder::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame($dropoffBranch->id, $order->branch_id);
        $this->assertSame($productionBranch->id, $order->processing_branch_id);
    }

    public function test_job_order_number_does_not_duplicate_branch_code_when_prefix_matches_branch_code(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $dropoffBranch = $this->createBranch([
            'name' => 'Branch 3',
            'code' => 'B0003',
            'branch_type' => 'pickup_dropoff',
            'machine_count' => 0,
        ]);
        BranchSetting::query()->updateOrCreate(
            ['branch_id' => $dropoffBranch->id],
            ['job_order_prefix' => 'B0003', 'invoice_prefix' => 'INV-B0003']
        );
        $productionBranch = $this->createBranch([
            'name' => 'Production Branch',
            'code' => 'PROD4',
            'branch_type' => 'full_service',
            'machine_count' => 3,
        ]);
        $customer = $this->createCustomer($dropoffBranch);
        $service = \App\Models\LaundryService::query()->create([
            'branch_id' => $dropoffBranch->id,
            'name' => 'Wash',
            'pricing_type' => 'kilo',
            'price' => 100,
            'is_active' => true,
        ]);
        $manager = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $dropoffBranch->id,
            'access' => ['job_orders'],
        ]);

        $this->actingAs($manager)
            ->post(route('admin.job-orders.store'), [
                'branch_id' => $dropoffBranch->id,
                'processing_branch_id' => $productionBranch->id,
                'customer_id' => $customer->id,
                'items' => [[
                    'laundry_service_id' => $service->id,
                    'description' => $service->name,
                    'quantity' => 1,
                    'unit_price' => 100,
                ]],
                'discount' => 0,
                'paid_amount' => 0,
                'transaction_type' => 'walk_in',
            ])
            ->assertRedirect(route('admin.job-orders.index'));

        $order = JobOrder::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertStringStartsWith('B0003-'.now()->format('Ymd').'-', $order->job_order_number);
        $this->assertStringNotContainsString('B0003-B0003', $order->job_order_number);
    }

    public function test_production_branch_can_release_pickup_dropoff_order_here(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $dropoffBranch = $this->createBranch([
            'name' => 'Pickup Branch',
            'code' => 'DROP3',
            'branch_type' => 'pickup_dropoff',
            'machine_count' => 0,
        ]);
        $productionBranch = $this->createBranch([
            'name' => 'Production Branch',
            'code' => 'PROD3',
            'branch_type' => 'full_service',
            'machine_count' => 3,
        ]);
        $customer = $this->createCustomer($dropoffBranch);
        $order = $this->createJobOrder($dropoffBranch, $customer, 'JO-RELEASE-HERE');
        $order->update([
            'processing_branch_id' => $productionBranch->id,
            'current_branch_id' => $productionBranch->id,
            'release_branch_id' => $productionBranch->id,
            'production_accepted_at' => now(),
        ]);
        $productionUser = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $productionBranch->id,
            'access' => ['cycles'],
        ]);

        $this->actingAs($productionUser)
            ->patch(route('admin.cycles.status', $order), ['status' => 'ready_for_pickup'])
            ->assertRedirect();

        $this->actingAs($productionUser)
            ->patch(route('admin.cycles.release', $order), ['action' => 'release_here'])
            ->assertRedirect();

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertSame($productionBranch->id, $order->current_branch_id);
        $this->assertSame($productionBranch->id, $order->release_branch_id);
        $this->assertNotNull($order->released_at);
    }

    public function test_production_branch_can_return_ready_order_to_dropoff_for_release(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $dropoffBranch = $this->createBranch([
            'name' => 'Pickup Branch',
            'code' => 'DROP4',
            'branch_type' => 'pickup_dropoff',
            'machine_count' => 0,
        ]);
        $productionBranch = $this->createBranch([
            'name' => 'Production Branch',
            'code' => 'PROD4',
            'branch_type' => 'full_service',
            'machine_count' => 3,
        ]);
        $customer = $this->createCustomer($dropoffBranch);
        $order = $this->createJobOrder($dropoffBranch, $customer, 'JO-RETURN-DROP');
        $order->update([
            'processing_branch_id' => $productionBranch->id,
            'current_branch_id' => $productionBranch->id,
            'release_branch_id' => $productionBranch->id,
            'production_accepted_at' => now(),
        ]);
        $productionUser = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $productionBranch->id,
            'access' => ['cycles'],
        ]);
        $dropoffUser = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $dropoffBranch->id,
            'access' => ['cycles'],
        ]);

        $this->actingAs($productionUser)
            ->patch(route('admin.cycles.status', $order), ['status' => 'ready_for_pickup'])
            ->assertRedirect();

        $this->actingAs($productionUser)
            ->patch(route('admin.cycles.release', $order), ['action' => 'return_to_dropoff'])
            ->assertRedirect();

        $order->refresh();
        $this->assertSame('ready_for_pickup', $order->status);
        $this->assertSame($dropoffBranch->id, $order->current_branch_id);
        $this->assertSame($dropoffBranch->id, $order->release_branch_id);
        $this->assertNotNull($order->returned_to_branch_at);

        $this->actingAs($productionUser)
            ->patch(route('admin.cycles.release', $order), ['action' => 'release_here'])
            ->assertForbidden();

        $this->actingAs($dropoffUser)
            ->patch(route('admin.cycles.release', $order), ['action' => 'release_here'])
            ->assertRedirect();

        $this->assertDatabaseHas('job_orders', [
            'id' => $order->id,
            'status' => 'completed',
            'release_branch_id' => $dropoffBranch->id,
        ]);
    }

    public function test_release_branch_payment_keeps_sales_owner_and_tracks_actual_collection_branch(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $dropoffBranch = $this->createBranch([
            'name' => 'Pickup Branch',
            'code' => 'DROP5',
            'branch_type' => 'pickup_dropoff',
            'machine_count' => 0,
        ]);
        $productionBranch = $this->createBranch([
            'name' => 'Production Branch',
            'code' => 'PROD5',
            'branch_type' => 'full_service',
            'machine_count' => 3,
        ]);
        $customer = $this->createCustomer($dropoffBranch);
        $order = $this->createJobOrder($dropoffBranch, $customer, 'JO-CROSS-PAY');
        $order->update([
            'processing_branch_id' => $productionBranch->id,
            'current_branch_id' => $productionBranch->id,
            'release_branch_id' => $productionBranch->id,
            'production_accepted_at' => now(),
            'status' => 'ready_for_pickup',
            'total' => 500,
            'paid_amount' => 0,
            'balance' => 500,
        ]);
        $productionCashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $productionBranch->id,
            'access' => ['receivables', 'z_readings'],
        ]);

        $this->actingAs($productionCashier)
            ->post(route('admin.receivables.payments.store', $order), [
                'payment_type' => 'cash',
                'amount' => 500,
                'remarks' => 'Paid at production release',
            ])
            ->assertRedirect();

        $payment = Payment::query()->where('job_order_id', $order->id)->firstOrFail();
        $this->assertSame($dropoffBranch->id, $payment->branch_id);
        $this->assertSame($productionBranch->id, $payment->collected_branch_id);
        $this->assertSame('pending', $payment->settlement_status);

        $this->assertDatabaseHas('job_orders', [
            'id' => $order->id,
            'paid_amount' => 500,
            'balance' => 0,
        ]);

        $this->actingAs($productionCashier)
            ->get(route('admin.z-readings.create', [
                'branch_id' => $productionBranch->id,
                'business_date' => today()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Cash payments')
            ->assertSee('500.00');
    }

    public function test_production_branch_can_print_receipt_without_editing_sales_order(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $dropoffBranch = $this->createBranch([
            'name' => 'Pickup Branch',
            'code' => 'DROP6',
            'branch_type' => 'pickup_dropoff',
            'machine_count' => 0,
        ]);
        $productionBranch = $this->createBranch([
            'name' => 'Production Branch',
            'code' => 'PROD6',
            'branch_type' => 'full_service',
            'machine_count' => 3,
        ]);
        $customer = $this->createCustomer($dropoffBranch);
        $order = $this->createJobOrder($dropoffBranch, $customer, 'JO-PRINT-PROD');
        $order->update([
            'processing_branch_id' => $productionBranch->id,
            'current_branch_id' => $productionBranch->id,
            'release_branch_id' => $productionBranch->id,
            'production_accepted_at' => now(),
            'status' => 'ready_for_pickup',
        ]);
        $productionUser = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $productionBranch->id,
            'access' => ['job_orders', 'cycles'],
        ]);

        $this->actingAs($productionUser)
            ->get(route('admin.job-orders.edit', $order))
            ->assertForbidden();

        $this->actingAs($productionUser)
            ->get(route('admin.job-orders.receipt', $order))
            ->assertOk()
            ->assertSee('JO-PRINT-PROD')
            ->assertSee('Production Branch')
            ->assertSee('data:image/svg+xml');
    }

    public function test_production_scan_auto_assigns_pickup_dropoff_order_to_scanning_branch(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $dropoffBranch = $this->createBranch([
            'name' => 'Pickup Branch',
            'code' => 'DROP7',
            'branch_type' => 'pickup_dropoff',
            'machine_count' => 0,
        ]);
        $productionBranch = $this->createBranch([
            'name' => 'Production Branch',
            'code' => 'PROD7',
            'branch_type' => 'full_service',
            'machine_count' => 3,
        ]);
        $customer = $this->createCustomer($dropoffBranch);
        $order = $this->createJobOrder($dropoffBranch, $customer, 'JO-SCAN-PROD');
        $order->update([
            'processing_branch_id' => $productionBranch->id,
            'current_branch_id' => $dropoffBranch->id,
            'release_branch_id' => $dropoffBranch->id,
        ]);
        $productionUser = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $productionBranch->id,
            'access' => ['cycles'],
        ]);

        $this->actingAs($productionUser)
            ->get(route('admin.cycles.scan', $order))
            ->assertRedirect(route('admin.cycles.index', ['search' => 'JO-SCAN-PROD']));

        $this->assertDatabaseHas('job_orders', [
            'id' => $order->id,
            'branch_id' => $dropoffBranch->id,
            'processing_branch_id' => $productionBranch->id,
            'current_branch_id' => $productionBranch->id,
            'release_branch_id' => $productionBranch->id,
        ]);
        $this->assertNotNull($order->fresh()->production_accepted_at);

        $this->actingAs($productionUser)
            ->get(route('admin.cycles.index'))
            ->assertOk()
            ->assertSee('JO-SCAN-PROD')
            ->assertSee('Drop-off: Pickup Branch')
            ->assertSee('Processing: Production Branch');
    }

    public function test_production_scan_cannot_steal_order_after_other_branch_started_cycles(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $dropoffBranch = $this->createBranch([
            'name' => 'Pickup Branch',
            'code' => 'DROP8',
            'branch_type' => 'pickup_dropoff',
            'machine_count' => 0,
        ]);
        $productionBranch = $this->createBranch([
            'name' => 'Production A',
            'code' => 'PRA',
            'branch_type' => 'full_service',
            'machine_count' => 3,
        ]);
        $otherProductionBranch = $this->createBranch([
            'name' => 'Production B',
            'code' => 'PRB',
            'branch_type' => 'full_service',
            'machine_count' => 3,
        ]);
        $customer = $this->createCustomer($dropoffBranch);
        $order = $this->createJobOrder($dropoffBranch, $customer, 'JO-SCAN-LOCKED');
        $order->update([
            'processing_branch_id' => $productionBranch->id,
            'current_branch_id' => $productionBranch->id,
            'release_branch_id' => $productionBranch->id,
            'production_accepted_at' => now(),
        ]);
        CycleRecord::query()->create([
            'job_order_id' => $order->id,
            'user_id' => User::factory()->create(['branch_id' => $productionBranch->id])->id,
            'cycle_type' => 'wash',
            'machine_number' => 1,
            'cycle_number' => 1,
            'started_at' => now(),
        ]);
        $otherProductionUser = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $otherProductionBranch->id,
            'access' => ['cycles'],
        ]);

        $this->actingAs($otherProductionUser)
            ->get(route('admin.cycles.scan', $order))
            ->assertForbidden();

        $this->assertDatabaseHas('job_orders', [
            'id' => $order->id,
            'processing_branch_id' => $productionBranch->id,
        ]);
    }

    public function test_attendance_kiosk_scan_accepts_assigned_pickup_order_into_employee_branch(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $dropoffBranch = $this->createBranch([
            'name' => 'Pickup Branch',
            'code' => 'DROP9',
            'branch_type' => 'pickup_dropoff',
            'machine_count' => 0,
        ]);
        $productionBranch = $this->createBranch([
            'name' => 'Production Branch',
            'code' => 'PROD9',
            'branch_type' => 'full_service',
            'machine_count' => 3,
        ]);
        $employee = AttendanceEmployee::query()->create([
            'branch_id' => $productionBranch->id,
            'first_name' => 'Prod',
            'last_name' => 'Staff',
            'username' => 'prodstaff',
            'password' => bcrypt('secret123'),
            'status' => 'active',
        ]);
        $customer = $this->createCustomer($dropoffBranch);
        $order = $this->createJobOrder($dropoffBranch, $customer, 'JO-KIOSK-SCAN');
        $order->update([
            'processing_branch_id' => $productionBranch->id,
            'current_branch_id' => $dropoffBranch->id,
            'release_branch_id' => $dropoffBranch->id,
        ]);

        $this->withSession(['attendance_employee_id' => $employee->id])
            ->postJson(route('attendance.job-orders.scan'), [
                'qr_text' => route('admin.cycles.scan', $order),
            ])
            ->assertOk()
            ->assertJsonPath('job_order_number', 'JO-KIOSK-SCAN')
            ->assertJsonPath('processing_branch', 'Production Branch');

        $this->assertDatabaseHas('job_orders', [
            'id' => $order->id,
            'processing_branch_id' => $productionBranch->id,
            'current_branch_id' => $productionBranch->id,
            'release_branch_id' => $productionBranch->id,
        ]);
        $this->assertNotNull($order->fresh()->production_accepted_at);
    }

    public function test_pickup_dropoff_inventory_deducts_from_production_only_after_scan_acceptance(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $dropoffBranch = $this->createBranch([
            'name' => 'Pickup Branch',
            'code' => 'DROP10',
            'branch_type' => 'pickup_dropoff',
            'machine_count' => 0,
        ]);
        $productionBranch = $this->createBranch([
            'name' => 'Production Branch',
            'code' => 'PROD10',
            'branch_type' => 'full_service',
            'machine_count' => 3,
        ]);
        $pickupInventory = Inventory::query()->create([
            'branch_id' => $dropoffBranch->id,
            'name' => 'Detergent',
            'sku' => 'DET-001',
            'unit' => 'ml',
            'quantity' => 100,
            'reorder_level' => 10,
            'is_active' => true,
        ]);
        $productionInventory = Inventory::query()->create([
            'branch_id' => $productionBranch->id,
            'name' => 'Detergent',
            'sku' => 'DET-001',
            'unit' => 'ml',
            'quantity' => 100,
            'reorder_level' => 10,
            'is_active' => true,
        ]);
        $service = \App\Models\LaundryService::query()->create([
            'branch_id' => $dropoffBranch->id,
            'name' => 'Wash',
            'pricing_type' => 'kilo',
            'price' => 100,
            'is_active' => true,
        ]);
        \App\Models\ServiceInventoryUsage::query()->create([
            'laundry_service_id' => $service->id,
            'inventory_id' => $pickupInventory->id,
            'quantity' => 5,
        ]);
        $customer = $this->createCustomer($dropoffBranch);
        $manager = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $dropoffBranch->id,
            'access' => ['job_orders'],
        ]);
        $productionUser = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $productionBranch->id,
            'access' => ['cycles'],
        ]);

        $this->actingAs($manager)
            ->post(route('admin.job-orders.store'), [
                'branch_id' => $dropoffBranch->id,
                'processing_branch_id' => $productionBranch->id,
                'customer_id' => $customer->id,
                'items' => [[
                    'laundry_service_id' => $service->id,
                    'description' => $service->name,
                    'quantity' => 2,
                    'unit_price' => 100,
                ]],
                'discount' => 0,
                'paid_amount' => 0,
                'transaction_type' => 'walk_in',
            ])
            ->assertRedirect(route('admin.job-orders.index'));

        $order = JobOrder::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('100.00', $pickupInventory->fresh()->quantity);
        $this->assertSame('100.00', $productionInventory->fresh()->quantity);
        $this->assertNull($order->inventory_deducted_at);

        $this->actingAs($productionUser)
            ->get(route('admin.cycles.scan', $order))
            ->assertRedirect(route('admin.cycles.index', ['search' => $order->job_order_number]));

        $this->assertSame('100.00', $pickupInventory->fresh()->quantity);
        $this->assertSame('90.00', $productionInventory->fresh()->quantity);
        $this->assertNotNull($order->fresh()->inventory_deducted_at);
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

    private function createBranch(array $overrides = []): Branch
    {
        return Branch::query()->create(array_merge([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'address' => 'Manila',
            'contact_number' => '09171234567',
            'is_active' => true,
        ], $overrides));
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

    private function createJobOrder(Branch $branch, Customer $customer, string $jobOrderNumber = 'JO-TEST-001'): JobOrder
    {
        return JobOrder::query()->create([
            'branch_id' => $branch->id,
            'processing_branch_id' => $branch->id,
            'current_branch_id' => $branch->id,
            'release_branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'job_order_number' => $jobOrderNumber,
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
