<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchExpense;
use App\Models\AccountsPayable;
use App\Models\AccountsPayablePayment;
use App\Models\AttendanceEmployee;
use App\Models\Customer;
use App\Models\DailyTask;
use App\Models\DailyTaskCompletion;
use App\Models\EmployeeAttendanceRecord;
use App\Models\JobOrder;
use App\Models\LaundryService;
use App\Models\MoneyMovement;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\ZReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

    public function test_time_clock_redirects_to_login_without_employee_session(): void
    {
        $response = $this->get(route('attendance.kiosk'));

        $response->assertRedirect(route('attendance.login'));
    }

    public function test_employee_login_goes_to_attendance_kiosk(): void
    {
        $branch = Branch::query()->create([
            'name' => 'Branch A',
            'code' => 'A',
            'is_active' => true,
        ]);

        AttendanceEmployee::query()->create([
            'branch_id' => $branch->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'username' => 'juan',
            'password' => Hash::make('secret123'),
            'status' => 'active',
        ]);

        $response = $this->post(route('attendance.login.submit'), [
            'login' => 'juan',
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('attendance.kiosk'));
        $this->assertNotNull(session('attendance_employee_id'));
    }

    public function test_failed_employee_login_returns_to_employee_login_page(): void
    {
        $response = $this
            ->from(route('login'))
            ->post(route('attendance.login.submit'), [
                'login' => 'missing-employee',
                'password' => 'wrong-password',
            ]);

        $response
            ->assertRedirect(route('attendance.login'))
            ->assertSessionHasErrors('login');
    }

    public function test_employee_login_page_is_available_while_system_user_is_authenticated(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get(route('attendance.login'))
            ->assertOk()
            ->assertSee('Employee Time Clock');
    }

    public function test_employee_logout_returns_to_employee_login_and_clears_session(): void
    {
        $this->withSession(['attendance_employee_id' => 123])
            ->post(route('attendance.logout'))
            ->assertRedirect(route('attendance.login'));

        $this->assertNull(session('attendance_employee_id'));
    }

    public function test_expired_employee_session_redirects_kiosk_actions_to_employee_login(): void
    {
        $task = DailyTask::query()->create([
            'name' => 'Closing task',
            'is_active' => true,
        ]);

        $this->post(route('attendance.daily-tasks.complete', $task))
            ->assertRedirect(route('attendance.login'))
            ->assertSessionHasErrors('login');

        $this->postJson(route('attendance.public-time-in'), [])
            ->assertUnauthorized()
            ->assertJsonPath('redirect', route('attendance.login'));
    }

    public function test_employee_connectivity_check_requires_session_and_returns_fresh_status(): void
    {
        $this->getJson(route('attendance.connectivity'))
            ->assertUnauthorized()
            ->assertJsonPath('redirect', route('attendance.login'));

        $branch = Branch::query()->create([
            'name' => 'Branch A',
            'code' => 'A-CONNECT',
            'is_active' => true,
        ]);
        $employee = AttendanceEmployee::query()->create([
            'branch_id' => $branch->id,
            'first_name' => 'Online',
            'last_name' => 'Employee',
            'username' => 'online-employee',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        $response = $this->withSession(['attendance_employee_id' => $employee->id])
            ->getJson(route('attendance.connectivity'))
            ->assertOk()
            ->assertJsonPath('online', true);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_attendance_kiosk_uses_uploaded_business_logo(): void
    {
        Storage::fake('public');

        $logoPath = 'settings/brand.png';
        Storage::disk('public')->put($logoPath, 'fake-logo');
        SystemSetting::query()->create([
            'business_name' => 'EAJ Laundry',
            'contact_number' => '09171234567',
            'business_address' => 'Manila',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'primary_color' => '#2E7D32',
            'business_logo' => $logoPath,
            'is_completed' => true,
        ]);

        $branch = Branch::query()->create([
            'name' => 'Branch A',
            'code' => 'A',
            'is_active' => true,
        ]);
        $employee = AttendanceEmployee::query()->create([
            'branch_id' => $branch->id,
            'first_name' => 'Logo',
            'last_name' => 'Tester',
            'username' => 'logo-tester',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        $this
            ->withSession(['attendance_employee_id' => $employee->id])
            ->get(route('attendance.kiosk'))
            ->assertOk()
            ->assertSee(asset('storage/'.$logoPath), false)
            ->assertDontSee(asset('logo.png'), false);
    }

    public function test_attendance_kiosk_temporarily_hides_qr_receiving_menu(): void
    {
        $branch = Branch::query()->create(['name' => 'Branch A', 'code' => 'A', 'is_active' => true]);
        $employee = AttendanceEmployee::query()->create([
            'branch_id' => $branch->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'username' => 'juan-no-qr-menu',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        $this
            ->withSession(['attendance_employee_id' => $employee->id])
            ->get(route('attendance.kiosk'))
            ->assertOk()
            ->assertDontSee('Receive Laundry for Production')
            ->assertDontSee('Scan Job Order')
            ->assertDontSee('switchTab(\'scan\')', false)
            ->assertSee('grid-cols-2', false);
    }

    public function test_system_login_does_not_accept_attendance_employee_credentials(): void
    {
        $branch = Branch::query()->create([
            'name' => 'Branch A',
            'code' => 'A',
            'is_active' => true,
        ]);

        AttendanceEmployee::query()->create([
            'branch_id' => $branch->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'username' => 'juan',
            'password' => Hash::make('secret123'),
            'status' => 'active',
        ]);

        $response = $this->post(route('login.submit'), [
            'login' => 'juan',
            'password' => 'secret123',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertNull(session('attendance_employee_id'));
    }

    public function test_attendance_login_does_not_accept_system_user_credentials(): void
    {
        $user = User::factory()->create([
            'username' => 'system-user',
            'password' => Hash::make('secret123'),
            'role' => 'cashier',
        ]);

        $response = $this->post(route('attendance.login.submit'), [
            'login' => $user->username,
            'password' => 'secret123',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertNull(session('attendance_employee_id'));
    }

    public function test_admin_can_create_attendance_employee_with_default_password(): void
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

        $admin = User::factory()->create(['role' => 'super_admin']);
        $branch = Branch::query()->create([
            'name' => 'Branch A',
            'code' => 'A',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($admin)
            ->post(route('admin.employees.store'), [
                'branch_id' => $branch->id,
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'phone' => '',
                'username' => 'maria',
                'password' => '',
                'is_active' => '1',
            ]);

        $response->assertRedirect();

        $employee = AttendanceEmployee::where('username', 'maria')->firstOrFail();
        $this->assertTrue(Hash::check('password123', $employee->password));
    }

    public function test_attendance_module_displays_active_employee_even_without_logs(): void
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

        $branch = Branch::query()->create(['name' => 'Branch A', 'code' => 'A', 'is_active' => true]);
        $manager = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $branch->id,
            'access' => ['attendance'],
        ]);

        AttendanceEmployee::query()->create([
            'branch_id' => $branch->id,
            'first_name' => 'Ana',
            'last_name' => 'No Logs',
            'username' => 'ana-no-logs',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        $this
            ->actingAs($manager)
            ->get(route('admin.attendance.index', ['date' => today()->toDateString()]))
            ->assertOk()
            ->assertSee('Ana No Logs')
            ->assertSee('ana-no-logs')
            ->assertSee('No time-in proof')
            ->assertSee('No time-out proof');
    }

    public function test_attendance_module_is_filtered_log_list_without_manual_time_clock(): void
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

        $branch = Branch::query()->create(['name' => 'Branch A', 'code' => 'A', 'is_active' => true]);
        $otherBranch = Branch::query()->create(['name' => 'Branch B', 'code' => 'B', 'is_active' => true]);
        $manager = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $branch->id,
            'access' => ['attendance'],
        ]);
        $employee = AttendanceEmployee::query()->create([
            'branch_id' => $branch->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'username' => 'juan-logs',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $otherEmployee = AttendanceEmployee::query()->create([
            'branch_id' => $otherBranch->id,
            'first_name' => 'Pedro',
            'last_name' => 'Santos',
            'username' => 'pedro-logs',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $sameBranchEmployee = AttendanceEmployee::query()->create([
            'branch_id' => $branch->id,
            'first_name' => 'Maria',
            'last_name' => 'Reyes',
            'username' => 'maria-logs',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        EmployeeAttendanceRecord::query()->create([
            'attendance_employee_id' => $employee->id,
            'branch_id' => $branch->id,
            'work_date' => today()->toDateString(),
            'clock_in' => ['08:00:00'],
        ]);
        EmployeeAttendanceRecord::query()->create([
            'attendance_employee_id' => $otherEmployee->id,
            'branch_id' => $otherBranch->id,
            'work_date' => today()->toDateString(),
            'clock_in' => ['09:00:00'],
        ]);
        EmployeeAttendanceRecord::query()->create([
            'attendance_employee_id' => $sameBranchEmployee->id,
            'branch_id' => $branch->id,
            'work_date' => today()->toDateString(),
            'clock_in' => ['10:00:00'],
        ]);

        $this
            ->actingAs($manager)
            ->get(route('admin.attendance.index', [
                'branch_id' => $otherBranch->id,
                'employee_id' => $employee->id,
            ]))
            ->assertOk()
            ->assertSee(today()->toDateString())
            ->assertSee('Juan Dela Cruz')
            ->assertDontSee('maria-logs')
            ->assertDontSee('Pedro Santos')
            ->assertSee('name="employee_id"', false)
            ->assertDontSee('Time Clock')
            ->assertDontSee('Select an employee from the list.')
            ->assertDontSee('name="branch_id"', false);
    }

    public function test_attendance_module_shows_attached_proof_buttons(): void
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

        $branch = Branch::query()->create(['name' => 'Branch A', 'code' => 'A', 'is_active' => true]);
        $manager = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $branch->id,
            'access' => ['attendance'],
        ]);
        $employee = AttendanceEmployee::query()->create([
            'branch_id' => $branch->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'username' => 'juan-proof',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        EmployeeAttendanceRecord::query()->create([
            'attendance_employee_id' => $employee->id,
            'branch_id' => $branch->id,
            'work_date' => today()->toDateString(),
            'clock_in' => ['08:00:00'],
            'clock_out' => ['17:00:00'],
            'clock_in_photos' => ['attendance-proofs/in.jpg'],
            'clock_out_photos' => ['attendance-proofs/out.jpg'],
            'clock_in_locations' => [['latitude' => 14.1, 'longitude' => 121.1]],
            'clock_out_locations' => [['latitude' => 14.1, 'longitude' => 121.1]],
        ]);

        $this
            ->actingAs($manager)
            ->get(route('admin.attendance.index', ['date' => today()->toDateString()]))
            ->assertOk()
            ->assertSee('Time In Proof')
            ->assertSee('Time Out Proof')
            ->assertSee('Time in proof 1', false)
            ->assertSee('Time out proof 1', false)
            ->assertSee('storage\/attendance-proofs\/in.jpg', false)
            ->assertSee('storage\/attendance-proofs\/out.jpg', false);
    }

    public function test_employee_kiosk_can_upload_daily_task_proof_for_assigned_branch(): void
    {
        Storage::fake('public');

        $branch = Branch::query()->create(['name' => 'Branch A', 'code' => 'A', 'is_active' => true]);
        $employee = AttendanceEmployee::query()->create([
            'branch_id' => $branch->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'username' => 'juan-task',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $task = DailyTask::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Clean delivery shelf',
            'requires_photo' => true,
            'is_active' => true,
        ]);

        $this
            ->withSession(['attendance_employee_id' => $employee->id])
            ->get(route('attendance.kiosk'))
            ->assertOk()
            ->assertSee('Clean delivery shelf');

        $this
            ->withSession(['attendance_employee_id' => $employee->id])
            ->post(route('attendance.daily-tasks.complete', $task), [
                'photo' => UploadedFile::fake()->createWithContent('proof.jpg', $this->tinyJpeg()),
                'remarks' => 'Done after closing',
            ])
            ->assertRedirect();

        $completion = DailyTaskCompletion::firstOrFail();
        $this->assertSame($employee->id, $completion->completed_by_employee_id);
        $this->assertSame($branch->id, $completion->branch_id);
        Storage::disk('public')->assertExists($completion->photo_path);
    }

    public function test_branch_without_configured_daily_tasks_shows_empty_checklist(): void
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

        $branch = Branch::query()->create(['name' => 'Branch A', 'code' => 'A', 'is_active' => true]);
        $manager = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $branch->id,
            'access' => ['daily_tasks'],
        ]);
        $employee = AttendanceEmployee::query()->create([
            'branch_id' => $branch->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'username' => 'juan-empty-task',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        $this
            ->actingAs($manager)
            ->get(route('admin.daily-tasks.index'))
            ->assertOk()
            ->assertSee('No end-of-day tasks configured for this branch.')
            ->assertDontSee('Machine tub cleaning')
            ->assertDontSee('Store cleaning');

        $this
            ->withSession(['attendance_employee_id' => $employee->id])
            ->get(route('attendance.kiosk'))
            ->assertOk()
            ->assertSee('No tasks configured.')
            ->assertDontSee('Machine tub cleaning')
            ->assertDontSee('Store cleaning');

        $this->assertSame(0, DailyTask::query()->count());
    }

    public function test_completed_previous_job_order_can_be_filtered_and_edited(): void
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

        $admin = User::factory()->create(['role' => 'super_admin']);
        $branch = Branch::query()->create([
            'name' => 'Branch A',
            'code' => 'A',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Anna Santos',
            'billing_type' => 'regular',
            'unpaid_limit' => 0,
            'is_active' => true,
        ]);
        $service = LaundryService::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Wash Dry Fold',
            'pricing_type' => 'kilo',
            'price' => 100,
            'is_active' => true,
        ]);

        $order = JobOrder::query()->create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'created_by' => $admin->id,
            'job_order_number' => 'JO-A-20260606-0001',
            'status' => 'completed',
            'transaction_type' => 'walk_in',
            'subtotal' => 100,
            'discount' => 0,
            'tax' => 0,
            'total' => 100,
            'paid_amount' => 0,
            'balance' => 100,
            'completed_at' => now(),
        ]);
        $order->items()->create([
            'laundry_service_id' => $service->id,
            'description' => $service->name,
            'quantity' => 1,
            'unit_price' => 100,
            'total' => 100,
        ]);

        $this
            ->actingAs($admin)
            ->get(route('admin.job-orders.index', ['status' => 'completed']))
            ->assertOk()
            ->assertSee('JO-A-20260606-0001')
            ->assertSee(route('admin.job-orders.edit', $order), false);

        $this
            ->actingAs($admin)
            ->put(route('admin.job-orders.update', $order), [
                'customer_id' => $customer->id,
                'status' => 'ready_for_pickup',
                'transaction_type' => 'delivery',
                'notes' => 'Updated previous order',
                'discount' => '10',
                'items' => [
                    [
                        'laundry_service_id' => $service->id,
                        'description' => $service->name,
                        'quantity' => '2',
                        'unit_price' => '100',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.job-orders.show', $order));

        $order->refresh();
        $this->assertSame('ready_for_pickup', $order->status);
        $this->assertSame('delivery', $order->transaction_type);
        $this->assertSame('190.00', $order->total);
        $this->assertSame('190.00', $order->balance);
        $this->assertNull($order->completed_at);
    }

    public function test_public_attendance_requires_employee_session(): void
    {
        $response = $this->postJson(route('attendance.public-time-in'), [
            'latitude' => 14.5995124,
            'longitude' => 120.9842195,
            'face_image' => 'data:image/jpeg;base64,'.base64_encode('fake'),
        ]);

        $response
            ->assertUnauthorized()
            ->assertJsonPath('redirect', route('attendance.login'));
    }

    public function test_public_attendance_allows_employee_to_clock_in_at_any_configured_branch(): void
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

        $employee = AttendanceEmployee::query()->create([
            'branch_id' => $branchA->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'username' => 'branch-a-staff',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        $response = $this
            ->withSession(['attendance_employee_id' => $employee->id])
            ->postJson(route('attendance.public-time-in'), [
                'latitude' => $branchB->latitude,
                'longitude' => $branchB->longitude,
                'face_image' => 'data:image/jpeg;base64,'.base64_encode('fake'),
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('employee', $employee->name)
            ->assertJsonPath('branch', $branchB->name);

        $record = \App\Models\EmployeeAttendanceRecord::query()->firstOrFail();
        $this->assertSame($employee->id, $record->attendance_employee_id);
        $this->assertSame($branchB->id, $record->branch_id);

        $this
            ->withSession(['attendance_employee_id' => $employee->id])
            ->postJson(route('attendance.public-time-out'), [
                'latitude' => $branchA->latitude,
                'longitude' => $branchA->longitude,
                'face_image' => 'data:image/jpeg;base64,'.base64_encode('fake-out'),
            ])
            ->assertOk()
            ->assertJsonPath('branch', $branchA->name);

        $record->refresh();
        $this->assertSame($branchB->id, $record->branch_id);
        $this->assertCount(1, $record->clock_out);
    }

    public function test_public_attendance_accepts_employee_session_and_allows_multiple_clock_ins(): void
    {
        Storage::fake('public');

        $branch = Branch::query()->create([
            'name' => 'Branch A',
            'code' => 'A',
            'latitude' => 14.5995124,
            'longitude' => 120.9842195,
            'attendance_radius_meters' => 100,
            'is_active' => true,
        ]);

        $employee = AttendanceEmployee::query()->create([
            'branch_id' => $branch->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'username' => 'branch-a-staff',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        $payload = [
            'latitude' => $branch->latitude,
            'longitude' => $branch->longitude,
            'face_image' => 'data:image/jpeg;base64,'.base64_encode('fake'),
        ];

        $response = $this
            ->withSession(['attendance_employee_id' => $employee->id])
            ->postJson(route('attendance.public-time-in'), $payload);

        $response
            ->assertOk()
            ->assertJsonPath('employee', $employee->name)
            ->assertJsonPath('branch', $branch->name);

        $this
            ->withSession(['attendance_employee_id' => $employee->id])
            ->postJson(route('attendance.public-time-in'), $payload)
            ->assertOk();

        $this->assertDatabaseHas('employee_attendance_records', [
            'attendance_employee_id' => $employee->id,
            'branch_id' => $branch->id,
        ]);

        $record = \App\Models\EmployeeAttendanceRecord::first();
        $this->assertCount(2, $record->clock_in);
        $this->assertCount(2, $record->clock_in_photos);
        Storage::disk('public')->assertExists($record->clock_in_photos[0]);
    }

    public function test_z_reading_saves_cash_count_balance_and_generates_pdf(): void
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

        $admin = User::factory()->create(['role' => 'super_admin']);
        $branch = Branch::query()->create(['name' => 'Branch A', 'code' => 'A', 'is_active' => true]);
        $customer = Customer::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Anna Santos',
            'billing_type' => 'regular',
            'unpaid_limit' => 0,
            'is_active' => true,
        ]);
        $order = JobOrder::query()->create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'created_by' => $admin->id,
            'job_order_number' => 'JO-A-20260606-0001',
            'status' => 'completed',
            'transaction_type' => 'walk_in',
            'subtotal' => 800,
            'discount' => 0,
            'tax' => 0,
            'total' => 800,
            'paid_amount' => 800,
            'balance' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Payment::query()->create([
            'branch_id' => $branch->id,
            'job_order_id' => $order->id,
            'customer_id' => $customer->id,
            'received_by' => $admin->id,
            'payment_number' => 'PAY-ZR-0001',
            'payment_type' => 'cash',
            'amount' => 500,
            'paid_at' => now(),
        ]);
        Payment::query()->create([
            'branch_id' => $branch->id,
            'job_order_id' => $order->id,
            'customer_id' => $customer->id,
            'received_by' => $admin->id,
            'payment_number' => 'PAY-ZR-0002',
            'payment_type' => 'gcash',
            'reference_no' => 'GCASH-123',
            'amount' => 300,
            'paid_at' => now(),
        ]);
        Payment::query()->create([
            'branch_id' => $branch->id,
            'job_order_id' => $order->id,
            'customer_id' => $customer->id,
            'received_by' => $admin->id,
            'payment_number' => 'PAY-ZR-0003',
            'payment_type' => 'bank',
            'reference_no' => 'BANK-123',
            'amount' => 50,
            'paid_at' => now(),
        ]);
        BranchExpense::query()->create([
            'branch_id' => $branch->id,
            'category' => 'Supplies',
            'expense_type' => 'regular',
            'title' => 'Detergent',
            'amount' => 100,
            'expense_date' => today()->toDateString(),
            'payment_method' => 'cash',
            'paid_from' => 'store_cash',
            'created_by' => $admin->id,
        ]);
        $gcashPayable = AccountsPayable::query()->create([
            'branch_id' => $branch->id,
            'created_by' => $admin->id,
            'payable_number' => 'AP-GCASH-ZR',
            'creditor_name' => 'Owner',
            'source_type' => 'owner_funding',
            'funding_method' => 'gcash',
            'description' => 'GCash working fund',
            'original_amount' => 200,
            'paid_amount' => 50,
            'balance' => 150,
            'status' => 'partial',
            'funded_at' => today()->toDateString(),
        ]);
        $bankPayable = AccountsPayable::query()->create([
            'branch_id' => $branch->id,
            'created_by' => $admin->id,
            'payable_number' => 'AP-BANK-ZR',
            'creditor_name' => 'Owner',
            'source_type' => 'owner_funding',
            'funding_method' => 'bank',
            'description' => 'Bank working fund',
            'original_amount' => 400,
            'paid_amount' => 75,
            'balance' => 325,
            'status' => 'partial',
            'funded_at' => today()->toDateString(),
        ]);
        AccountsPayablePayment::query()->create([
            'accounts_payable_id' => $gcashPayable->id,
            'branch_id' => $branch->id,
            'recorded_by' => $admin->id,
            'payment_number' => 'APP-GCASH-ZR',
            'payment_date' => today()->toDateString(),
            'payment_method' => 'gcash',
            'amount' => 50,
        ]);
        AccountsPayablePayment::query()->create([
            'accounts_payable_id' => $bankPayable->id,
            'branch_id' => $branch->id,
            'recorded_by' => $admin->id,
            'payment_number' => 'APP-BANK-ZR',
            'payment_date' => today()->toDateString(),
            'payment_method' => 'bank',
            'amount' => 75,
        ]);
        BranchExpense::query()->create([
            'branch_id' => $branch->id,
            'category' => 'Utilities',
            'expense_type' => 'regular',
            'title' => 'Electric bill',
            'amount' => 250,
            'expense_date' => today()->toDateString(),
            'payment_method' => 'gcash',
            'paid_from' => 'owner',
            'created_by' => $admin->id,
        ]);

        $this
            ->actingAs($admin)
            ->get(route('admin.z-readings.index', [
                'branch_id' => $branch->id,
                'business_date' => today()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Create Z Reading')
            ->assertDontSee('Expected Cash Drawer');

        $this
            ->actingAs($admin)
            ->get(route('admin.z-readings.create', [
                'branch_id' => $branch->id,
                'business_date' => today()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Create Z Reading')
            ->assertSee('Expected Cash Drawer');

        $this
            ->actingAs($admin)
            ->get(route('admin.petty-cash.index', [
                'branch_id' => $branch->id,
                'movement_date' => today()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Petty Cash')
            ->assertSee('New Petty Cash Voucher');

        $this
            ->actingAs($admin)
            ->post(route('admin.petty-cash.store'), [
                'branch_id' => $branch->id,
                'movement_date' => today()->toDateString(),
                'type' => 'deposit',
                'amount' => '50.00',
                'reference_no' => 'ADD-001',
                'description' => 'Added change fund',
            ])
            ->assertRedirect(route('admin.petty-cash.index', [
                'branch_id' => $branch->id,
                'movement_date' => today()->toDateString(),
            ]));

        $this
            ->actingAs($admin)
            ->post(route('admin.petty-cash.store'), [
                'branch_id' => $branch->id,
                'movement_date' => today()->toDateString(),
                'type' => 'withdraw',
                'amount' => '25.00',
                'reference_no' => 'REM-001',
                'description' => 'Sales remittance',
            ])
            ->assertRedirect(route('admin.petty-cash.index', [
                'branch_id' => $branch->id,
                'movement_date' => today()->toDateString(),
            ]));

        $this->assertSame(2, MoneyMovement::query()->count());

        $this
            ->actingAs($admin)
            ->post(route('admin.z-readings.store'), [
                'branch_id' => $branch->id,
                'business_date' => today()->toDateString(),
                'cash_count' => [
                    '200' => 2,
                ],
                'actual_gcash_amount' => '310.00',
                'actual_bank_amount' => '55.00',
            ])
            ->assertRedirect(route('admin.z-readings.index', [
                'branch_id' => $branch->id,
                'business_date' => today()->toDateString(),
            ]));

        $reading = ZReading::query()->firstOrFail();

        $this->assertSame('425.00', $reading->expected_cash_drawer_amount);
        $this->assertSame('400.00', $reading->actual_cash_amount);
        $this->assertSame('450.00', $reading->expected_gcash_amount);
        $this->assertSame('310.00', $reading->actual_gcash_amount);
        $this->assertSame('375.00', $reading->expected_bank_amount);
        $this->assertSame('55.00', $reading->actual_bank_amount);
        $this->assertSame('1250.00', $reading->expected_total_amount);
        $this->assertSame('765.00', $reading->actual_total_amount);
        $this->assertSame('-485.00', $reading->over_short_amount);
        $this->assertEquals(250.0, $reading->expense_breakdown['owner']);
        $this->assertEquals(50.0, $reading->expense_breakdown['money_movements']['cash_in']);
        $this->assertEquals(25.0, $reading->expense_breakdown['money_movements']['cash_out']);
        $this->assertEquals(200.0, $reading->expense_breakdown['accounts_payable']['gcash_funding']);
        $this->assertEquals(50.0, $reading->expense_breakdown['accounts_payable']['gcash_repayments']);
        $this->assertEquals(400.0, $reading->expense_breakdown['accounts_payable']['bank_funding']);
        $this->assertEquals(75.0, $reading->expense_breakdown['accounts_payable']['bank_repayments']);

        $this
            ->actingAs($admin)
            ->get(route('admin.z-readings.pdf', $reading))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    private function tinyJpeg(): string
    {
        return base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/ASP/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/ASP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Al//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/IV//2gAMAwEAAgADAAAAEP/EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EFBABAQAAAAAAAAAAAAAAAAAAARD/2gAIAQEAAT8QH//Z');
    }
}
