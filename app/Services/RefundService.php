<?php

namespace App\Services;

use App\Models\BalanceLog;
use App\Models\PaymentOrder;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Refund manual: uang dikembalikan lewat transfer bank oleh admin.
 *
 * API refund Midtrans sengaja belum dipakai — mayoritas pembayaran masuk lewat
 * bank transfer, yang pengembaliannya memang dikerjakan manusia. Yang
 * dikerjakan kelas ini adalah membuat proses itu punya jejak: status yang
 * terlihat pembeli, catatan siapa mentransfer dengan bukti apa, dan satu entri
 * ledger yang bisa direkonsiliasi.
 *
 * Tiga aturan yang menentukan bentuknya:
 *
 *   1. Saldo seller tidak pernah bertambah karena refund. Pada alur ini seller
 *      memang belum pernah dikredit — kredit baru terjadi saat pesanan
 *      'completed' — jadi tidak ada yang perlu ditarik kembali. Entri ledger
 *      yang dicatat bertipe 'refund' dan sengaja tidak menyentuh
 *      SellerBalance; ia ada untuk rekonsiliasi, bukan untuk menggerakkan
 *      angka.
 *
 *   2. Stok hanya kembali untuk pesanan 'paid'. Pesanan 'shipped' barangnya
 *      sudah keluar gudang, jadi menambah stok karena refund berarti menjual
 *      barang yang secara fisik tidak ada.
 *
 *   3. Pesanan yang sudah 'completed' tidak bisa direfund lewat jalur ini.
 *      Uangnya sudah menjadi saldo seller, dan menariknya kembali adalah
 *      persoalan lain — bisa membuat saldo minus, dan seller mungkin sudah
 *      menariknya. Itu butuh alur tersendiri yang belum ada.
 */
class RefundService
{
    /**
     * Status pesanan yang boleh masuk antrean refund.
     *
     * 'shipped' ikut karena paket bisa hilang atau ditolak di tujuan, dan itu
     * justru kasus refund yang paling sering.
     */
    private const REFUNDABLE = ['paid', 'shipped'];

    /**
     * Tandai pesanan menunggu pengembalian dana.
     *
     * Belum ada uang yang bergerak di sini. Ini hanya memindahkan pesanan ke
     * antrean admin dan memberi pembeli status yang bisa dilihat.
     */
    public function markPending(Transaction $transaction, string $reason): Transaction
    {
        return DB::transaction(function () use ($transaction, $reason) {
            $order = Transaction::with('items')->lockForUpdate()->find($transaction->id);

            if (!$order) {
                throw new RuntimeException('Pesanan tidak ditemukan.');
            }

            if ($order->status === 'refund_pending') {
                throw new RuntimeException('Pesanan ini sudah menunggu pengembalian dana.');
            }

            if (!in_array($order->status, self::REFUNDABLE)) {
                throw new RuntimeException(
                    $order->status === 'completed'
                        ? 'Pesanan yang sudah selesai tidak bisa direfund lewat jalur ini — dananya sudah menjadi saldo seller.'
                        : "Pesanan berstatus {$order->status} tidak bisa direfund."
                );
            }

            // Stok hanya kembali untuk pesanan yang belum dikirim.
            //
            // 'paid': barangnya masih di gudang dan batal terjual sejak
            // keputusan diambil. Menahannya sampai admin sempat mentransfer
            // hanya membuat barang yang tersedia tampak habis.
            //
            // 'shipped': barangnya sudah keluar gudang. Menambah stok di sini
            // akan menjual barang yang secara fisik tidak ada. Kalau paketnya
            // kembali, penambahan stok adalah keputusan seller setelah barang
            // benar-benar diterima kembali — bukan efek samping dari refund.
            if ($order->status === 'paid') {
                $this->restock($order);
            }

            $order->update([
                'status' => 'refund_pending',
                'refund_reason' => $reason,
                'refund_requested_at' => now(),
            ]);

            return $order;
        });
    }

    /**
     * Catat bahwa transfer pengembalian sudah benar-benar dilakukan.
     */
    public function markRefunded(
        Transaction $transaction,
        User $admin,
        string $transferReference,
        ?string $note = null
    ): Transaction {
        return DB::transaction(function () use ($transaction, $admin, $transferReference, $note) {
            $order = Transaction::lockForUpdate()->find($transaction->id);

            if (!$order) {
                throw new RuntimeException('Pesanan tidak ditemukan.');
            }

            if ($order->status === 'refunded') {
                throw new RuntimeException('Pesanan ini sudah direfund.');
            }

            if ($order->status !== 'refund_pending') {
                throw new RuntimeException(
                    "Hanya pesanan yang menunggu pengembalian dana yang bisa ditandai refunded, status saat ini: {$order->status}."
                );
            }

            $order->update([
                'status' => 'refunded',
                'refund_reference' => $transferReference,
                'refunded_at' => now(),
                'refunded_by' => $admin->id,
                'refund_reason' => $note ?? $order->refund_reason,
            ]);

            $order->payment?->update(['status' => 'refunded']);

            // Charge ikut ditandai supaya halaman pembayaran tidak lagi
            // menampilkannya sebagai lunas. isSettled() memang menghitung
            // 'refunded', jadi webhook susulan tetap tidak akan mengubahnya.
            PaymentOrder::where('checkout_group_id', $order->checkout_group_id)
                ->where('status', 'paid')
                ->update(['status' => 'refunded']);

            // Entri ledger tanpa pergerakan saldo. Lihat keterangan kelas.
            BalanceLog::create([
                'seller_id' => $order->seller_id,
                'transaction_id' => $order->id,
                'type' => 'refund',
                'amount' => $order->total_amount,
                'note' => 'Refund ' . ($order->invoice_number ?? $order->id)
                    . ' ke pembeli — ref transfer ' . $transferReference,
            ]);

            return $order;
        });
    }

    /**
     * Kembalikan stok pesanan yang belum dikirim, urut product_id seperti di
     * checkout dan cancel.
     *
     * Urutan yang konsisten itulah yang mencegah dua proses restock saling
     * mengunci.
     */
    private function restock(Transaction $order): void
    {
        foreach ($order->items->sortBy('product_id') as $item) {
            if (!$item->product_id) {
                continue;   // produknya sudah dihapus, tidak ada yang dikembalikan
            }

            Product::where('id', $item->product_id)->increment('stock', $item->quantity);
        }
    }
}
