<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\BalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CompleteShippedOrders extends Command
{
    protected $signature = 'orders:auto-complete
                            {--days=7 : Days after shipping before an order closes itself}
                            {--dry-run : List what would be completed without changing anything}';

    protected $description = 'Complete shipped orders whose confirmation window has passed and credit the seller';

    public function handle(BalanceService $balances): int
    {
        $days = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');

        if ($days < 1) {
            $this->error('The --days option must be at least 1.');
            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        // Orders shipped before the fulfilment timestamps existed have no
        // shipped_at, so they can never age out. They are counted and
        // reported rather than silently ignored.
        $orphaned = Transaction::where('status', 'shipped')
            ->whereNull('shipped_at')
            ->count();

        if ($orphaned > 0) {
            $this->warn("{$orphaned} shipped order(s) have no shipped_at and were skipped.");
        }

        $query = Transaction::where('status', 'shipped')
            ->whereNotNull('shipped_at')
            ->where('shipped_at', '<=', $cutoff);

        $total = $query->count();

        if ($total === 0) {
            $this->info('Nothing to complete.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("{$total} order(s) would be completed (shipped on or before {$cutoff->toDateTimeString()}).");

            $query->orderBy('shipped_at')
                ->limit(20)
                ->get(['id', 'invoice_number', 'seller_id', 'total_amount', 'shipped_at'])
                ->each(function ($order) {
                    $this->line(sprintf(
                        '  #%s  seller %d  %s  shipped %s',
                        $order->invoice_number ?? $order->id,
                        $order->seller_id,
                        number_format((float) $order->total_amount, 2),
                        $order->shipped_at
                    ));
                });

            return self::SUCCESS;
        }

        $completed = 0;
        $failed = 0;

        // Chunked by id so the result set stays stable while rows are being
        // updated out from under the cursor.
        $query->orderBy('id')->chunkById(100, function ($orders) use ($balances, &$completed, &$failed) {
            foreach ($orders as $order) {
                try {
                    DB::transaction(function () use ($order, $balances) {
                        $fresh = Transaction::lockForUpdate()->find($order->id);

                        // The buyer may have confirmed in the moment between
                        // the query and this lock. Their confirmation wins.
                        if (!$fresh || $fresh->status !== 'shipped') {
                            return;
                        }

                        $fresh->update([
                            'status'       => 'completed',
                            'completed_at' => now(),
                            'completed_by' => 'system',
                        ]);

                        $balances->creditForCompletedOrder($fresh);
                    });

                    $completed++;
                } catch (Throwable $e) {
                    // One bad order shouldn't stop the rest of the batch.
                    $failed++;
                    Log::error('Auto-complete failed for order ' . $order->id, [
                        'order_id' => $order->id,
                        'message'  => $e->getMessage(),
                    ]);
                    $this->error("Order {$order->id} failed: {$e->getMessage()}");
                }
            }
        });

        $this->info("Completed {$completed} order(s).");

        if ($failed > 0) {
            $this->warn("{$failed} order(s) failed — see the log.");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}