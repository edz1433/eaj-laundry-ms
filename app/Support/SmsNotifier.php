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
            'unisms' => self::sendWithUniSms($log, $settings),
            default => $log->update([
                'status' => 'queued',
                'response' => 'UniSMS is not configured for live sending.',
            ]),
        };
    }

    private static function sendWithUniSms(SmsLog $log, SystemSetting $settings): void
    {
        $secretKey = trim((string) $settings->sms_api_key);

        if ($secretKey === '') {
            $log->update([
                'status' => 'queued',
                'response' => 'UniSMS is selected but the API secret key is missing.',
            ]);

            return;
        }

        $payload = [
            'recipient' => self::normalizePhone($log->recipient),
            'content' => self::smsContent($log->message),
            'metadata' => [
                'sms_log_id' => (string) $log->id,
                'branch_id' => (string) $log->branch_id,
                'customer_id' => (string) $log->customer_id,
            ],
        ];
        $senderId = trim((string) $settings->unisms_sender_id);
        if ($senderId !== '') {
            $payload['sender_id'] = $senderId;
        }

        try {
            $response = Http::withBasicAuth($secretKey, '')
                ->acceptJson()
                ->asJson()
                ->timeout(10)
                ->post('https://unismsapi.com/api/sms', $payload);

            $responsePayload = $response->json();
            $messageResult = is_array($responsePayload) ? ($responsePayload['message'] ?? $responsePayload) : [];
            $referenceId = $messageResult['reference_id'] ?? null;
            $providerStatus = Str::lower((string) ($messageResult['status'] ?? ''));
            $accepted = $response->created() && ! in_array($providerStatus, ['failed'], true);
            $error = $messageResult['fail_reason'] ?? $messageResult['message'] ?? $response->body();

            $log->update([
                'status' => $accepted ? 'sent' : 'failed',
                'response' => $accepted
                    ? 'UniSMS message accepted'.($referenceId ? " ({$referenceId})" : '.')
                    : Str::limit('UniSMS error: '.$error, 1000),
            ]);
        } catch (\Throwable $exception) {
            $log->update([
                'status' => 'failed',
                'response' => Str::limit('UniSMS request failed: '.$exception->getMessage(), 1000),
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

    private static function smsContent(string $message): string
    {
        return Str::limit($message, 160, '');
    }
}
