# NEXT SESSION (diperbarui setelah 7D-2)

## Posisi terakhir
- Step 1-6, 7A, 7B, 7C-1, 7C-2/3, 7D-1, 7D-2 ditulis. **Tidak ada satu pun tes yang pernah dijalankan** (sandbox penulis tanpa PHP/MySQL; hanya keseimbangan kurung yang dicek).

## Yang harus Anda lakukan dulu
0. Pasang patch_7d2 di atas zip ini (laravel_accounting_backend_1.zip, sudah berisi 7D-1). Jalankan `php artisan migrate` (migrasi 2026_11_21_140001) dan `php artisan route:clear`.
   Prioritas tes baru: `php artisan test --filter=AssetDisposalTest`.
1. (riwayat) Urutan pasang sebelumnya: zip step7a -> patch_7b -> patch_7c1 -> patch_7c2 -> patch_7d1.
   patch_7d1 menimpa `config/app.php` (hanya menambah satu provider; bila Anda punya perubahan lokal di berkas itu, tambahkan manual
   `App\Modules\FixedAssets\FixedAssetsServiceProvider::class,` setelah PurchasingServiceProvider) dan `PeriodCloseService.php`.
2. `php artisan route:clear`, `php artisan migrate:fresh --seed`, `php artisan test` di MySQL 8 asli.
   Prioritas: FixedAssetTest, BankReconciliationTest, ReconciliationMatcherTest, PeriodCloseTest, BankStatementTest, StockRecalcTest, CashBankTest.
3. Kirim baris yang merah. Titik rawan 7D: composite FK ke `journals`/`accounting_periods`, `whereNotExists` + `whereColumn` di PeriodCloseService,
   `AccountingPeriod::lockForUpdate()`, dan `company_settings` (tes memakai updateOrInsert).

## Celah yang diketahui
- 7D: lihat DECISIONS.md (status `failed` pada checklist adalah keputusan saya yang perlu dikonfirmasi; tidak ada void penyusutan; aset lama sebelum pembukuan belum didukung).
- 7C: ALG-09 1-ke-banyak belum; rekonsiliasi pertama bisa selisih karena baris buku lama.
- Penerimaan Penjualan, Retur Penjualan, Retur Pembelian belum ada kodenya.
- `PeriodService::close()` masih primitif; `assertPostable()` tidak mengunci baris periode (7B).

## Langkah berikutnya (tunggu persetujuan sebelum menulis kode)
Usulan: 7D-3 (saldo menurun, Perubahan Aset, Pindah Aset + Lokasi, migrasi aset lama, pembatalan penyusutan), lalu 7E transaksi berulang, lalu 7F Penerimaan/Retur. Jangan mulai Tahap 8.

## Titik rawan 7D-2
- Kolom turunan `aktif_key` (virtualAs + UNIQUE) dan CHECK pada migrasi baru.
- `test_void_memulihkan_aset...` mengandaikan jurnal pembalik membawa source_type/source_id yang sama (nilai bersih 1-2100 = 0); bila merah, cek JournalService::reverse.
- `test_periode_terkunci...` mengandaikan PeriodException turunan DomainException.
