<?php

namespace App\Support;

class Menu
{
    public static function items(): array
    {
        return [
            'dashboard' => ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'dashboard'],
            'branches' => ['label' => 'Branches', 'route' => 'admin.branches.index', 'icon' => 'branches'],
            'users' => ['label' => 'Users', 'route' => 'admin.users.index', 'icon' => 'users'],
            'customers' => ['label' => 'Customers', 'route' => 'admin.customers.index', 'icon' => 'customers'],
            'services' => ['label' => 'Laundry Services', 'route' => 'admin.services.index', 'icon' => 'services'],
            'job_orders' => ['label' => 'Job Orders', 'route' => 'admin.job-orders.index', 'icon' => 'jobOrders'],
            'cycles' => ['label' => 'Cycle Monitoring', 'route' => 'admin.cycles.index', 'icon' => 'cycles'],
            'employees' => ['label' => 'Employees', 'route' => 'admin.employees.index', 'icon' => 'employees'],
            'payments' => ['label' => 'Payments', 'route' => 'admin.payments.index', 'icon' => 'payments'],
            'receivables' => ['label' => 'Receivables', 'route' => 'admin.receivables.index', 'icon' => 'receivables'],
            'inventory' => ['label' => 'Inventory', 'route' => 'admin.inventory.index', 'icon' => 'inventory'],
            'attendance' => ['label' => 'Attendance', 'route' => 'admin.attendance.index', 'icon' => 'attendance'],
            'reports' => ['label' => 'Reports', 'route' => 'admin.reports.index', 'icon' => 'reports'],
            'sms_logs' => ['label' => 'SMS Logs', 'route' => 'admin.sms-logs.index', 'icon' => 'smsLogs'],
            'settings' => ['label' => 'System Settings', 'route' => 'admin.settings.edit', 'icon' => 'settings'],
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::items());
    }
}
