<?php

namespace App\Support;

use App\Models\JobOrder;
use App\Models\SmsLog;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

        $log = SmsLog::create([
            'branch_id' => $order->branch_id,
            'customer_id' => $order->customer_id,
            'recipient' => $customer->phone,
            'message' => $message,
            'status' => 'queued',
            'response' => 'Waiting for SMS provider.',
        ]);

        self::send($log, $settings);
    }

    private static function send(SmsLog $log, SystemSetting $settings): void
    {
        if (Str::lower((string) $settings->sms_provider) !== 'twilio') {
            $log->update([
                'status' => 'queued',
                'response' => 'SMS provider is not configured for live sending.',
            ]);

            return;
        }

        $accountSid = trim((string) $settings->twilio_account_sid);
        $authToken = trim((string) ($settings->twilio_auth_token ?: $settings->sms_api_key));
        $from = trim((string) $settings->twilio_from_number);

        if ($accountSid === '' || $authToken === '' || $from === '') {
            $log->update([
                'status' => 'queued',
                'response' => 'Twilio is selected but credentials or from number are incomplete.',
            ]);

            return;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($accountSid, $authToken)
                ->timeout(10)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json", [
                    'From' => $from,
                    'To' => self::normalizePhone($log->recipient),
                    'Body' => $log->message,
                ]);

            $payload = $response->json() ?? [];
            $sid = $payload['sid'] ?? null;
            $error = $payload['message'] ?? $response->body();

            $log->update([
                'status' => $response->successful() ? 'sent' : 'failed',
                'response' => $response->successful()
                    ? 'Twilio message sent'.($sid ? " ({$sid})" : '.')
                    : Str::limit('Twilio error: '.$error, 1000),
            ]);
        } catch (\Throwable $exception) {
            $log->update([
                'status' => 'failed',
                'response' => Str::limit('Twilio request failed: '.$exception->getMessage(), 1000),
            ]);
        }
    }

    private static function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[\s\-\(\)]/', '', trim($phone)) ?: '';

        if (Str::startsWith($phone, '+')) {
            return $phone;
        }

        if (Str::startsWith($phone, '09')) {
            return '+63'.substr($phone, 1);
        }

        if (Str::startsWith($phone, '63')) {
            return '+'.$phone;
        }

        return $phone;
    }
}
