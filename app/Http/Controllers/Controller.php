<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Ukuran halaman yang boleh dipercaya dari query string.
     *
     * Sebelumnya setiap endpoint meneruskan ?per_page apa adanya ke
     * paginate(). Satu request dengan ?per_page=1000000 menarik seluruh tabel
     * ke memori beserta relasi yang di-eager load — penolakan layanan dengan
     * satu baris URL, di sebelas endpoint sekaligus.
     *
     * Nilai non-numerik, nol, dan negatif jatuh ke default, bukan ke error:
     * ini parameter tampilan, dan menolak seluruh request karena angkanya
     * aneh tidak menolong siapa pun.
     */
    protected function perPage(Request $request, int $default = 10, int $max = 100): int
    {
        $requested = $request->query('per_page');

        if (!is_numeric($requested)) {
            return $default;
        }

        $requested = (int) $requested;

        if ($requested < 1) {
            return $default;
        }

        return min($requested, $max);
    }
}
