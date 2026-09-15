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

    private const ON_TIME_HOURS = 48;

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
    //
    // Semuanya dihitung sebagai agregat di database. Versi sebelumnya menarik
    // seluruh riwayat pesanan seller ke memori dengan ->get() tanpa batas apa
    // pun, lalu memfilternya di PHP: seller dengan puluhan ribu pesanan
    // meledakkan memory limit setiap kali membuka dashboard.
    private function storeHealth(int $sellerId): array
    {
        $orders = Transaction::where('seller_id', $sellerId)
            ->where('status', '!=', 'pending')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled")
            ->first();

        $totalOrders = (int) ($orders->total ?? 0);
        $cancelled = (int) ($orders->cancelled ?? 0);

        // Share of orders that were not cancelled
        $fulfilment = $totalOrders > 0
            ? ($totalOrders - $cancelled) / $totalOrders
            : null;

        $onTime = $this->onTimeRatio($sellerId);

        $rating = $this->ratingSummary($sellerId);
        $ratingScore = $rating['average'] !== null ? $rating['average'] / 5 : null;

        $products = Product::where('seller_id', $sellerId)
            ->where('status', 'active')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN stock > 0 THEN 1 ELSE 0 END) as in_stock')
            ->first();

        $activeProducts = (int) ($products->total ?? 0);

        $stockScore = $activeProducts > 0
            ? (int) ($products->in_stock ?? 0) / $activeProducts
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

    /**
     * Porsi pesanan yang dikirim dalam tenggat, dihitung di database.
     *
     * Selisih dua timestamp tidak punya sintaks yang sama di semua mesin, jadi
     * ekspresinya dipilih per driver. Driver yang tidak dikenal mengembalikan
     * null — komponennya lalu dijatuhkan dari skor dan bobotnya dibagi ulang,
     * sama seperti perlakuan untuk toko yang memang belum punya riwayat. Itu
     * lebih jujur daripada menebak dengan rumus yang salah.
     */
    private function onTimeRatio(int $sellerId): ?float
    {
        $hours = self::ON_TIME_HOURS;

        $expression = match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "TIMESTAMPDIFF(HOUR, paid_at, shipped_at) <= {$hours}",
            'sqlite' => "(julianday(shipped_at) - julianday(paid_at)) * 24 <= {$hours}",
            'pgsql' => "EXTRACT(EPOCH FROM (shipped_at - paid_at)) / 3600 <= {$hours}",
            default => null,
        };

        if ($expression === null) {
            return null;
        }

        // Pesanan sebelum kolom fulfilment ada tidak punya shipped_at dan
        // dikeluarkan dari perhitungan, bukan dihitung terlambat.
        $row = Transaction::where('seller_id', $sellerId)
            ->whereIn('status', ['shipped', 'completed'])
            ->whereNotNull('paid_at')
            ->whereNotNull('shipped_at')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN {$expression} THEN 1 ELSE 0 END) as on_time")
            ->first();

        $total = (int) ($row->total ?? 0);

        return $total > 0 ? (int) ($row->on_time ?? 0) / $total : null;
    }

    /**
     * Pendapatan dan jumlah pesanan per hari dalam rentang yang diminta.
     *
     * Pengelompokan dilakukan di database. Komentar versi sebelumnya menyebut
     * "paling banyak 30 bucket, jadi biayanya bisa diabaikan" — yang benar
     * untuk jumlah bucket, tapi bukan untuk jumlah baris: seluruh pesanan
     * dalam rentang itu ditarik ke memori dulu, dan seller ramai bisa punya
     * ribuan pesanan dalam 30 hari.
     *
     * DATE() dikenal MySQL, SQLite, maupun PostgreSQL, jadi tidak perlu
     * percabangan driver di sini. Bucket kosong tetap diisi di PHP supaya
     * hari tanpa penjualan muncul sebagai nol, bukan menghilang dari grafik.
     */
    private function dailyTrend(int $sellerId, Carbon $start, Carbon $end): array
    {
        $rows = Transaction::where('seller_id', $sellerId)
            ->whereIn('status', self::EARNING_STATES)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as revenue')
            ->selectRaw('COUNT(*) as orders')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $buckets = [];
        for ($date = $start->copy(); $date <= $end; $date->addDay()) {
            $buckets[$date->toDateString()] = ['revenue' => 0.0, 'orders' => 0];
        }

        foreach ($rows as $day => $row) {
            $day = (string) $day;

            if (!isset($buckets[$day])) {
                continue;
            }

            $buckets[$day] = [
                'revenue' => (float) $row->revenue,
                'orders' => (int) $row->orders,
            ];
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
        $lowStockQuery = Product::where('seller_id', $sellerId)
            ->where('status', 'active')
            ->where('stock', '<=', self::LOW_STOCK_THRESHOLD);

        return [
            'low_stock_threshold' => self::LOW_STOCK_THRESHOLD,
            'low_stock_count' => (clone $lowStockQuery)->count(),
            'low_stock_products' => (clone $lowStockQuery)
                ->orderBy('stock')
                ->limit(5)
                ->get(['id', 'name', 'stock']),
            'awaiting_processing' => Transaction::where('seller_id', $sellerId)
                ->where('status', 'paid')
                ->count(),
        ];
    }
}