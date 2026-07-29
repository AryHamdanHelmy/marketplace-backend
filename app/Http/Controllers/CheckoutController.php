<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\CheckoutAttempt;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    // POST /api/checkout
    public function store(Request $request)
    {
        $validated = $request->validate([
            'idempotency_key' => 'required|string|max:100',
            'payment_method'  => 'required|in:bank_transfer,ewallet,cod',
            'notes'           => 'nullable|string|max:500',
        ]);

        $userId = $request->user()->id;

        // --- Guard 1: idempotency ---
        // Kalau key ini sudah pernah dipakai, kembalikan hasil checkout yang lama.
        // Ini yang bikin double-click / retry jaringan tidak jadi dua pesanan.
        $existing = CheckoutAttempt::where('user_id', $userId)
            ->where('idempotency_key', $validated['idempotency_key'])
            ->first();

        if ($existing && $existing->checkout_group_id) {
            return $this->respondWithGroup($existing->checkout_group_id, $userId, 200);
        }

        try {
            $checkoutGroupId = DB::transaction(function () use ($userId, $validated) {

                // --- Guard 2: kunci attempt di dalam transaksi ---
                // Unique index (user_id, idempotency_key) bikin request kedua
                // yang datang bersamaan langsung gagal di sini, bukan bikin pesanan kedua.
                $attempt = CheckoutAttempt::create([
                    'user_id'         => $userId,
                    'idempotency_key' => $validated['idempotency_key'],
                ]);

                // --- Ambil isi cart ---
                $cartItems = CartItem::with('product.seller', 'product.primaryImage')
                    ->where('user_id', $userId)
                    ->get();

                if ($cartItems->isEmpty()) {
                    abort(422, 'Your cart is empty');
                }

                $cartItems = $cartItems->filter(fn($item) => $item->product !== null);

                if ($cartItems->isEmpty()) {
                    abort(422, 'All items in your cart are no longer available');
                }

                // --- Kunci produk berurutan by ID ---
                // Urutan menaik yang konsisten mencegah deadlock: kalau dua checkout
                // mengunci produk yang sama tapi urutannya beda, keduanya bisa saling
                // menunggu selamanya. Dengan sort, semua orang mengunci dengan urutan sama.
                $productIds = $cartItems->pluck('product_id')->unique()->sort()->values();

                $products = Product::whereIn('id', $productIds)
                    ->orderBy('id')
                    ->lockForUpdate()          // baris terkunci sampai transaksi selesai
                    ->get()
                    ->keyBy('id');

                // --- Validasi stok setelah lock ---
                // Dibaca SETELAH lockForUpdate, jadi angkanya dijamin bukan hasil
                // pembacaan basi milik request lain yang belum commit.
                $errors = [];
                foreach ($cartItems as $item) {
                    $product = $products->get($item->product_id);

                    if (!$product) {
                        $errors[] = "Product is no longer available";
                        continue;
                    }

                    if ($product->status !== 'active') {
                        $errors[] = "{$product->name} is not available for purchase";
                        continue;
                    }

                    if ($product->stock < $item->quantity) {
                        $errors[] = "{$product->name} — only {$product->stock} left in stock";
                    }
                }

                if (!empty($errors)) {
                    // Semua atau tidak sama sekali: satu item gagal, batalkan seluruhnya
                    abort(response()->json([
                        'success' => false,
                        'message' => 'Some items are unavailable',
                        'errors'  => $errors,
                    ], 422));
                }

                // --- Pecah cart per seller ---
                $groupedBySeller = $cartItems->groupBy(fn($item) => $item->product->seller_id);

                $checkoutGroupId = (string) Str::uuid();

                foreach ($groupedBySeller as $sellerId => $items) {

                    // Hitung total pakai bcmath, bukan float.
                    // Float bikin 0.1 + 0.2 != 0.3 — tidak boleh terjadi pada uang.
                    $total = '0';
                    foreach ($items as $item) {
                        $subtotal = bcmul((string) $item->product->price, (string) $item->quantity, 2);
                        $total    = bcadd($total, $subtotal, 2);
                    }

                    $transaction = Transaction::create([
                        'checkout_group_id' => $checkoutGroupId,
                        'invoice_number'    => $this->generateInvoiceNumber(),
                        'buyer_id'          => $userId,
                        'seller_id'         => $sellerId,
                        'seller_name'       => $items->first()->product->seller?->name ?? 'Unknown Seller',
                        'status'            => 'pending',
                        'total_amount'      => $total,
                    ]);

                    foreach ($items as $item) {
                        $product  = $products->get($item->product_id);
                        $subtotal = bcmul((string) $product->price, (string) $item->quantity, 2);

                        // Snapshot: nilai dibekukan di sini, tidak ikut berubah
                        // kalau seller mengedit produknya besok.
                        TransactionItem::create([
                            'transaction_id'    => $transaction->id,
                            'product_id'        => $product->id,
                            'product_name'      => $product->name,
                            'product_thumbnail' => $product->primaryImage?->image_path,
                            'product_price'     => $product->price,
                            'quantity'          => $item->quantity,
                            'subtotal'          => $subtotal,
                        ]);

                        // --- Kurangi stok secara atomik ---
                        // decrement() menghasilkan "SET stock = stock - n" di level SQL,
                        // bukan baca-ke-PHP-lalu-tulis-balik yang rawan hilang update.
                        $affected = Product::where('id', $product->id)
                            ->where('stock', '>=', $item->quantity)   // sabuk pengaman kedua
                            ->decrement('stock', $item->quantity);

                        if ($affected === 0) {
                            // Praktisnya tidak akan kejadian karena sudah di-lock,
                            // tapi kalau sampai terjadi, rollback lebih baik daripada stok minus.
                            abort(409, "Stock changed for {$product->name}, please try again");
                        }
                    }

                    Payment::create([
                        'transaction_id' => $transaction->id,
                        'method'         => $validated['payment_method'],
                        'status'         => 'pending',
                        'amount'         => $total,
                    ]);
                }

                // --- Kosongkan cart ---
                CartItem::where('user_id', $userId)->delete();

                // Simpan group id ke attempt supaya retry mengembalikan hasil yang sama
                $attempt->update(['checkout_group_id' => $checkoutGroupId]);

                return $checkoutGroupId;
            }, 3); // retry 3x kalau terjadi deadlock

            return $this->respondWithGroup($checkoutGroupId, $userId, 201);

        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Request kembar datang nyaris bersamaan — yang kalah balikin hasil yang menang
            $attempt = CheckoutAttempt::where('user_id', $userId)
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();

            if ($attempt?->checkout_group_id) {
                return $this->respondWithGroup($attempt->checkout_group_id, $userId, 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Checkout is already being processed, please wait',
            ], 409);
        }
    }

    // GET /api/checkout/{groupId}
    public function showGroup(Request $request, $groupId)
    {
        return $this->respondWithGroup($groupId, $request->user()->id, 200);
    }

    private function respondWithGroup(string $groupId, int $userId, int $httpCode)
    {
        $transactions = Transaction::with(['items', 'payment'])
            ->where('checkout_group_id', $groupId)
            ->where('buyer_id', $userId)      // cegah user lain intip pesanan orang
            ->get();

        if ($transactions->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }

        $grandTotal = $transactions->reduce(
            fn($carry, $trx) => bcadd($carry, (string) $trx->total_amount, 2),
            '0'
        );

        return response()->json([
            'success' => true,
            'message' => 'Order retrieved successfully',
            'data'    => [
                'checkout_group_id' => $groupId,
                'grand_total'       => $grandTotal,
                'transactions'      => $transactions->map(fn($trx) => [
                    'id'             => $trx->id,
                    'invoice_number' => $trx->invoice_number,
                    'seller_name'    => $trx->seller_name,
                    'status'         => $trx->status,
                    'total_amount'   => $trx->total_amount,
                    'payment'        => $trx->payment ? [
                        'method' => $trx->payment->method,
                        'status' => $trx->payment->status,
                    ] : null,
                    'items' => $trx->items->map(fn($item) => [
                        'product_id'    => $item->product_id,
                        'product_name'  => $item->product_name,
                        'thumbnail'     => $item->product_thumbnail,
                        'price'         => $item->product_price,
                        'quantity'      => $item->quantity,
                        'subtotal'      => $item->subtotal,
                    ]),
                ]),
            ],
        ], $httpCode);
    }

    private function generateInvoiceNumber(): string
    {
        // INV-20260722-A1B2C3 — tanggal untuk keterbacaan, random untuk anti-tabrakan.
        // Tidak pakai nomor urut karena butuh baca tabel dulu = titik kontensi baru.
        return 'INV-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }
}