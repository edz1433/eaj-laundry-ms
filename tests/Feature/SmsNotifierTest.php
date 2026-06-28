<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\JobOrder;
use App\Models\SmsLog;
use App\Models\SystemSetting;
use App\Support\SmsNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_unisms_sends_sms_with_normalized_philippine_number(): void
    {
        Http::fake([
            'unismsapi.com/*' => Http::response([
                'message' => [
                    'reference_id' => 'msg_12345',
                    'status' => 'sent',
                ],
            ], 201),
        ]);

        [$order] = $this->readyOrder();
        SystemSetting::query()->create([
            'business_name' => 'SPIN KLEAN LAUNDRY',
            'contact_number' => '09171234567',
            'business_address' => 'Manila',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'sms_enabled' => true,
            'sms_provider' => 'unisms',
            'sms_api_key' => 'unisms-secret',
            'unisms_sender_id' => 'SPINKLEAN',
            'is_completed' => true,
        ]);

        SmsNotifier::jobOrderStatus($order);

        Http::assertSent(fn ($request) => $request->url() === 'https://unismsapi.com/api/sms'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('unisms-secret:'))
            && $request['recipient'] === '+639171234567'
            && $request['sender_id'] === 'SPINKLEAN'
            && $request['metadata']['sms_log_id'] !== null
            && str_contains($request['content'], 'ready for pickup'));

        $this->assertDatabaseHas('sms_logs', [
            'recipient' => '09171234567',
            'status' => 'sent',
            'response' => 'UniSMS message accepted (msg_12345)',
        ]);
    }

    public function test_po_customer_never_receives_or_queues_sms(): void
    {
        Http::fake();
        [$order, , $customer] = $this->readyOrder();
        $customer->update(['billing_type' => 'po']);
        $order->setRelation('customer', $customer->fresh());

        SystemSetting::query()->create([
            'business_name' => 'SPIN KLEAN LAUNDRY',
            'contact_number' => '09171234567',
            'business_address' => 'Manila',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'sms_enabled' => true,
            'sms_provider' => 'unisms',
            'sms_api_key' => 'unisms-secret',
            'is_completed' => true,
        ]);

        SmsNotifier::jobOrderReceived($order);
        SmsNotifier::jobOrderStatus($order);

        Http::assertNothingSent();
        $this->assertDatabaseCount('sms_logs', 0);
    }

    public function test_unisms_failure_is_recorded_without_throwing(): void
    {
        Http::fake([
            'unismsapi.com/*' => Http::response([
                'message' => [
                    'status' => 'failed',
                    'fail_reason' => 'Insufficient account balance.',
                ],
            ], 422),
        ]);

        [$order] = $this->readyOrder();
        SystemSetting::query()->create([
            'business_name' => 'SPIN KLEAN LAUNDRY',
            'contact_number' => '09171234567',
            'business_address' => 'Manila',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'sms_enabled' => true,
            'sms_provider' => 'unisms',
            'sms_api_key' => 'unisms-secret',
            'is_completed' => true,
        ]);

        SmsNotifier::jobOrderStatus($order);

        $this->assertDatabaseHas('sms_logs', [
            'customer_id' => $order->customer_id,
            'status' => 'failed',
            'response' => 'UniSMS error: Insufficient account balance.',
        ]);
        $this->assertDatabaseHas('job_orders', [
            'id' => $order->id,
            'status' => 'ready_for_pickup',
        ]);
    }

    public function test_unisms_missing_secret_key_keeps_sms_queued_without_throwing(): void
    {
        [$order] = $this->readyOrder();

        SystemSetting::query()->create([
            'business_name' => 'EAJ Laundry',
            'contact_number' => '09171234567',
            'business_address' => 'Manila',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'sms_enabled' => true,
            'sms_provider' => 'unisms',
            'is_completed' => true,
        ]);

        SmsNotifier::jobOrderStatus($order);

        $this->assertDatabaseHas('sms_logs', [
            'recipient' => '09171234567',
            'status' => 'queued',
            'response' => 'UniSMS is selected but the API secret key is missing.',
        ]);
    }

    public function test_unisms_configured_sends_finish_sms(): void
    {
        Http::fake([
            'unismsapi.com/*' => Http::response([
                'message' => [
                    'reference_id' => 'msg_finish',
                    'status' => 'sent',
                ],
            ], 201),
        ]);

        [$order] = $this->readyOrder();

        SystemSetting::query()->create([
            'business_name' => 'EAJ Laundry',
            'contact_number' => '09171234567',
            'business_address' => 'Manila',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'sms_enabled' => true,
            'sms_provider' => 'unisms',
            'sms_api_key' => 'secret',
            'is_completed' => true,
        ]);

        SmsNotifier::jobOrderStatus($order);

        Http::assertSent(fn ($request) => $request->url() === 'https://unismsapi.com/api/sms'
            && $request['recipient'] === '+639171234567'
            && str_contains($request['content'], 'ready for pickup'));

        $this->assertDatabaseHas('sms_logs', [
            'recipient' => '09171234567',
            'status' => 'sent',
            'response' => 'UniSMS message accepted (msg_finish)',
        ]);
    }

    public function test_walk_in_received_sms_uses_drop_off_message(): void
    {
        [$order] = $this->readyOrder();
        $order->update([
            'status' => 'pending',
            'transaction_type' => 'walk_in',
        ]);

        SystemSetting::query()->create([
            'business_name' => 'SPIN KLEAN LAUNDRY',
            'contact_number' => '09171234567',
            'business_address' => 'Manila',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'sms_enabled' => true,
            'is_completed' => true,
        ]);

        SmsNotifier::jobOrderReceived($order);

        $message = (string) SmsLog::query()->value('message');
        $this->assertStringContainsString('SPIN KLEAN LAUNDRY has received your laundry order', $message);
        $this->assertStringNotContainsString('picked up', $message);
        $this->assertStringContainsString('queued for processing', $message);
    }

    private function readyOrder(): array
    {
        $branch = Branch::query()->create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Laundry Customer',
            'phone' => '09171234567',
            'billing_type' => 'regular',
            'is_active' => true,
        ]);
        $order = JobOrder::query()->create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'job_order_number' => 'JO-TEST-001',
            'status' => 'ready_for_pickup',
            'subtotal' => 0,
            'discount' => 0,
            'tax' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'balance' => 0,
        ]);
        $order->load('customer');

        return [$order, $branch, $customer];
    }
}
