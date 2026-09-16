<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\RefundService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Antrean refund manual untuk admin.
 *
 * Pembeli tidak bisa membatalkan pesanan yang sudah dibayar sendiri — tidak
 * ada jalur refund otomatis, jadi membiarkannya berarti uang mengendap tanpa
 * catatan. Pembeli menghubungi dukungan, admin memutuskan, dan jejaknya
 * tercatat di sini.
 */
class AdminRefundController extends Controller
{
    public function __construct(private RefundService $refunds) {}

    // GET /api/admin/refunds
    public function index(Request $request)
    {
        if ($deny = $this->denyIfNotAdmin($request)) return $deny;

        $status = $request->query('status', 'refund_pending');

        if (!in_array($status, ['refund_pending', 'refunded'])) {
            $status = 'refund_pending';
        }

        $orders = Transaction::with(['buyer:id,name,email', 'items'])
            ->where('status', $status)
            // Paling lama menunggu didahulukan — pembeli yang uangnya belum
            // kembali sudah menunggu paling lama pula.
            ->orderBy('refund_requested_at')
            ->paginate($this->perPage($request, 20));

        return response()->json([
            'success' => true,
            'message' => 'Refund queue retrieved',
            'data' => collect($orders->items())->map(fn ($order) => $this->format($order)),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'pending_count' => Transaction::where('status', 'refund_pending')->count(),
                'pending_amount' => Transaction::where('status', 'refund_pending')->sum('total_amount'),
            ],
        ]);
    }

    // POST /api/admin/orders/{id}/refund-pending
    //
    // Memindahkan pesanan ke antrean. Belum ada uang yang bergerak; stok
    // dikembalikan di sini karena barangnya batal terjual sejak keputusan
    // diambil.
    public function markPending(Request $request, $id)
    {
        if ($deny = $this->denyIfNotAdmin($request)) return $deny;

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ], [
            'reason.required' => 'Tulis alasannya — pembeli melihat ini.',
        ]);

        $order = Transaction::find($id);

        if (!$order) {
            return $this->notFound();
        }

        try {
            $order = $this->refunds->markPending($order, $validated['reason']);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pesanan masuk antrean pengembalian dana',
            'data' => $this->format($order->fresh(['buyer', 'items'])),
        ]);
    }

    // PATCH /api/admin/refunds/{id}/refunded
    //
    // Dipanggil setelah transfer benar-benar dilakukan.
    public function markRefunded(Request $request, $id)
    {
        if ($deny = $this->denyIfNotAdmin($request)) return $deny;

        $validated = $request->validate([
            'transfer_reference' => 'required|string|max:100',
            'note' => 'nullable|string|max:500',
        ], [
            'transfer_reference.required' => 'Masukkan bukti transfer supaya refund ini bisa ditelusuri.',
        ]);

        $order = Transaction::find($id);

        if (!$order) {
            return $this->notFound();
        }

        try {
            $order = $this->refunds->markRefunded(
                $order,
                $request->user(),
                $validated['transfer_reference'],
                $validated['note'] ?? null
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Refund tercatat',
            'data' => $this->format($order->fresh(['buyer', 'items'])),
        ]);
    }

    private function format(Transaction $order): array
    {
        return [
            'id' => $order->id,
            'invoice_number' => $order->invoice_number,
            'status' => $order->status,
            'total_amount' => $order->total_amount,
            'shipping_cost' => $order->shipping_cost,
            'seller_name' => $order->seller_name,
            'refund_reason' => $order->refund_reason,
            'refund_reference' => $order->refund_reference,
            'refund_requested_at' => $order->refund_requested_at,
            'refunded_at' => $order->refunded_at,
            'paid_at' => $order->paid_at,
            'buyer' => $order->buyer ? [
                'id' => $order->buyer->id,
                'name' => $order->buyer->name,
                'email' => $order->buyer->email,
            ] : null,
            'item_count' => $order->items->count(),
        ];
    }

    private function notFound()
    {
        return response()->json([
            'success' => false,
            'message' => 'Pesanan tidak ditemukan',
        ], 404);
    }

    private function denyIfNotAdmin(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => "You don't have access to this.",
            ], 403);
        }

        return null;
    }
}
