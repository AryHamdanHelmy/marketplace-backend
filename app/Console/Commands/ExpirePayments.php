<?php

namespace App\Console\Commands;

use App\Models\PaymentOrder;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExpirePayments extends Command
{
    protected $signature = 'payments:expire
                            {--abandoned-hours= : Usia checkout terbengkalai sebelum stoknya dilepas}
                            {--dry-run : List what would expire without changing anything}';

    protected $description = 'Expire lapsed payment charges, cancel their orders, and return the reserved stock';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $status = $this->expireCharges($dryRun);

        // Sapuan kedua: checkout yang tidak pernah sampai ke halaman
        // pembayaran sama sekali. Tanpa ini stoknya tertahan selamanya —
        // lihat keterangan di releaseAbandonedCheckouts().
        $abandoned = $this->releaseAbandonedCheckouts($dryRun);

        return $status === self::SUCCESS && $abandoned === self::SUCCESS
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * Sapuan pertama: charge yang sudah lewat batas waktunya.
     */
    private function expireCharges(bool $dryRun): int
    {
        $query = PaymentOrder::whereIn('status', ['pending', 'awaiting_payment'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());

        $total = $query->count();

        if ($total === 0) {
            $this->info('Nothing to expire.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("{$total} charge(s) would expire.");

            $query->orderBy('expires_at')
                ->limit(20)
                ->get(['id', 'reference', 'amount', 'expires_at'])
                ->each(function ($order) {
                    $this->line(sprintf(
                        '  %s  %s  expired %s',
                        $order->reference,
                        number_format((float) $order->amount, 2),
                        $order->expires_at
                    ));
                });

            return self::SUCCESS;
        }

        $expired = 0;
        $released = 0;
        $failed = 0;

        $query->orderBy('id')->chunkById(50, function ($orders) use (&$expired, &$released, &$failed) {
            foreach ($orders as $order) {
                try {
                    $releasedHere = DB::transaction(function () use ($order) {
                        $fresh = PaymentOrder::lockForUpdate()->find($order->id);

                        // A webhook may have landed between the query and this
                        // lock. A charge that just settled must not be undone.
                        if (!$fresh || !in_array($fresh->status, ['pending', 'awaiting_payment'])) {
                            return 0;
                        }

                        $fresh->update(['status' => 'expired']);

                        return $this->cancelOrders($fresh);
                    });

                    $expired++;
                    $released += $releasedHere;
                } catch (Throwable $e) {
                    // One bad charge shouldn't hold up the rest
                    $failed++;
                    Log::error('Expiring payment failed', [
                        'payment_order_id' => $order->id,
                        'reference' => $order->reference,
                        'message' => $e->getMessage(),
                    ]);
                    $this->error("{$order->reference} failed: {$e->getMessage()}");
                }
            }
        });

        $this->info("Expired {$expired} charge(s), returned stock on {$released} item(s).");

        if ($failed > 0) {
            $this->warn("{$failed} charge(s) failed — see the log.");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }


    /**
     * Sapuan kedua: checkout yang stoknya ditahan tapi tidak pernah ditagih.
     *
     * Stok dikurangi saat POST /checkout, sebelum pembeli memilih metode
     * pembayaran. Kalau ia menutup tab di situ, tidak pernah ada PaymentOrder
     * yang lahir — dan sapuan pertama hanya melihat PaymentOrder. Akibatnya
     * transaksi 'pending' itu memegang stoknya selamanya, dan barang yang
     * sebenarnya tersedia tampak habis bagi pembeli lain.
     *
     * Ambang waktunya sengaja lebih longgar dari masa berlaku charge: sebuah
     * checkout hanya dianggap terbengkalai kalau tidak ada lagi tagihan hidup
     * yang menaunginya. Kalau charge-nya masih bisa dibayar, sapuan ini
     * melewatinya dan menyerahkan urusan ke sapuan pertama.
     */
    private function releaseAbandonedCheckouts(bool $dryRun): int
    {
        $hours = (int) ($this->option('abandoned-hours')
            ?: config('payments.expiry_hours', 24) + 1);

        if ($hours < 1) {
            $this->error('--abandoned-hours minimal 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subHours($hours);

        // Grup yang masih punya tagihan hidup atau sudah lunas bukan urusan
        // sapuan ini. Sisanya — tidak pernah ditagih, gagal saat ditagih,
        // atau tagihannya sudah kedaluwarsa — stoknya harus kembali.
        $sheltered = PaymentOrder::query()
            ->where(function ($q) {
                $q->whereIn('status', ['paid', 'refunded'])
                    ->orWhere(function ($live) {
                        $live->whereIn('status', ['pending', 'awaiting_payment'])
                            ->whereNotNull('expires_at')
                            ->where('expires_at', '>', now());
                    });
            })
            ->select('checkout_group_id');

        $query = Transaction::where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->where(function ($q) use ($sheltered) {
                $q->whereNull('checkout_group_id')
                    ->orWhereNotIn('checkout_group_id', $sheltered);
            });

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Tidak ada checkout terbengkalai.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("{$total} pesanan terbengkalai akan dibatalkan (dibuat sebelum {$cutoff->toDateTimeString()}).");

            return self::SUCCESS;
        }

        $cancelled = 0;
        $released = 0;
        $failed = 0;

        $query->orderBy('id')->chunkById(50, function ($transactions) use (&$cancelled, &$released, &$failed) {
            foreach ($transactions as $transaction) {
                try {
                    $outcome = DB::transaction(function () use ($transaction) {
                        $fresh = Transaction::with('items')
                            ->lockForUpdate()
                            ->find($transaction->id);

                        // Pembeli bisa saja membayar tepat di sela antara
                        // query dan lock ini. Pembayarannya yang menang, dan
                        // pesanannya tidak ikut terhitung dibatalkan.
                        if (!$fresh || $fresh->status !== 'pending') {
                            return null;
                        }

                        return $this->cancelTransactions(collect([$fresh]));
                    });

                    if ($outcome !== null) {
                        $cancelled++;
                        $released += $outcome;
                    }
                } catch (Throwable $e) {
                    $failed++;
                    Log::error('Melepas checkout terbengkalai gagal', [
                        'transaction_id' => $transaction->id,
                        'message' => $e->getMessage(),
                    ]);
                    $this->error("Pesanan {$transaction->id} gagal: {$e->getMessage()}");
                }
            }
        });

        $this->info("Membatalkan {$cancelled} pesanan terbengkalai, mengembalikan stok {$released} item.");

        if ($failed > 0) {
            $this->warn("{$failed} pesanan gagal — lihat log.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Cancel the seller orders behind an expired charge and put the stock back.
     *
     * Mirrors OrderController::cancel() — items are ordered by product_id
     * before locking, which is what stops two concurrent restocks from
     * deadlocking against each other.
     *
     * @return int number of line items whose stock was returned
     */
    private function cancelOrders(PaymentOrder $order): int
    {
        return $this->cancelTransactions(
            Transaction::with('items')
                ->where('checkout_group_id', $order->checkout_group_id)
                ->lockForUpdate()
                ->get()
        );
    }

    /**
     * Batalkan sekumpulan transaksi dan kembalikan stoknya.
     *
     * @param  \Illuminate\Support\Collection<int, Transaction>  $transactions
     * @return int jumlah baris item yang stoknya dikembalikan
     */
    private function cancelTransactions($transactions): int
    {
        $released = 0;

        foreach ($transactions as $transaction) {
            // Only untouched orders. Anything already paid or cancelled is
            // left exactly as it is.
            if ($transaction->status !== 'pending') {
                continue;
            }

            foreach ($transaction->items->sortBy('product_id') as $item) {
                if (!$item->product_id) {
                    continue;   // product was deleted; nothing to give back
                }

                Product::where('id', $item->product_id)
                    ->increment('stock', $item->quantity);

                $released++;
            }

            $transaction->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            $transaction->payment?->update(['status' => 'failed']);
        }

        return $released;
    }
}