<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Transaction;
use App\Services\BalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    // ==================== BUYER ====================

    // GET /api/orders
    public function index(Request $request)
    {
        $query = Transaction::with(['items', 'payment'])
            ->where('buyer_id', $request->user()->id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->orderByDesc('created_at')
            ->paginate($request->query('per_page', 10));

        return response()->json([
            'success' => true,
            'message' => 'Orders retrieved successfully',
            'data'    => collect($orders->items())->map(fn($trx) => $this->formatOrder($trx)),
            'meta'    => [
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
            ],
        ]);
    }

    // GET /api/orders/{id}
    public function show(Request $request, $id)
    {
        $order = Transaction::with(['items', 'payment', 'seller'])
            ->where('buyer_id', $request->user()->id)   // cegah intip pesanan orang lain
            ->find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order retrieved successfully',
            'data'    => $this->formatOrder($order, true),
        ]);
    }

    // POST /api/orders/{id}/pay
    public function pay(Request $request, $id)
    {
        $userId = $request->user()->id;

        try {
            $order = DB::transaction(function () use ($id, $userId) {

                // Lock supaya double-click tidak memproses pembayaran dua kali
                $order = Transaction::where('buyer_id', $userId)
                    ->lockForUpdate()
                    ->find($id);

                if (!$order) {
                    abort(404, 'Order not found');
                }

                if ($order->status !== 'pending') {
                    abort(422, "Order cannot be paid, current status: {$order->status}");
                }

                $order->update([
                    'status'  => 'paid',
                    'paid_at' => now(),
                ]);

                // Sinkronkan record payment
                $order->payment?->update([
                    'status'  => 'verified',
                    'paid_at' => now(),
                ]);

                return $order;
            });

            return response()->json([
                'success' => true,
                'message' => 'Payment confirmed successfully',
                'data'    => $this->formatOrder($order->fresh(['items', 'payment']), true),
            ]);

        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        }
    }

    // POST /api/orders/{id}/cancel
    public function cancel(Request $request, $id)
    {
        $userId = $request->user()->id;

        $order = DB::transaction(function () use ($id, $userId) {

            // Lock transaksi dulu — mencegah cancel diproses dua kali bersamaan
            $order = Transaction::with('items')
                ->where('buyer_id', $userId)
                ->lockForUpdate()
                ->find($id);

            if (!$order) {
                abort(404, 'Order not found');
            }

            if (!$order->isCancellable()) {
                abort(422, "Order cannot be cancelled, current status: {$order->status}");
            }

            // Kembalikan stok — urutkan by product_id untuk mencegah deadlock,
            // sama seperti di checkout
            $items = $order->items->sortBy('product_id');

            foreach ($items as $item) {
                if (!$item->product_id) {
                    continue;   // produk sudah dihapus, tidak ada stok untuk dikembalikan
                }

                // increment() atomik di level SQL
                Product::where('id', $item->product_id)
                    ->increment('stock', $item->quantity);
            }

            $order->update([
                'status'       => 'cancelled',
                'cancelled_at' => now(),
            ]);

            $order->payment?->update(['status' => 'failed']);

            return $order;
        });

        return response()->json([
            'success' => true,
            'message' => 'Order cancelled and stock restored',
            'data'    => $this->formatOrder($order->fresh(['items', 'payment']), true),
        ]);
    }

    // POST /api/orders/{id}/confirm
    //
    // The buyer confirming receipt is what releases the money. The seller's
    // balance is credited inside the same transaction, so the order can never
    // read as completed while the ledger says otherwise.
    public function confirmReceipt(Request $request, $id)
    {
        $userId = $request->user()->id;

        $order = DB::transaction(function () use ($id, $userId) {

            $order = Transaction::where('buyer_id', $userId)
                ->lockForUpdate()
                ->find($id);

            if (!$order) {
                abort(404, 'Order not found');
            }

            if ($order->status !== 'shipped') {
                abort(422, "Only shipped orders can be confirmed, current status: {$order->status}");
            }

            $order->update([
                'status'       => 'completed',
                'completed_at' => now(),
                'completed_by' => 'buyer',
            ]);

            app(BalanceService::class)->creditForCompletedOrder($order);

            return $order;
        });

        return response()->json([
            'success' => true,
            'message' => 'Thanks for confirming. The seller has been paid.',
            'data'    => $this->formatOrder($order->fresh(['items', 'payment']), true),
        ]);
    }

    // ==================== SELLER ====================

    // GET /api/seller/orders
    public function sellerOrders(Request $request)
    {
        $query = Transaction::with(['items', 'payment', 'buyer'])
            ->where('seller_id', $request->user()->id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->orderByDesc('created_at')
            ->paginate($request->query('per_page', 10));

        return response()->json([
            'success' => true,
            'message' => 'Seller orders retrieved successfully',
            'data'    => collect($orders->items())->map(fn($trx) => [
                ...$this->formatOrder($trx, true),
                'buyer' => $trx->buyer ? [
                    'id'   => $trx->buyer->id,
                    'name' => $trx->buyer->name,
                ] : null,
            ]),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
            ],
        ]);
    }

    // PUT /api/seller/orders/{id}/status
    //
    // Sellers can move an order forward only as far as "shipped". Completion
    // belongs to the buyer, or to the scheduled job once the confirmation
    // window expires — otherwise a seller could mark their own orders
    // complete and draw the money before anything was delivered.
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:shipped',
        ]);

        $userId = $request->user()->id;

        $order = DB::transaction(function () use ($id, $userId, $validated) {

            $order = Transaction::where('seller_id', $userId)
                ->lockForUpdate()
                ->find($id);

            if (!$order) {
                abort(404, 'Order not found');
            }

            // Transisi status yang diizinkan — mencegah lompat status sembarangan
            $allowed = [
                'paid' => ['shipped'],
            ];

            $canMoveTo = $allowed[$order->status] ?? [];

            if (!in_array($validated['status'], $canMoveTo)) {
                abort(422, "Cannot change status from {$order->status} to {$validated['status']}");
            }

            $order->update([
                'status'     => 'shipped',
                'shipped_at' => now(),
            ]);

            return $order;
        });

        return response()->json([
            'success' => true,
            'message' => 'Order marked as shipped',
            'data'    => $this->formatOrder($order->fresh(['items', 'payment']), true),
        ]);
    }

    // ==================== HELPER ====================

    private function formatOrder($trx, bool $withItems = false): array
    {
        $data = [
            'id'             => $trx->id,
            'invoice_number' => $trx->invoice_number,
            'checkout_group_id' => $trx->checkout_group_id,
            'seller_name'    => $trx->seller_name,
            'status'         => $trx->status,
            'total_amount'   => $trx->total_amount,
            'paid_at'        => $trx->paid_at,
            'shipping_address'=> $trx->shipping_address,
            'shipped_at'     => $trx->shipped_at,
            'completed_at'   => $trx->completed_at,
            'cancelled_at'   => $trx->cancelled_at,
            'created_at'     => $trx->created_at,
            'is_cancellable' => $trx->isCancellable(),
            'payment'        => $trx->payment ? [
                'method' => $trx->payment->method,
                'status' => $trx->payment->status,
                'amount' => $trx->payment->amount,
            ] : null,
            'item_count'     => $trx->items->count(),
        ];

        if ($withItems) {
            $data['items'] = $trx->items->map(fn($item) => [
                'product_id'   => $item->product_id,
                'product_name' => $item->product_name,
                'thumbnail'    => $item->product_thumbnail,
                'price'        => $item->product_price,
                'quantity'     => $item->quantity,
                'subtotal'     => $item->subtotal,
            ]);
        }

        return $data;
    }
}