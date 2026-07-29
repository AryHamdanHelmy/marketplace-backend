<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use App\Models\Product;
use App\Models\ProductImport;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class ProductImportController extends Controller
{
    private const MAX_ROWS = 200;

    // GET /api/products/import/template
    public function downloadTemplate()
    {
        $spreadsheet = new Spreadsheet();

        // --- Sheet 1: form pengisian ---
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Products');

        $headers = ['name', 'category', 'price', 'stock', 'description', 'status', 'image_file'];
        $sheet->fromArray($headers, null, 'A1');

        $sheet->getStyle('A1:G1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1E3A5F'],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // Kolom wajib diberi warna berbeda
        $sheet->getStyle('A1:D1')->getFont()->getColor()->setRGB('FFD966');

        // Baris contoh
        $sheet->fromArray([
            'Logitech M330 Silent',
            'Aksesoris Komputer',
            300000,
            50,
            'Mouse wireless senyap, baterai tahan 2 tahun',
            'active',
            '',
        ], null, 'A2');

        $sheet->getStyle('A2:G2')->getFont()->setItalic(true)
              ->getColor()->setRGB('999999');

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setWidth($col === 'E' ? 45 : 22);
        }

        $sheet->freezePane('A2');

        // --- Sheet 2: daftar kategori, jadi sumber dropdown ---
        $refSheet = $spreadsheet->createSheet();
        $refSheet->setTitle('Categories');

        $categories = ProductCategory::whereNotNull('parent_id')
            ->with('parent:id,name')
            ->orderBy('parent_id')
            ->orderBy('sort_order')
            ->get();

        $refSheet->fromArray(['Kategori', 'Induk'], null, 'A1');
        $refSheet->getStyle('A1:B1')->getFont()->setBold(true);

        foreach ($categories as $i => $cat) {
            $row = $i + 2;
            $refSheet->setCellValue('A' . $row, $cat->name);
            $refSheet->setCellValue('B' . $row, $cat->parent?->name);
        }

        $refSheet->getColumnDimension('A')->setWidth(30);
        $refSheet->getColumnDimension('B')->setWidth(30);

        // Dropdown kategori (kolom B) dan status (kolom F)
        $lastRow = $categories->count() + 1;

        for ($row = 2; $row <= self::MAX_ROWS + 1; $row++) {
            $catValidation = $sheet->getCell('B' . $row)->getDataValidation();
            $catValidation->setType(DataValidation::TYPE_LIST);
            $catValidation->setErrorStyle(DataValidation::STYLE_STOP);
            $catValidation->setAllowBlank(false);
            $catValidation->setShowDropDown(true);
            $catValidation->setShowErrorMessage(true);
            $catValidation->setErrorTitle('Kategori tidak valid');
            $catValidation->setError('Pilih kategori dari daftar yang tersedia.');
            $catValidation->setFormula1("Categories!\$A\$2:\$A\${$lastRow}");

            $statusValidation = $sheet->getCell('F' . $row)->getDataValidation();
            $statusValidation->setType(DataValidation::TYPE_LIST);
            $statusValidation->setErrorStyle(DataValidation::STYLE_STOP);
            $statusValidation->setAllowBlank(true);
            $statusValidation->setShowDropDown(true);
            $statusValidation->setShowErrorMessage(true);
            $statusValidation->setFormula1('"draft,active,inactive"');
        }

        $spreadsheet->setActiveSheetIndex(0);

        $fileName = 'template-produk-' . now()->format('Ymd') . '.xlsx';
        $tempPath = storage_path('app/' . $fileName);

        (new Xlsx($spreadsheet))->save($tempPath);

        return response()->download($tempPath, $fileName)->deleteFileAfterSend(true);
    }
    // POST /api/product-import/preview
    public function preview(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:3072',
        ]);

        $parsed = $this->parseFile($request->file('file'));

        if (isset($parsed['error'])) {
            return response()->json([
                'success' => false,
                'message' => $parsed['error'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'File berhasil dibaca',
            'data'    => [
                'file_name'     => $request->file('file')->getClientOriginalName(),
                'total_rows'    => count($parsed['rows']),
                'valid_count'   => count($parsed['valid']),
                'error_count'   => count($parsed['errors']),
                'rows'          => $parsed['rows'],
                'errors'        => $parsed['errors'],
            ],
        ]);
    }

    // POST /api/product-import
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:3072',
        ]);

        $file   = $request->file('file');
        $parsed = $this->parseFile($file);

        if (isset($parsed['error'])) {
            return response()->json([
                'success' => false,
                'message' => $parsed['error'],
            ], 422);
        }

        // Tidak ada satu pun baris valid — tidak perlu lanjut
        if (empty($parsed['valid'])) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada baris yang valid untuk disimpan',
                'errors'  => $parsed['errors'],
            ], 422);
        }

        $userId = $request->user()->id;

        $import = DB::transaction(function () use ($parsed, $userId, $file) {

            foreach ($parsed['valid'] as $row) {
                Product::create([
                    'seller_id'   => $userId,
                    'category_id' => $row['category_id'],
                    'name'        => $row['name'],
                    'description' => $row['description'],
                    'price'       => $row['price'],
                    'stock'       => $row['stock'],
                    // Produk hasil import selalu draft — belum ada gambar,
                    // seller melengkapi dulu sebelum ditayangkan
                    'status'      => 'draft',
                ]);
            }

            return ProductImport::create([
                'user_id'       => $userId,
                'file_name'     => $file->getClientOriginalName(),
                'total_rows'    => count($parsed['rows']),
                'success_count' => count($parsed['valid']),
                'failed_count'  => count($parsed['errors']),
                'status'        => empty($parsed['errors']) ? 'completed' : 'failed',
                'errors'        => $parsed['errors'] ?: null,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => "{$import->success_count} produk berhasil diimport sebagai draft",
            'data'    => [
                'import_id'     => $import->id,
                'success_count' => $import->success_count,
                'failed_count'  => $import->failed_count,
                'errors'        => $import->errors,
            ],
        ], 201);
    }

    // GET /api/product-import/history
    public function history(Request $request)
    {
        $imports = ProductImport::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate($request->query('per_page', 10));

        return response()->json([
            'success' => true,
            'message' => 'Riwayat import berhasil diambil',
            'data'    => collect($imports->items())->map(fn($i) => [
                'id'            => $i->id,
                'file_name'     => $i->file_name,
                'total_rows'    => $i->total_rows,
                'success_count' => $i->success_count,
                'failed_count'  => $i->failed_count,
                'status'        => $i->status,
                'errors'        => $i->errors,
                'created_at'    => $i->created_at,
            ]),
            'meta' => [
                'current_page' => $imports->currentPage(),
                'last_page'    => $imports->lastPage(),
                'total'        => $imports->total(),
            ],
        ]);
    }
    /**
     * Baca dan validasi file Excel.
     * Mengembalikan: ['rows' => semua baris, 'valid' => siap simpan, 'errors' => pesan per baris]
     */
    private function parseFile($file): array
    {
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getSheetByName('Products') ?? $spreadsheet->getSheet(0);
            $data  = $sheet->toArray(null, true, true, true);
        } catch (\Throwable $e) {
            return ['error' => 'File tidak bisa dibaca. Pastikan formatnya .xlsx'];
        }

        // Buang baris header
        array_shift($data);

        // Peta nama kategori → id, huruf kecil semua supaya tidak case-sensitive
        $categories = ProductCategory::whereNotNull('parent_id')
            ->pluck('id', 'name')
            ->mapWithKeys(fn($id, $name) => [mb_strtolower(trim($name)) => $id]);

        $rows       = [];
        $valid      = [];
        $errors     = [];
        $seenNames  = [];
        $rowNumber  = 1;   // baris 1 adalah header

        foreach ($data as $row) {
            $rowNumber++;

            $name        = trim((string) ($row['A'] ?? ''));
            $categoryRaw = trim((string) ($row['B'] ?? ''));
            $priceRaw    = trim((string) ($row['C'] ?? ''));
            $stockRaw    = trim((string) ($row['D'] ?? ''));
            $description = trim((string) ($row['E'] ?? ''));
            $statusRaw   = mb_strtolower(trim((string) ($row['F'] ?? 'draft')));

            // Lewati baris yang benar-benar kosong
            if ($name === '' && $categoryRaw === '' && $priceRaw === '' && $stockRaw === '') {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                return ['error' => 'Maksimal ' . self::MAX_ROWS . ' baris per file'];
            }

            $rowErrors = [];

            // --- Nama ---
            if ($name === '') {
                $rowErrors[] = 'Nama produk wajib diisi';
            } elseif (mb_strlen($name) > 150) {
                $rowErrors[] = 'Nama produk maksimal 150 karakter';
            } else {
                $key = mb_strtolower($name);
                if (isset($seenNames[$key])) {
                    $rowErrors[] = "Nama produk sama dengan baris {$seenNames[$key]}";
                } else {
                    $seenNames[$key] = $rowNumber;
                }
            }

            // --- Kategori ---
            $categoryId = null;
            if ($categoryRaw === '') {
                $rowErrors[] = 'Kategori wajib diisi';
            } else {
                $categoryId = $categories[mb_strtolower($categoryRaw)] ?? null;
                if (!$categoryId) {
                    $rowErrors[] = "Kategori '{$categoryRaw}' tidak ditemukan";
                }
            }

            // --- Harga ---
            // Excel menyimpan angka sebagai float, jadi perlu dinormalisasi
            $price = null;
            if ($priceRaw === '') {
                $rowErrors[] = 'Harga wajib diisi';
            } elseif (!is_numeric($priceRaw)) {
                $rowErrors[] = 'Harga harus berupa angka';
            } elseif ((float) $priceRaw < 0) {
                $rowErrors[] = 'Harga tidak boleh negatif';
            } else {
                $price = round((float) $priceRaw, 2);
            }

            // --- Stok ---
            $stock = 0;
            if ($stockRaw !== '') {
                if (!is_numeric($stockRaw)) {
                    $rowErrors[] = 'Stok harus berupa angka';
                } elseif ((int) $stockRaw < 0) {
                    $rowErrors[] = 'Stok tidak boleh negatif';
                } else {
                    $stock = (int) $stockRaw;
                }
            }

            // --- Status ---
            if ($statusRaw === ''){
                $statusRaw = 'draft';
            }elseif (!in_array($statusRaw, ['draft', 'active', 'inactive', ''])) {
                $rowErrors[] = "Status '{$statusRaw}' tidak valid";
            }

            $parsedRow = [
                'row'         => $rowNumber,
                'name'        => $name,
                'category'    => $categoryRaw,
                'category_id' => $categoryId,
                'price'       => $price,
                'stock'       => $stock,
                'description' => $description ?: null,
                'is_valid'    => empty($rowErrors),
                'errors'      => $rowErrors,
            ];

            $rows[] = $parsedRow;

            if (empty($rowErrors)) {
                $valid[] = $parsedRow;
            } else {
                foreach ($rowErrors as $msg) {
                    $errors[] = "Baris {$rowNumber}: {$msg}";
                }
            }
        }

        if (empty($rows)) {
            return ['error' => 'File tidak berisi data produk'];
        }

        return [
            'rows'   => $rows,
            'valid'  => $valid,
            'errors' => $errors,
        ];
    }
}