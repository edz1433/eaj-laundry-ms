<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $fillable = ['branch_id', 'user_id', 'work_date', 'time_in', 'time_out', 'status'];

    protected $casts = ['work_date' => 'date', 'time_in' => 'datetime', 'time_out' => 'datetime'];
}
