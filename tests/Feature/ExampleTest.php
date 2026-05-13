<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_reports_pdf(): void
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

        $user = User::factory()->create([
            'role' => 'super_admin',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('admin.reports.pdf', [
                'date_range' => '2026-05-01 to 2026-05-14',
            ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }
}
