<?php

namespace App\Http\Controllers;

use App\Models\PaymentOrder;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    // GET /api/payments/channels
    public function channels()
    {
        return response()->json([
            'success' => true,
            'message' => 'Payment channels retrieved',
            'data' => $this->payments->availableChannels(),
        ]);
    }

    // POST /api/payments/{checkoutGroupId}/charge
    public function charge(Request $request, string $checkoutGroupId)
    {
        $validated = $request->validate([
            'channel' => 'required|string|max:40',
        ]);

        try {
            $order = $this->payments->createCharge(
                $checkoutGroupId,
                $request->user()->id,
                $validated['channel']
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment created',
            'data' => $this->format($order),
        ], 201);
    }

    // GET /api/payments/{checkoutGroupId}
    public function show(Request $request, string $checkoutGroupId)
    {
        $order = PaymentOrder::where('checkout_group_id', $checkoutGroupId)
            ->where('buyer_id', $request->user()->id)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment retrieved',
            'data' => $this->format($order),
        ]);
    }

    // POST /api/payments/{checkoutGroupId}/refresh
    //
    // Webhooks get lost. This lets a buyer stuck on the waiting screen ask the
    // provider directly. Throttled, because it hits the provider's API.
    public function refresh(Request $request, string $checkoutGroupId)
    {
        $order = PaymentOrder::where('checkout_group_id', $checkoutGroupId)
            ->where('buyer_id', $request->user()->id)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found',
            ], 404);
        }

        $refreshed = $this->payments->refreshStatus($order);

        return response()->json([
            'success' => true,
            'message' => 'Payment status refreshed',
            'data' => $this->format($refreshed ?? $order),
        ]);
    }

    // POST /api/payments/webhook/{gateway}
    //
    // Public by necessity — the provider has no credentials of ours. Every
    // payload is treated as hostile until the driver verifies its signature,
    // which is why nothing here reads the request body directly.
    public function webhook(Request $request, string $gateway)
    {
        try {
            $driver = $this->payments->gateway($gateway);
        } catch (RuntimeException) {
            // Don't reveal which gateway names exist
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $result = $driver->parseWebhook(
                $request->all(),
                $request->headers->all()
            );
        } catch (Throwable $e) {
            Log::error('Webhook parsing failed', [
                'gateway' => $gateway,
                'message' => $e->getMessage(),
            ]);

            // A 500 makes the provider retry, which is what we want when the
            // failure is ours rather than theirs.
            return response()->json(['message' => 'Could not process'], 500);
        }

        if (!$result) {
            Log::warning('Rejected an unverified webhook', ['gateway' => $gateway]);

            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $this->payments->applyResult($result);

        // Providers retry until they see a 200. Returning it here — even for a
        // reference we don't recognise — stops an endless retry loop over
        // something we can never resolve.
        return response()->json(['message' => 'ok']);
    }

    private function format(PaymentOrder $order): array
    {
        return [
            'checkout_group_id' => $order->checkout_group_id,
            'reference' => $order->reference,
            'amount' => $order->amount,
            'gateway' => $order->gateway,
            'channel' => $order->channel,
            'status' => $order->status,
            'instructions' => $order->instructions,
            'expires_at' => $order->expires_at,
            'paid_at' => $order->paid_at,
            'is_payable' => $order->isPayable(),
        ];
    }
}