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

    public function test_semaphore_sends_sms_with_normalized_philippine_number(): void
    {
        Http::fake([
            'api.semaphore.co/*' => Http::response([[
                'message_id' => 12345,
                'status' => 'Pending',
            ]], 200),
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
            'sms_provider' => 'semaphore',
            'sms_api_key' => 'semaphore-secret',
            'semaphore_sender_name' => 'SPINKLEAN',
            'is_completed' => true,
        ]);

        SmsNotifier::jobOrderStatus($order);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.semaphore.co/api/v4/messages'
            && $request['apikey'] === 'semaphore-secret'
            && $request['number'] === '639171234567'
            && $request['sendername'] === 'SPINKLEAN'
            && str_contains($request['message'], 'ready for pickup'));

        $this->assertDatabaseHas('sms_logs', [
            'recipient' => '09171234567',
            'status' => 'sent',
            'response' => 'Semaphore message accepted (12345)',
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
            'sms_provider' => 'semaphore',
            'sms_api_key' => 'semaphore-secret',
            'is_completed' => true,
        ]);

        SmsNotifier::jobOrderReceived($order);
        SmsNotifier::jobOrderStatus($order);

        Http::assertNothingSent();
        $this->assertDatabaseCount('sms_logs', 0);
    }

    public function test_semaphore_failure_is_recorded_without_throwing(): void
    {
        Http::fake([
            'api.semaphore.co/*' => Http::response([
                'message' => 'Insufficient account balance.',
            ], 400),
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
            'sms_provider' => 'semaphore',
            'sms_api_key' => 'semaphore-secret',
            'is_completed' => true,
        ]);

        SmsNotifier::jobOrderStatus($order);

        $this->assertDatabaseHas('sms_logs', [
            'customer_id' => $order->customer_id,
            'status' => 'failed',
            'response' => 'Semaphore error: Insufficient account balance.',
        ]);
        $this->assertDatabaseHas('job_orders', [
            'id' => $order->id,
            'status' => 'ready_for_pickup',
        ]);
    }

    public function test_twilio_missing_config_keeps_sms_queued_without_throwing(): void
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
            'sms_provider' => 'twilio',
            'is_completed' => true,
        ]);

        SmsNotifier::jobOrderStatus($order);

        $this->assertDatabaseHas('sms_logs', [
            'recipient' => '09171234567',
            'status' => 'queued',
            'response' => 'Twilio is selected but credentials or from number are incomplete.',
        ]);
    }

    public function test_twilio_configured_sends_finish_sms(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201),
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
            'sms_provider' => 'twilio',
            'twilio_account_sid' => 'AC123',
            'twilio_auth_token' => 'secret',
            'twilio_from_number' => '+15551234567',
            'is_completed' => true,
        ]);

        SmsNotifier::jobOrderStatus($order);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json'
            && $request['From'] === '+15551234567'
            && $request['To'] === '+639171234567'
            && str_contains($request['Body'], 'ready for pickup'));

        $this->assertDatabaseHas('sms_logs', [
            'recipient' => '09171234567',
            'status' => 'sent',
            'response' => 'Twilio message sent (SM123)',
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
