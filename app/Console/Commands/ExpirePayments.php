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
                            {--dry-run : List what would expire without changing anything}';

    protected $description = 'Expire lapsed payment charges, cancel their orders, and return the reserved stock';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

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
        $transactions = Transaction::with('items')
            ->where('checkout_group_id', $order->checkout_group_id)
            ->lockForUpdate()
            ->get();

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