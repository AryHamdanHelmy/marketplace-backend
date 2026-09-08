<?php

namespace App\Http\Controllers;

use App\Models\BalanceLog;
use App\Models\Withdrawal;
use App\Services\BalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SellerBalanceController extends Controller
{
    private const MINIMUM_WITHDRAWAL = 50000;

    public function __construct(private BalanceService $balances) {}

    // GET /api/seller/balance
    public function show(Request $request)
    {
        if ($deny = $this->denyIfNotSeller($request)) return $deny;

        $seller = $request->user();
        $store = $seller->store;

        return response()->json([
            'success' => true,
            'message' => 'Balance retrieved',
            'data' => [
                'balance' => $this->balances->currentBalance($seller->id),
                'minimum_withdrawal' => self::MINIMUM_WITHDRAWAL,
                'pending_withdrawals' => Withdrawal::where('seller_id', $seller->id)
                    ->open()
                    ->sum('amount'),
                'payout_account' => $store && $store->bank_account_number ? [
                    'bank_name' => $store->bank_name,
                    'masked_account_number' => $store->masked_account_number,
                    'account_holder' => $store->bank_account_holder,
                ] : null,
            ],
        ]);
    }

    // GET /api/seller/balance/history
    public function history(Request $request)
    {
        if ($deny = $this->denyIfNotSeller($request)) return $deny;

        $logs = BalanceLog::where('seller_id', $request->user()->id)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->orderByDesc('id')
            ->paginate($request->query('per_page', 20));

        return response()->json([
            'success' => true,
            'message' => 'Balance history retrieved',
            'data' => collect($logs->items())->map(fn ($log) => [
                'id' => $log->id,
                'type' => $log->type,
                'amount' => $log->amount,
                'note' => $log->note,
                'transaction_id' => $log->transaction_id,
                'created_at' => $log->created_at,
            ]),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    // GET /api/seller/withdrawals
    public function index(Request $request)
    {
        if ($deny = $this->denyIfNotSeller($request)) return $deny;

        $withdrawals = Withdrawal::where('seller_id', $request->user()->id)
            ->orderByDesc('id')
            ->paginate($request->query('per_page', 20));

        return response()->json([
            'success' => true,
            'message' => 'Withdrawals retrieved',
            'data' => $withdrawals->items(),
            'meta' => [
                'current_page' => $withdrawals->currentPage(),
                'last_page' => $withdrawals->lastPage(),
                'per_page' => $withdrawals->perPage(),
                'total' => $withdrawals->total(),
            ],
        ]);
    }

    // POST /api/seller/withdrawals
    //
    // The balance is debited the moment the request is made, not when an
    // admin approves it. Otherwise a seller could file several requests
    // against the same money before any of them were processed.
    public function store(Request $request)
    {
        if ($deny = $this->denyIfNotSeller($request)) return $deny;

        $seller = $request->user();
        $store = $seller->store;

        if (!$store || !$store->bank_account_number) {
            return response()->json([
                'success' => false,
                'message' => 'Add a payout account to your shop before withdrawing.',
            ], 422);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:' . self::MINIMUM_WITHDRAWAL,
        ]);

        try {
            $withdrawal = DB::transaction(function () use ($seller, $store, $validated) {

                // Throws when the balance can't cover it. The check happens
                // against a locked row inside the service.
                $this->balances->debit(
                    $seller->id,
                    (float) $validated['amount'],
                    null,
                    'Withdrawal request'
                );

                return Withdrawal::create([
                    'seller_id' => $seller->id,
                    'reference' => Withdrawal::generateReference(),
                    'amount' => $validated['amount'],
                    'status' => 'pending',
                    // Copied, not referenced — the seller may change their
                    // payout account before this transfer is made.
                    'bank_name' => $store->bank_name,
                    'bank_account_number' => $store->bank_account_number,
                    'bank_account_holder' => $store->bank_account_holder,
                ]);
            });
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal requested. It usually clears within 1 business day.',
            'data' => $withdrawal,
        ], 201);
    }

    private function denyIfNotSeller(Request $request)
    {
        if ($request->user()->role !== 'seller') {
            return response()->json([
                'success' => false,
                'message' => 'Only sellers have a shop balance.',
            ], 403);
        }

        return null;
    }
}