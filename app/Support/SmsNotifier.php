<?php

namespace App\Support;

use App\Models\JobOrder;
use App\Models\SmsLog;
use App\Models\SystemSetting;

class SmsNotifier
{
    public static function jobOrderStatus(JobOrder $order): void
    {
        $settings = SystemSetting::current();
        $customer = $order->customer;

        if (! $settings->sms_enabled || ! $customer?->phone) {
            return;
        }

        $message = match ($order->status) {
            'ready_for_pickup' => "Hi {$customer->name}, your laundry {$order->job_order_number} is ready for pickup.",
            'completed' => "Hi {$customer->name}, your laundry {$order->job_order_number} has been completed. Thank you.",
            default => null,
        };

        if (! $message) {
            return;
        }

        SmsLog::create([
            'branch_id' => $order->branch_id,
            'customer_id' => $order->customer_id,
            'recipient' => $customer->phone,
            'message' => $message,
            'status' => 'queued',
            'response' => 'SMS provider not connected yet. Queued for optional integration.',
        ]);
    }
}
