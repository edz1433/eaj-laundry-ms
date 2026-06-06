<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobOrder extends Model
{
    use SoftDeletes;

    protected $fillable = ['branch_id', 'customer_id', 'created_by', 'job_order_number', 'status', 'transaction_type', 'subtotal', 'discount', 'tax', 'total', 'paid_amount', 'balance', 'notes', 'completed_at'];

    protected $casts = ['subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2', 'paid_amount' => 'decimal:2', 'balance' => 'decimal:2', 'completed_at' => 'datetime'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function items() { return $this->hasMany(JobOrderItem::class); }
    public function payments() { return $this->hasMany(Payment::class); }
    public function cycles() { return $this->hasMany(CycleRecord::class); }
}
