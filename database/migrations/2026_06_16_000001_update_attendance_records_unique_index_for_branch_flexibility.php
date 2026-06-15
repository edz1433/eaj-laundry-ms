<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_attendance_records', function (Blueprint $table) {
            $table->dropUnique('employee_attendance_work_date_unique');
            $table->unique(['attendance_employee_id', 'branch_id', 'work_date'], 'employee_attendance_branch_work_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('employee_attendance_records', function (Blueprint $table) {
            $table->dropUnique('employee_attendance_branch_work_date_unique');
            $table->unique(['attendance_employee_id', 'work_date'], 'employee_attendance_work_date_unique');
        });
    }
};
