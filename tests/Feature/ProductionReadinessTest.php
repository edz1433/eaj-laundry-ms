<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Menu;
use App\Support\StatusBadge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_badges_have_distinct_identifiable_colors(): void
    {
        $statuses = [
            'pending',
            'washing',
            'drying',
            'folding',
            'ready_for_pickup',
            'completed',
            'cancelled',
            'unpaid',
            'paid',
            'overdue',
            'suspended',
            'queued',
            'sent',
            'failed',
        ];

        $classes = collect($statuses)
            ->mapWithKeys(fn (string $status) => [$status => StatusBadge::classes($status)]);

        $this->assertStringContainsString('amber', $classes['pending']);
        $this->assertStringContainsString('blue', $classes['washing']);
        $this->assertStringContainsString('cyan', $classes['drying']);
        $this->assertStringContainsString('purple', $classes['folding']);
        $this->assertStringContainsString('teal', $classes['ready_for_pickup']);
        $this->assertStringContainsString('green', $classes['completed']);
        $this->assertStringContainsString('red', $classes['cancelled']);
        $this->assertStringContainsString('orange', $classes['unpaid']);
        $this->assertStringContainsString('slate', $classes['suspended']);
    }

    public function test_menu_routes_are_real(): void
    {
        $missing = collect(Menu::items())
            ->pluck('route')
            ->reject(fn (string $route) => Route::has($route))
            ->all();

        $this->assertSame([], $missing);
    }

    public function test_core_admin_pages_render_for_super_admin(): void
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

        Branch::query()->create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'address' => 'Manila',
            'contact_number' => '09171234567',
            'is_active' => true,
        ]);

        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
        ]);

        foreach ([
            'dashboard',
            'admin.branches.index',
            'admin.users.index',
            'admin.customers.index',
            'admin.services.index',
            'admin.job-orders.index',
            'admin.cycles.index',
            'admin.payments.index',
            'admin.receivables.index',
            'admin.inventory.index',
            'admin.attendance.index',
            'admin.reports.index',
            'admin.sms-logs.index',
            'admin.billing.index',
            'admin.settings.edit',
        ] as $route) {
            $this->actingAs($superAdmin)
                ->get(route($route))
                ->assertOk();
        }
    }
}
