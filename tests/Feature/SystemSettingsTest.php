<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchSetting;
use App\Models\SystemSetting;
use App\Models\SystemTrialSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_manager_can_update_branch_settings_without_global_fields(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $branch = $this->createBranch();
        $manager = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $branch->id,
            'access' => ['settings'],
        ]);

        $this->actingAs($manager)
            ->put(route('admin.settings.update'), [
                'branch_name' => 'Fresh Wash Branch',
                'branch_code' => 'FWB',
                'branch_address' => '123 Clean Water Avenue',
                'branch_contact' => '09170000000',
                'attendance_radius_meters' => 150,
                'machine_count' => 6,
                'receipt_header' => 'Fresh Wash',
                'receipt_footer' => 'Thank you',
                'default_price_per_kilo' => 75,
                'default_price_per_load' => 180,
                'default_price_per_piece' => 25,
                'job_order_prefix' => 'FWB',
                'invoice_prefix' => 'INV-FWB',
                'operating_hours' => [
                    'monday' => ['open' => '08:00', 'close' => '18:00'],
                ],
            ])
            ->assertRedirect(route('admin.settings.edit', ['branch_id' => $branch->id]));

        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'name' => 'Fresh Wash Branch',
            'code' => 'FWB',
            'address' => '123 Clean Water Avenue',
            'machine_count' => 6,
        ]);

        $this->assertDatabaseHas('branch_settings', [
            'branch_id' => $branch->id,
            'receipt_header' => 'Fresh Wash',
            'job_order_prefix' => 'FWB',
        ]);
    }

    public function test_settings_rejects_too_long_branch_address_cleanly(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $branch = $this->createBranch();
        $manager = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $branch->id,
            'access' => ['settings'],
        ]);

        $this->actingAs($manager)
            ->from(route('admin.settings.edit'))
            ->put(route('admin.settings.update'), [
                'branch_name' => $branch->name,
                'branch_code' => $branch->code,
                'branch_address' => str_repeat('A', 256),
            ])
            ->assertRedirect(route('admin.settings.edit'))
            ->assertSessionHasErrors('branch_address');
    }

    public function test_login_page_uses_laundry_presentation(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Laundry operations login')
            ->assertSee('laundry-bubble')
            ->assertSee('--bubble-x')
            ->assertSee('window.appPrimaryColor')
            ->assertDontSee('particles-js');
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
            'address' => 'Old Address',
            'contact_number' => '09171234567',
            'is_active' => true,
        ]);
    }
}
