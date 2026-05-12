<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeveloperSetting extends Model
{
    protected $fillable = [
        'subscription_status',
        'trial_ends_at',
        'due_at',
        'grace_period_days',
        'maintenance_mode',
        'maintenance_message',
        'system_suspended',
        'activated_at',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'due_at' => 'datetime',
        'maintenance_mode' => 'boolean',
        'system_suspended' => 'boolean',
        'activated_at' => 'datetime',
    ];

    public static function current(): self
    {
        return self::firstOrCreate(['id' => 1], ['subscription_status' => 'trial']);
    }
}
