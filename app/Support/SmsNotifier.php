<?php

namespace App\Support;

use App\Models\JobOrder;
use App\Models\SmsLog;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SmsNotifier
{
    public static function jobOrderReceived(JobOrder $order): void
    {
        self::withoutInterruptingOperations(function () use ($order): void {
            $settings = SystemSetting::current();
            $order->loadMissing('customer');
            $customer = $order->customer;

            if (! $settings->sms_enabled || ! $customer?->canReceiveSms()) {
                return;
            }

            $store = $settings->business_name ?: config('app.name');
            $message = $order->transaction_type === 'delivery'
                ? "Hi {$customer->name}, {$store} has picked up and received your laundry order {$order->job_order_number}. It is now recorded and queued for processing. We will notify you when it is ready."
                : "Hi {$customer->name}, {$store} has received your laundry order {$order->job_order_number}. It is now recorded and queued for processing. We will notify you when it is ready.";

            self::queue($order, $message, $settings);
        });
    }

    public static function jobOrderStatus(JobOrder $order): void
    {
        self::withoutInterruptingOperations(function () use ($order): void {
            $settings = SystemSetting::current();
            $order->loadMissing('customer');
            $customer = $order->customer;

            if (! $settings->sms_enabled || ! $customer?->canReceiveSms()) {
                return;
            }

            $message = match ($order->status) {
                'ready_for_pickup' => "Hi {$customer->name}, your laundry {$order->job_order_number} is ready for pickup.",
                'ready_for_delivery' => "Hi {$customer->name}, your laundry {$order->job_order_number} is ready for delivery.",
                'completed' => "Hi {$customer->name}, your laundry {$order->job_order_number} has been completed. Thank you.",
                default => null,
            };

            if (! $message) {
                return;
            }

            self::queue($order, $message, $settings);
        });
    }

    private static function withoutInterruptingOperations(callable $notification): void
    {
        try {
            $notification();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private static function queue(JobOrder $order, string $message, SystemSetting $settings): void
    {
        $customer = $order->customer;
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
        match (Str::lower((string) $settings->sms_provider)) {
            'semaphore' => self::sendWithSemaphore($log, $settings),
            'twilio' => self::sendWithTwilio($log, $settings),
            default => $log->update([
                'status' => 'queued',
                'response' => 'SMS provider is not configured for live sending.',
            ]),
        };
    }

    private static function sendWithSemaphore(SmsLog $log, SystemSetting $settings): void
    {
        $apiKey = trim((string) $settings->sms_api_key);

        if ($apiKey === '') {
            $log->update([
                'status' => 'queued',
                'response' => 'Semaphore is selected but the API key is missing.',
            ]);

            return;
        }

        $payload = [
            'apikey' => $apiKey,
            'number' => self::normalizeSemaphorePhone($log->recipient),
            'message' => $log->message,
        ];
        $senderName = trim((string) $settings->semaphore_sender_name);
        if ($senderName !== '') {
            $payload['sendername'] = $senderName;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post('https://api.semaphore.co/api/v4/messages', $payload);

            $responsePayload = $response->json();
            $messageResult = is_array($responsePayload) ? ($responsePayload[0] ?? $responsePayload) : [];
            $messageId = $messageResult['message_id'] ?? null;
            $providerStatus = Str::lower((string) ($messageResult['status'] ?? ''));
            $accepted = $response->successful() && ! in_array($providerStatus, ['failed', 'refunded'], true);
            $error = $messageResult['message'] ?? $response->body();

            $log->update([
                'status' => $accepted ? 'sent' : 'failed',
                'response' => $accepted
                    ? 'Semaphore message accepted'.($messageId ? " ({$messageId})" : '.')
                    : Str::limit('Semaphore error: '.$error, 1000),
            ]);
        } catch (\Throwable $exception) {
            $log->update([
                'status' => 'failed',
                'response' => Str::limit('Semaphore request failed: '.$exception->getMessage(), 1000),
            ]);
        }
    }

    private static function sendWithTwilio(SmsLog $log, SystemSetting $settings): void
    {
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

    private static function normalizeSemaphorePhone(string $phone): string
    {
        return ltrim(self::normalizePhone($phone), '+');
    }
}
