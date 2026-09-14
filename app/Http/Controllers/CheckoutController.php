<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\CheckoutAttempt;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\Address;
use App\Shipping\ShipmentQuote;
use App\Shipping\ShippingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly ShippingService $shipping,
    ) {}

    // POST /api/checkout
    public function store(Request $request)
    {
        $validated = $request->validate([
            'idempotency_key' => 'required|string|max:100',
            'address_id'      => 'required|integer',
            'payment_method'  => 'required|in:bank_transfer,ewallet,cod',
            'notes'           => 'nullable|string|max:500',
            'cart_item_ids'   => 'nullable|array|min:1',
            'cart_item_ids.*' => 'integer',

            // Chosen courier per seller: { "3": "jne:REG", "7": "sicepat:BEST" }
            // Only the key is accepted — never a price. See verifyShipping().
            'shipping'        => 'required|array',
            'shipping.*'      => 'required|string|max:60',
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

        // --- Ongkir diverifikasi SEBELUM transaksi database dibuka ---
        // Ini panggilan HTTP ke pihak ketiga. Kalau dilakukan di dalam
        // DB::transaction, baris produk tetap terkunci selama menunggu jaringan —
        // satu API yang lambat langsung jadi antrean checkout yang macet.
        // Di luar transaksi, yang paling buruk terjadi cuma request ini gagal.
        $shippingPlan = $this->verifyShipping($request, $validated);

        if (isset($shippingPlan['error'])) {
            return response()->json([
                'success' => false,
                'message' => $shippingPlan['error'],
            ], 422);
        }

        try {
            $checkoutGroupId = DB::transaction(function () use ($userId, $validated, $shippingPlan) {

                // --- Guard 2: kunci attempt di dalam transaksi ---
                // Unique index (user_id, idempotency_key) bikin request kedua
                // yang datang bersamaan langsung gagal di sini, bukan bikin pesanan kedua.
                $attempt = CheckoutAttempt::create([
                    'user_id'         => $userId,
                    'idempotency_key' => $validated['idempotency_key'],
                ]);

                // --- Ambil isi cart ---
                $selectedIds = $validated['cart_item_ids'] ?? null;

                $cartQuery = CartItem::with('product.seller', 'product.primaryImage')
                    ->where('user_id', $userId);      // pagar kepemilikan

                if ($selectedIds) {
                    $cartQuery->whereIn('id', $selectedIds);
                }

                $cartItems = $cartQuery->get();

                if ($cartItems->isEmpty()) {
                    abort(422, 'No items selected for checkout');
                }

                // ID milik user lain / sudah terhapus akan hilang diam-diam di query di atas.
                // Lebih baik gagal terang-terangan daripada user membayar lebih sedikit
                // dari yang dia kira dia beli.
                if ($selectedIds && $cartItems->count() !== count(array_unique($selectedIds))) {
                    abort(422, 'Some selected items are no longer in your cart');
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

                $address = Address::where('user_id', $userId)
                    ->find($validated['address_id']);
                if (!$address) {
                    abort(422, 'Choose a shipping address first.');
                }

                $addressSnapshot = $address->toSnapshot();

                $checkoutGroupId = (string) Str::uuid();

                foreach ($groupedBySeller as $sellerId => $items) {

                    // Hitung total pakai bcmath, bukan float.
                    // Float bikin 0.1 + 0.2 != 0.3 — tidak boleh terjadi pada uang.
                    $total = '0';
                    foreach ($items as $item) {
                        $subtotal = bcmul((string) $item->product->price, (string) $item->quantity, 2);
                        $total    = bcadd($total, $subtotal, 2);
                    }

                    // Ongkir sudah diverifikasi ke provider di luar transaksi.
                    // Kalau seller ini tidak ada di rencana, verifyShipping()
                    // seharusnya sudah menolak lebih dulu — abort di sini cuma
                    // jaring pengaman supaya tidak ada pesanan lahir tanpa ongkir.
                    $ship = $shippingPlan['sellers'][$sellerId] ?? null;

                    if (!$ship) {
                        abort(422, 'Shipping was not selected for every shop in this order.');
                    }

                    $transaction = Transaction::create([
                        'checkout_group_id' => $checkoutGroupId,
                        'shipping_address'  => $addressSnapshot,
                        'invoice_number'    => $this->generateInvoiceNumber(),
                        'buyer_id'          => $userId,
                        'seller_id'         => $sellerId,
                        'seller_name'       => $items->first()->product->seller?->name ?? 'Unknown Seller',
                        'status'            => 'pending',

                        // total_amount tetap murni harga barang — itu yang jadi
                        // hak seller. shipping_cost berdiri sendiri karena
                        // uangnya milik kurir, bukan milik toko.
                        'total_amount'      => $total,
                        'shipping_cost'     => $ship['cost'],
                        'courier_code'      => $ship['courier_code'],
                        'courier_service'   => $ship['service_code'],
                        'courier_etd'       => $ship['etd'],
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

                    // Yang ditagih ke pembeli = barang + ongkir.
                    Payment::create([
                        'transaction_id' => $transaction->id,
                        'method'         => $validated['payment_method'],
                        'status'         => 'pending',
                        'amount'         => bcadd($total, (string) $ship['cost'], 2),
                    ]);
                }

                // --- Kosongkan cart ---
                CartItem::whereIn('id', $cartItems->pluck('id'))->delete();

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

    /**
     * Ubah pilihan kurir dari klien jadi ongkir yang dipercaya.
     *
     * Klien cuma mengirim kunci layanan ("jne:REG"). Harganya diambil ulang
     * dari provider di sini — tidak pernah dari request. Kalau harga ikut
     * dikirim klien, siapa pun bisa checkout dengan ongkir nol lewat satu
     * request yang diedit, dan selisihnya keluar dari kantong Rapaku waktu
     * escrow dicairkan.
     *
     * Hasilnya hampir selalu dari cache: halaman checkout baru saja memanggil
     * /shipping/quote dengan rute dan berat yang sama persis, jadi verifikasi
     * ini biasanya tidak menambah panggilan ke provider sama sekali.
     */
    private function verifyShipping(Request $request, array $validated): array
    {
        $userId = $request->user()->id;

        $address = Address::where('user_id', $userId)->find($validated['address_id']);

        if (!$address) {
            return ['error' => 'Choose a shipping address first.'];
        }

        if (!$address->destination_area_id) {
            return ['error' => 'This address needs a delivery area. Please edit it and pick your district.'];
        }

        $itemQuery = CartItem::with('product.seller.store')->where('user_id', $userId);

        if (!empty($validated['cart_item_ids'])) {
            $itemQuery->whereIn('id', $validated['cart_item_ids']);
        }

        $items = $itemQuery->get();

        if ($items->isEmpty()) {
            return ['error' => 'No items selected for checkout'];
        }

        $plan = [];

        foreach ($items->groupBy(fn (CartItem $i) => $i->product->seller_id) as $sellerId => $sellerItems) {
            $chosenKey = $validated['shipping'][$sellerId] ?? null;

            if (!$chosenKey) {
                $name = $sellerItems->first()->product->seller?->name ?? 'a shop';

                return ['error' => "Pick a courier for {$name} before placing the order."];
            }

            $store = $sellerItems->first()->product->seller->store ?? null;

            $quote = new ShipmentQuote(
                originAreaId: (string) ($store->origin_area_id ?? ''),
                destinationAreaId: (string) $address->destination_area_id,
                weightGrams: (int) $sellerItems->sum(
                    fn (CartItem $i) => (int) $i->product->weight_grams * $i->quantity
                ),
                itemValue: (int) round($sellerItems->sum(
                    fn (CartItem $i) => (float) $i->product->price * $i->quantity
                )),
                couriers: config('shipping.couriers', []),
            );

            $match = null;

            foreach ($this->shipping->quote($quote) as $rate) {
                if ($rate->key() === $chosenKey) {
                    $match = $rate;
                    break;
                }
            }

            if (!$match) {
                // Tarif berubah, rute jadi tidak dilayani, atau kuota provider
                // habis di antara halaman checkout dibuka dan tombol ditekan.
                // Semuanya berakhir sama: jangan buat pesanan dengan ongkir
                // yang tidak bisa dibuktikan.
                return ['error' => 'That shipping option is no longer available. Please refresh and choose again.'];
            }

            $plan[$sellerId] = [
                'cost'         => $match->cost,
                'courier_code' => $match->courierCode,
                'service_code' => $match->serviceCode,
                'etd'          => $match->etd,
            ];
        }

        return ['sellers' => $plan];
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

        // Grand total sekarang barang + ongkir, karena itu yang benar-benar
        // dibayar pembeli. Dipecah juga supaya frontend bisa menampilkan
        // rinciannya tanpa menghitung ulang sendiri.
        $itemsTotal = $transactions->reduce(
            fn($carry, $trx) => bcadd($carry, (string) $trx->total_amount, 2),
            '0'
        );

        $shippingTotal = $transactions->reduce(
            fn($carry, $trx) => bcadd($carry, (string) $trx->shipping_cost, 2),
            '0'
        );

        return response()->json([
            'success' => true,
            'message' => 'Order retrieved successfully',
            'data'    => [
                'checkout_group_id' => $groupId,
                'items_total'       => $itemsTotal,
                'shipping_total'    => $shippingTotal,
                'grand_total'       => bcadd($itemsTotal, $shippingTotal, 2),
                'transactions'      => $transactions->map(fn($trx) => [
                    'id'             => $trx->id,
                    'invoice_number' => $trx->invoice_number,
                    'seller_name'    => $trx->seller_name,
                    'status'         => $trx->status,
                    'total_amount'   => $trx->total_amount,
                    'shipping_cost'  => $trx->shipping_cost,
                    'courier'        => $trx->courier_code
                        ? trim($trx->courier_code . ' ' . $trx->courier_service)
                        : null,
                    'courier_etd'    => $trx->courier_etd,
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