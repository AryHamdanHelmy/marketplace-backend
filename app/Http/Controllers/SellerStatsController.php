<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SellerStatsController extends Controller
{
    // Orders in these states represent money the seller has actually earned.
    private const EARNING_STATES = ['paid', 'shipped', 'completed'];

    private const LOW_STOCK_THRESHOLD = 5;

    // GET /api/seller/stats?range=30
    public function index(Request $request)
    {
        $sellerId = $request->user()->id;

        if (!in_array($request->user()->role, ['seller', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only sellers can view shop statistics.',
            ], 403);
        }

        $range = (int) $request->query('range', 30);
        if (!in_array($range, [1, 7, 30])) {
            $range = 30;
        }

        $end = Carbon::now()->endOfDay();
        $start = Carbon::now()->subDays($range - 1)->startOfDay();

        // The equivalent window immediately before this one, for comparison
        $previousEnd = $start->copy()->subSecond();
        $previousStart = $start->copy()->subDays($range);

        $current = $this->periodTotals($sellerId, $start, $end);
        $previous = $this->periodTotals($sellerId, $previousStart, $previousEnd);

        return response()->json([
            'success' => true,
            'message' => 'Shop statistics retrieved',
            'data' => [
                'range_days' => $range,
                'sales' => [
                    'total' => round($current['revenue'], 2),
                    'previous' => round($previous['revenue'], 2),
                    'change_percent' => $this->percentChange($previous['revenue'], $current['revenue']),
                ],
                'orders' => [
                    'total' => $current['orders'],
                    'previous' => $previous['orders'],
                    'change_percent' => $this->percentChange($previous['orders'], $current['orders']),
                ],
                'rating' => $this->ratingSummary($sellerId),
                'store_health' => $this->storeHealth($sellerId),
                'trend' => $this->dailyTrend($sellerId, $start, $end),
                'alerts' => $this->alerts($sellerId),
            ],
        ]);
    }

    // Revenue and order count for one window.
    private function periodTotals(int $sellerId, Carbon $start, Carbon $end): array
    {
        $row = Transaction::where('seller_id', $sellerId)
            ->whereIn('status', self::EARNING_STATES)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(total_amount), 0) as revenue, COUNT(*) as orders')
            ->first();

        return [
            'revenue' => (float) ($row->revenue ?? 0),
            'orders' => (int) ($row->orders ?? 0),
        ];
    }

    private function percentChange(float $before, float $after): ?float
    {
        // No baseline means no meaningful percentage. Null tells the frontend
        // to hide the comparison rather than print a misleading "+100%".
        if ($before <= 0) {
            return null;
        }

        return round((($after - $before) / $before) * 100, 1);
    }

    private function ratingSummary(int $sellerId): array
    {
        $row = DB::table('reviews')
            ->join('products', 'products.id', '=', 'reviews.product_id')
            ->where('products.seller_id', $sellerId)
            ->selectRaw('AVG(reviews.rating) as average, COUNT(*) as total')
            ->first();

        return [
            'average' => $row->total > 0 ? round((float) $row->average, 2) : null,
            'review_count' => (int) ($row->total ?? 0),
        ];
    }

    // A single number is only useful if the seller can see what drags it down,
    // so the breakdown ships alongside the score.
    private function storeHealth(int $sellerId): array
    {
        $orders = Transaction::where('seller_id', $sellerId)
            ->whereNotIn('status', ['pending'])
            ->get(['status', 'paid_at', 'updated_at']);

        $totalOrders = $orders->count();

        // Share of orders that were not cancelled
        $fulfilment = $totalOrders > 0
            ? $orders->where('status', '!=', 'cancelled')->count() / $totalOrders
            : null;

        // Share of paid orders that moved past "paid" within 48 hours
        $paidOrders = $orders->whereIn('status', ['shipped', 'completed'])
            ->filter(fn ($o) => $o->paid_at !== null);

        $onTime = $paidOrders->count() > 0
            ? $paidOrders->filter(function ($order) {
                return Carbon::parse($order->paid_at)
                    ->diffInHours(Carbon::parse($order->updated_at)) <= 48;
            })->count() / $paidOrders->count()
            : null;

        $rating = $this->ratingSummary($sellerId);
        $ratingScore = $rating['average'] !== null ? $rating['average'] / 5 : null;

        $activeProducts = Product::where('seller_id', $sellerId)
            ->where('status', 'active')
            ->get(['stock']);

        $stockScore = $activeProducts->count() > 0
            ? $activeProducts->where('stock', '>', 0)->count() / $activeProducts->count()
            : null;

        $components = [
            'on_time_processing' => ['weight' => 0.35, 'value' => $onTime],
            'order_fulfilment' => ['weight' => 0.25, 'value' => $fulfilment],
            'buyer_rating' => ['weight' => 0.25, 'value' => $ratingScore],
            'stock_availability' => ['weight' => 0.15, 'value' => $stockScore],
        ];

        // Components without data are dropped and the remaining weights are
        // rescaled, so a brand new shop isn't punished for having no history.
        $usedWeight = 0;
        $weighted = 0;
        $breakdown = [];

        foreach ($components as $key => $component) {
            if ($component['value'] === null) {
                $breakdown[$key] = null;
                continue;
            }
            $usedWeight += $component['weight'];
            $weighted += $component['weight'] * $component['value'];
            $breakdown[$key] = round($component['value'] * 100, 1);
        }

        return [
            'score' => $usedWeight > 0 ? round(($weighted / $usedWeight) * 100, 1) : null,
            'breakdown' => $breakdown,
        ];
    }

    // Grouping happens in PHP rather than SQL so the query stays portable
    // across MySQL and SQLite. At most 30 buckets, so cost is negligible.
    private function dailyTrend(int $sellerId, Carbon $start, Carbon $end): array
    {
        $transactions = Transaction::where('seller_id', $sellerId)
            ->whereIn('status', self::EARNING_STATES)
            ->whereBetween('created_at', [$start, $end])
            ->get(['created_at', 'total_amount']);

        $buckets = [];
        for ($date = $start->copy(); $date <= $end; $date->addDay()) {
            $buckets[$date->toDateString()] = ['revenue' => 0.0, 'orders' => 0];
        }

        foreach ($transactions as $transaction) {
            $key = Carbon::parse($transaction->created_at)->toDateString();
            if (!isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['revenue'] += (float) $transaction->total_amount;
            $buckets[$key]['orders'] += 1;
        }

        return collect($buckets)
            ->map(fn ($values, $date) => [
                'date' => $date,
                'revenue' => round($values['revenue'], 2),
                'orders' => $values['orders'],
            ])
            ->values()
            ->all();
    }

    private function alerts(int $sellerId): array
    {
        $lowStock = Product::where('seller_id', $sellerId)
            ->where('status', 'active')
            ->where('stock', '<=', self::LOW_STOCK_THRESHOLD)
            ->orderBy('stock')
            ->limit(5)
            ->get(['id', 'name', 'stock']);

        $awaitingProcessing = Transaction::where('seller_id', $sellerId)
            ->where('status', 'paid')
            ->count();

        return [
            'low_stock_threshold' => self::LOW_STOCK_THRESHOLD,
            'low_stock_count' => Product::where('seller_id', $sellerId)
                ->where('status', 'active')
                ->where('stock', '<=', self::LOW_STOCK_THRESHOLD)
                ->count(),
            'low_stock_products' => $lowStock,
            'awaiting_processing' => $awaitingProcessing,
        ];
    }
}