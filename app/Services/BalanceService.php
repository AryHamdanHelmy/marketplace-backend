<?php

namespace App\Services;

use App\Models\BalanceLog;
use App\Models\SellerBalance;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BalanceService
{
    /**
     * Move money into a seller's balance.
     *
     * Wrapped in its own transaction so the balance row, the ledger entry,
     * and nothing else can drift apart. Nesting is safe — Laravel turns an
     * inner transaction into a savepoint.
     */
    public function credit(
        int $sellerId,
        float $amount,
        ?int $transactionId,
        string $note
    ): BalanceLog {
        if ($amount <= 0) {
            throw new RuntimeException('Credit amount must be greater than zero.');
        }

        return DB::transaction(function () use ($sellerId, $amount, $transactionId, $note) {
            $balance = SellerBalance::lockFor($sellerId);

            $balance->balance = (float) $balance->balance + $amount;
            $balance->save();

            return BalanceLog::create([
                'seller_id' => $sellerId,
                'transaction_id' => $transactionId,
                'type' => 'credit',
                'amount' => $amount,
                'note' => $note,
            ]);
        });
    }

    /**
     * Take money out of a seller's balance.
     *
     * Refuses to go negative. The check happens after the row is locked, so
     * two simultaneous withdrawals can't both read the same balance and both
     * pass.
     */
    public function debit(
        int $sellerId,
        float $amount,
        ?int $transactionId,
        string $note
    ): BalanceLog {
        if ($amount <= 0) {
            throw new RuntimeException('Debit amount must be greater than zero.');
        }

        return DB::transaction(function () use ($sellerId, $amount, $transactionId, $note) {
            $balance = SellerBalance::lockFor($sellerId);

            if ((float) $balance->balance < $amount) {
                throw new RuntimeException('Insufficient balance.');
            }

            $balance->balance = (float) $balance->balance - $amount;
            $balance->save();

            return BalanceLog::create([
                'seller_id' => $sellerId,
                'transaction_id' => $transactionId,
                'type' => 'debit',
                'amount' => $amount,
                'note' => $note,
            ]);
        });
    }

    /**
     * Credit a seller for one completed order.
     *
     * Two paths lead here — the buyer confirming receipt and the scheduled
     * job closing an expired window — and they can fire at nearly the same
     * moment. The ledger is checked for an existing credit against this
     * transaction while the balance row is held, which makes a double credit
     * impossible rather than merely unlikely.
     *
     * Returns null when the order was already credited.
     */
    public function creditForCompletedOrder(Transaction $transaction): ?BalanceLog
    {
        return DB::transaction(function () use ($transaction) {
            $balance = SellerBalance::lockFor($transaction->seller_id);

            $alreadyCredited = BalanceLog::where('transaction_id', $transaction->id)
                ->where('type', 'credit')
                ->exists();

            if ($alreadyCredited) {
                return null;
            }

            // The seller receives the order total. When a platform fee is
            // introduced, deduct it here and record the fee as its own
            // ledger entry so the two numbers stay reconcilable.
            $amount = (float) $transaction->total_amount;

            $balance->balance = (float) $balance->balance + $amount;
            $balance->save();

            return BalanceLog::create([
                'seller_id' => $transaction->seller_id,
                'transaction_id' => $transaction->id,
                'type' => 'credit',
                'amount' => $amount,
                'note' => 'Order ' . ($transaction->invoice_number ?? $transaction->id) . ' completed',
            ]);
        });
    }

    public function currentBalance(int $sellerId): float
    {
        $balance = SellerBalance::firstOrCreate(
            ['seller_id' => $sellerId],
            ['balance' => 0]
        );

        return (float) $balance->balance;
    }
}