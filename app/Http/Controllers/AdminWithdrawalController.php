<?php

namespace App\Http\Controllers;

use App\Models\Withdrawal;
use App\Services\BalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminWithdrawalController extends Controller
{
    public function __construct(private BalanceService $balances) {}

    // GET /api/admin/withdrawals
    public function index(Request $request)
    {
        if ($deny = $this->denyIfNotAdmin($request)) return $deny;

        $withdrawals = Withdrawal::with('seller:id,name,email')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            // Oldest first: sellers waiting longest get paid first.
            ->orderBy(DB::raw("FIELD(status, 'pending', 'processing', 'completed', 'rejected')"))
            ->orderBy('created_at')
            ->paginate($request->query('per_page', 20));

        return response()->json([
            'success' => true,
            'message' => 'Withdrawals retrieved',
            'data' => collect($withdrawals->items())->map(fn ($w) => $this->format($w)),
            'meta' => [
                'current_page' => $withdrawals->currentPage(),
                'last_page' => $withdrawals->lastPage(),
                'per_page' => $withdrawals->perPage(),
                'total' => $withdrawals->total(),
                'pending_count' => Withdrawal::where('status', 'pending')->count(),
                'pending_amount' => Withdrawal::open()->sum('amount'),
            ],
        ]);
    }

    // GET /api/admin/withdrawals/{id}
    //
    // The only place the full account number is returned. An admin about to
    // make a transfer needs to read it; nothing else does.
    public function show(Request $request, $id)
    {
        if ($deny = $this->denyIfNotAdmin($request)) return $deny;

        $withdrawal = Withdrawal::with('seller:id,name,email')->find($id);

        if (!$withdrawal) {
            return response()->json([
                'success' => false,
                'message' => 'Withdrawal not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal retrieved',
            'data' => [
                ...$this->format($withdrawal),
                'bank_account_number' => $withdrawal->getAttributes()['bank_account_number'],
            ],
        ]);
    }

    // PATCH /api/admin/withdrawals/{id}/processing
    //
    // Claims the request so two admins don't transfer the same money twice.
    public function markProcessing(Request $request, $id)
    {
        if ($deny = $this->denyIfNotAdmin($request)) return $deny;

        $withdrawal = DB::transaction(function () use ($id, $request) {
            $withdrawal = Withdrawal::lockForUpdate()->find($id);

            if (!$withdrawal) {
                abort(404, 'Withdrawal not found');
            }

            if ($withdrawal->status !== 'pending') {
                abort(422, "Only pending withdrawals can be claimed, current status: {$withdrawal->status}");
            }

            $withdrawal->update([
                'status' => 'processing',
                'processed_by' => $request->user()->id,
            ]);

            return $withdrawal;
        });

        return response()->json([
            'success' => true,
            'message' => 'Marked as processing',
            'data' => $this->format($withdrawal->fresh('seller')),
        ]);
    }

    // PATCH /api/admin/withdrawals/{id}/complete
    public function complete(Request $request, $id)
    {
        if ($deny = $this->denyIfNotAdmin($request)) return $deny;

        $validated = $request->validate([
            'transfer_reference' => 'required|string|max:100',
            'note' => 'nullable|string|max:500',
        ], [
            'transfer_reference.required' => 'Enter the bank transfer reference so this can be traced later.',
        ]);

        $withdrawal = DB::transaction(function () use ($id, $request, $validated) {
            $withdrawal = Withdrawal::lockForUpdate()->find($id);

            if (!$withdrawal) {
                abort(404, 'Withdrawal not found');
            }

            if (!in_array($withdrawal->status, ['pending', 'processing'])) {
                abort(422, "This withdrawal is already {$withdrawal->status}.");
            }

            // No balance movement here. The money left the seller's balance
            // when they requested it — this only records that the transfer
            // actually happened.
            $withdrawal->update([
                'status' => 'completed',
                'transfer_reference' => $validated['transfer_reference'],
                'note' => $validated['note'] ?? null,
                'processed_by' => $request->user()->id,
                'processed_at' => now(),
            ]);

            return $withdrawal;
        });

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal completed',
            'data' => $this->format($withdrawal->fresh('seller')),
        ]);
    }

    // PATCH /api/admin/withdrawals/{id}/reject
    //
    // The critical path. The balance was debited at request time, so refusing
    // a withdrawal without crediting it back would simply destroy the
    // seller's money.
    public function reject(Request $request, $id)
    {
        if ($deny = $this->denyIfNotAdmin($request)) return $deny;

        $validated = $request->validate([
            'note' => 'required|string|max:500',
        ], [
            'note.required' => 'Give a reason — the seller sees this.',
        ]);

        $withdrawal = DB::transaction(function () use ($id, $request, $validated) {
            $withdrawal = Withdrawal::lockForUpdate()->find($id);

            if (!$withdrawal) {
                abort(404, 'Withdrawal not found');
            }

            if (!in_array($withdrawal->status, ['pending', 'processing'])) {
                abort(422, "This withdrawal is already {$withdrawal->status}.");
            }

            $withdrawal->update([
                'status' => 'rejected',
                'note' => $validated['note'],
                'processed_by' => $request->user()->id,
                'processed_at' => now(),
            ]);

            // Same transaction as the status change: the refund and the
            // rejection either both land or neither does.
            $this->balances->credit(
                $withdrawal->seller_id,
                (float) $withdrawal->amount,
                null,
                'Withdrawal ' . $withdrawal->reference . ' rejected — refunded'
            );

            return $withdrawal;
        });

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal rejected and the balance refunded',
            'data' => $this->format($withdrawal->fresh('seller')),
        ]);
    }

    private function format(Withdrawal $w): array
    {
        return [
            'id' => $w->id,
            'reference' => $w->reference,
            'amount' => $w->amount,
            'status' => $w->status,
            'bank_name' => $w->bank_name,
            'masked_account_number' => $w->masked_account_number,
            'bank_account_holder' => $w->bank_account_holder,
            'transfer_reference' => $w->transfer_reference,
            'note' => $w->note,
            'processed_at' => $w->processed_at,
            'created_at' => $w->created_at,
            'seller' => $w->seller ? [
                'id' => $w->seller->id,
                'name' => $w->seller->name,
                'email' => $w->seller->email,
            ] : null,
        ];
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