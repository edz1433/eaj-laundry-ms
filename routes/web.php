<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\LaundryServiceController;
use App\Http\Controllers\Admin\JobOrderController;
use App\Http\Controllers\Admin\CycleController;
use App\Http\Controllers\Admin\ReceivableController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SmsLogController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\SystemSettingController;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLogin'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.submit');
});

Route::post('/logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::get('/time-clock', [AttendanceController::class, 'kiosk'])->name('attendance.kiosk');
Route::get('/time-clock/challenge', [AttendanceController::class, 'challenge'])->name('attendance.challenge');
Route::post('/time-clock/time-in', [AttendanceController::class, 'publicTimeIn'])->name('attendance.public-time-in');
Route::post('/time-clock/time-out', [AttendanceController::class, 'publicTimeOut'])->name('attendance.public-time-out');

Route::middleware(['auth', 'settings.completed'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/data', [DashboardController::class, 'data'])->name('dashboard.data');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::resource('branches', BranchController::class)->only(['index', 'store', 'update', 'destroy'])->middleware('menu.access:branches');
        Route::resource('users', UserController::class)->only(['index', 'store', 'update', 'destroy'])->middleware('menu.access:users');
        Route::resource('customers', CustomerController::class)->only(['index', 'store', 'update', 'destroy'])->middleware('menu.access:customers');
        Route::resource('services', LaundryServiceController::class)->only(['index', 'store', 'update', 'destroy'])->middleware('menu.access:services');
        Route::middleware('menu.access:job_orders')->group(function () {
            Route::get('/job-orders', [JobOrderController::class, 'index'])->name('job-orders.index');
            Route::get('/job-orders/create', [JobOrderController::class, 'create'])->name('job-orders.create');
            Route::post('/job-orders', [JobOrderController::class, 'store'])->name('job-orders.store');
            Route::get('/job-orders/{jobOrder}', [JobOrderController::class, 'show'])->name('job-orders.show');
            Route::get('/job-orders/{jobOrder}/receipt', [JobOrderController::class, 'receipt'])->name('job-orders.receipt');
            Route::patch('/job-orders/{jobOrder}/status', [JobOrderController::class, 'updateStatus'])->name('job-orders.status');
            Route::patch('/job-orders/{jobOrder}/cancel', [JobOrderController::class, 'cancel'])->name('job-orders.cancel');
        });
        Route::get('/payments', [PaymentController::class, 'index'])->middleware('menu.access:payments')->name('payments.index');
        Route::middleware('menu.access:inventory')->group(function () {
            Route::resource('inventory', InventoryController::class)->only(['index', 'store', 'update', 'destroy']);
            Route::post('/inventory/{inventory}/movements', [InventoryController::class, 'storeMovement'])->name('inventory.movements.store');
            Route::post('/inventory/suppliers', [InventoryController::class, 'storeSupplier'])->name('inventory.suppliers.store');
        });
        Route::middleware('menu.access:receivables')->group(function () {
            Route::get('/receivables', [ReceivableController::class, 'index'])->name('receivables.index');
            Route::post('/receivables/job-orders/{jobOrder}/payments', [ReceivableController::class, 'storePayment'])->name('receivables.payments.store');
        });
        Route::middleware('menu.access:cycles')->group(function () {
            Route::get('/cycles', [CycleController::class, 'index'])->name('cycles.index');
            Route::patch('/cycles/job-orders/{jobOrder}/status', [CycleController::class, 'updateStatus'])->name('cycles.status');
            Route::post('/cycles/job-orders/{jobOrder}', [CycleController::class, 'storeCycle'])->name('cycles.store');
            Route::patch('/cycles/{cycle}/end', [CycleController::class, 'endCycle'])->name('cycles.end');
        });
        Route::middleware('menu.access:employees')->group(function () {
            Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
            Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
        });
        Route::middleware('menu.access:attendance')->group(function () {
            Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
            Route::post('/attendance/time-in', [AttendanceController::class, 'timeIn'])->name('attendance.time-in');
            Route::post('/attendance/time-out', [AttendanceController::class, 'timeOut'])->name('attendance.time-out');
        });

        Route::get('/reports', [ReportController::class, 'index'])
            ->middleware('menu.access:reports')
            ->name('reports.index');
        Route::get('/reports/pdf', [ReportController::class, 'pdf'])
            ->middleware('menu.access:reports')
            ->name('reports.pdf');

        Route::get('/sms-logs', [SmsLogController::class, 'index'])
            ->middleware('menu.access:sms_logs')
            ->name('sms-logs.index');

        Route::get('/settings', [SystemSettingController::class, 'edit'])
            ->middleware('menu.access:settings')
            ->name('settings.edit');

        Route::put('/settings', [SystemSettingController::class, 'update'])
            ->middleware('menu.access:settings')
            ->name('settings.update');
    });
});
