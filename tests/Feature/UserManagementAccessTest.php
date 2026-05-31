<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\SystemTrialSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cannot_see_super_admins_in_user_list(): void
    {
        $this->completeSystemSettings();

        $admin = User::factory()->create([
            'name' => 'Regular Admin',
            'role' => 'admin',
            'access' => ['users'],
        ]);

        User::factory()->create([
            'name' => 'Hidden Super Admin',
            'username' => 'hidden-super-admin',
            'email' => 'hidden-super-admin@example.com',
            'role' => 'super_admin',
        ]);

        $response = $this
            ->actingAs($admin)
            ->get(route('admin.users.index'));

        $response
            ->assertOk()
            ->assertSee('Regular Admin')
            ->assertDontSee('Hidden Super Admin')
            ->assertDontSee('super_admin');
    }

    public function test_admin_cannot_create_super_admin_user(): void
    {
        $this->completeSystemSettings();

        $admin = User::factory()->create([
            'role' => 'admin',
            'access' => ['users'],
        ]);

        $response = $this
            ->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'New Super Admin',
                'username' => 'new-super-admin',
                'email' => 'new-super-admin@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
                'role' => 'super_admin',
                'branch_id' => null,
                'status' => 'active',
                'access' => ['users'],
            ]);

        $response
            ->assertRedirect()
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', [
            'username' => 'new-super-admin',
        ]);
    }

    public function test_admin_cannot_see_or_assign_billing_access(): void
    {
        $this->completeSystemSettings();

        $admin = User::factory()->create([
            'role' => 'admin',
            'access' => ['users'],
        ]);

        $this
            ->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertDontSee('Billing')
            ->assertDontSee('admin.billing.index');

        $response = $this
            ->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Billing Admin',
                'username' => 'billing-admin',
                'email' => 'billing-admin@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
                'role' => 'admin',
                'branch_id' => null,
                'status' => 'active',
                'access' => ['dashboard', 'users', 'billing'],
            ]);

        $response->assertRedirect(route('admin.users.index'));

        $created = User::where('username', 'billing-admin')->firstOrFail();

        $this->assertContains('users', $created->access);
        $this->assertNotContains('billing', $created->access);

        $this
            ->actingAs($created)
            ->get(route('admin.billing.index'))
            ->assertForbidden();
    }

    public function test_super_admin_can_see_billing_access_checkbox_and_sidebar_link(): void
    {
        $this->completeSystemSettings();

        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
        ]);

        $this
            ->actingAs($superAdmin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Billing')
            ->assertSee('Superadmin only');

        $this
            ->actingAs($superAdmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Billing')
            ->assertSee(route('admin.billing.index'), false);
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

        SystemTrialSetting::query()->create([
            'trial_enabled' => true,
            'trial_start_date' => now()->subDay()->toDateString(),
            'trial_end_date' => now()->addDay()->toDateString(),
            'trial_status' => 'active',
            'grace_period_days' => 0,
        ]);
    }
}
