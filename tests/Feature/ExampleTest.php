<?php

namespace Tests\Feature;

use App\Models\Branch;
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

    public function test_time_clock_issues_random_liveness_challenge(): void
    {
        $response = $this->getJson(route('attendance.challenge'));

        $response
            ->assertOk()
            ->assertJsonStructure(['nonce', 'sequence', 'expires_in'])
            ->assertJsonCount(3, 'sequence');
    }

    public function test_public_attendance_requires_server_liveness_challenge(): void
    {
        $response = $this->postJson(route('attendance.public-time-in'), [
            'descriptor' => array_fill(0, 128, 0.1),
            'latitude' => 14.5995124,
            'longitude' => 120.9842195,
            'face_image' => 'data:image/jpeg;base64,'.base64_encode('fake'),
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['challenge_nonce', 'challenge_result']);
    }

    public function test_public_attendance_only_matches_employees_in_nearby_branch(): void
    {
        $branchA = Branch::query()->create([
            'name' => 'Branch A',
            'code' => 'A',
            'latitude' => 14.5995124,
            'longitude' => 120.9842195,
            'attendance_radius_meters' => 100,
            'is_active' => true,
        ]);

        $branchB = Branch::query()->create([
            'name' => 'Branch B',
            'code' => 'B',
            'latitude' => 14.6095124,
            'longitude' => 120.9942195,
            'attendance_radius_meters' => 100,
            'is_active' => true,
        ]);

        User::factory()->create([
            'branch_id' => $branchA->id,
            'face_descriptors' => [array_fill(0, 128, 0.1)],
        ]);

        $challenge = $this->getJson(route('attendance.challenge'))->json();

        $response = $this->postJson(route('attendance.public-time-in'), [
            'descriptor' => array_fill(0, 128, 0.1),
            'latitude' => $branchB->latitude,
            'longitude' => $branchB->longitude,
            'face_image' => 'data:image/jpeg;base64,'.base64_encode('fake'),
            'challenge_nonce' => $challenge['nonce'],
            'challenge_result' => $challenge['sequence'],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['face']);
    }
}
