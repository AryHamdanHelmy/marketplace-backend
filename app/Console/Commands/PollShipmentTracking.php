<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Shipping\ShippingService;
use App\Shipping\TrackingResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes courier tracking for parcels still in flight.
 *
 * This is the only thing in the app that calls a courier for tracking. The
 * order page reads transactions.tracking_snapshot and never reaches out — a
 * buyer hitting refresh must not spend quota, and page load must not depend on
 * a courier's uptime.
 *
 * Quota is the whole design constraint here. RajaOngkir's free tier allows 100
 * calls a day, shared with checkout quotes. Three rules keep this command from
 * eating all of it:
 *
 *   - Only 'shipped' orders are polled. Delivered parcels are never asked
 *     about again, and delivered orders only accumulate.
 *   - A batch size caps each run, so a backlog drains over hours rather than
 *     exhausting the day in one go.
 *   - Parcels the courier has gone quiet on for weeks are abandoned. A waybill
 *     that never scans is usually a typo, and it would otherwise be polled
 *     forever.
 */
class PollShipmentTracking extends Command
{
    protected $signature = 'shipping:poll
                            {--limit= : Override the configured batch size}
                            {--dry-run : List what would be polled without calling the courier}';

    protected $description = 'Refresh tracking for orders that are still in transit';

    public function handle(ShippingService $shipping): int
    {
        $batch = (int) ($this->option('limit') ?? config('shipping.poll_batch_size', 25));
        $interval = (int) config('shipping.poll_interval_hours', 6);
        $staleDays = (int) config('shipping.stale_after_days', 30);

        $due = Transaction::query()
            ->where('status', 'shipped')
            ->whereNotNull('tracking_number')
            ->whereNotNull('courier_code')

            // Give up on parcels the courier stopped reporting on long ago.
            ->where('shipped_at', '>=', now()->subDays($staleDays))

            // Never checked, or checked long enough ago to be worth asking
            // again. Couriers scan a few times a day, so a short interval buys
            // nothing but spent quota.
            ->where(function ($q) use ($interval) {
                $q->whereNull('tracking_checked_at')
                  ->orWhere('tracking_checked_at', '<=', now()->subHours($interval));
            })

            // Oldest first, so a parcel never starves behind newer orders when
            // the backlog is longer than one batch.
            ->orderByRaw('tracking_checked_at IS NULL DESC')
            ->orderBy('tracking_checked_at')
            ->limit($batch)
            ->get();

        if ($due->isEmpty()) {
            $this->info('Nothing due for tracking.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($due as $order) {
                $this->line("{$order->invoice_number}  {$order->courier_code}  {$order->tracking_number}");
            }

            $this->info("{$due->count()} parcel(s) would be polled.");

            return self::SUCCESS;
        }

        $updated = 0;
        $delivered = 0;
        $failed = 0;

        foreach ($due as $order) {
            try {
                $result = $shipping->track($order->courier_code, $order->tracking_number);
            } catch (\Throwable $e) {
                // One courier refusing shouldn't abandon the rest of the batch.
                Log::error('Tracking poll failed', [
                    'transaction_id' => $order->id,
                    'message'        => $e->getMessage(),
                ]);

                $failed++;
                continue;
            }

            if (!$result) {
                // Normal in the hours after a seller enters a waybill: the
                // courier hasn't registered it yet. Stamp the timestamp anyway
                // so this parcel goes to the back of the queue instead of
                // being retried on every single run.
                $order->update(['tracking_checked_at' => now()]);

                $failed++;
                continue;
            }

            $order->update([
                'tracking_snapshot'   => $result->toArray(),
                'tracking_checked_at' => now(),
            ]);

            $updated++;

            if ($result->status === TrackingResult::DELIVERED) {
                $delivered++;
            }

            // Deliberately NOT auto-completing the order here. Delivery
            // confirmation releases escrow to the seller, and a courier
            // marking something delivered is not the same as a buyer having
            // received it — wrong addresses and false scans both happen.
            // orders:auto-complete already closes the loop after the
            // confirmation window, and that window is the buyer's protection.
        }

        $this->info("Polled {$due->count()}: {$updated} updated, {$delivered} delivered, {$failed} without an answer.");

        return self::SUCCESS;
    }
}