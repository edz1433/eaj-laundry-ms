<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = ['branch_id', 'name', 'phone', 'email', 'address', 'billing_type', 'credit_limit', 'is_active'];

    protected $casts = ['credit_limit' => 'decimal:2', 'is_active' => 'boolean'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function jobOrders() { return $this->hasMany(JobOrder::class); }
    public function payments() { return $this->hasMany(Payment::class); }
}
