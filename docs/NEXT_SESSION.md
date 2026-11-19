# NEXT SESSION (diperbarui setelah 7C-2/3)

## Posisi terakhir
- Step 1-6, 7A, 7B, 7C-1, 7C-2, 7C-3 ditulis. **Tidak ada satu pun tes yang pernah dijalankan** (sandbox penulis tanpa PHP/MySQL; hanya keseimbangan kurung yang dicek).
- Spesifikasi ALG-09 diambil dari Analisis_Sistem_Akuntansi (PDF hlm. 38).

## Yang harus Anda lakukan dulu
1. Pasang berurutan: zip step7a -> patch_7b -> patch_7c1 -> patch_7c2 (patch ini menimpa `Reconciliation/routes.php`, `PeriodCloseService.php`, `PeriodCloseTest.php`, docs).
2. `php artisan route:clear`, `php artisan migrate:fresh --seed`, lalu `php artisan test` di MySQL 8 asli.
   Prioritas: ReconciliationMatcherTest, BankReconciliationTest, BankStatementTest, PeriodCloseTest, StockRecalcTest, CashBankTest.
3. Kirim baris yang merah. Titik rawan 7C-2: `whereNotExists` dengan `whereColumn` pada query builder, `QueryException::getCode() === '23000'`,
   urutan `usort` pada ReconciliationMatcher, dan tes yang memakai `PeriodService::close/reopen` serta `periodFor`.

## Celah yang diketahui
- ALG-09 1-ke-banyak belum (butuh migrasi, lihat 7C-4 di roadmap).
- Rekonsiliasi pertama sebuah rekening bisa menunjukkan selisih bila ada baris buku lama yang tidak pernah dicocokkan (DECISIONS.md).
- Penerimaan Penjualan, Retur Penjualan, Retur Pembelian belum ada kodenya.
- `PeriodService::close()` masih primitif; `assertPostable()` tidak mengunci baris periode (lihat 7B).
- Checklist "saldo cocok" membaca seluruh journal_lines perusahaan.

## Langkah berikutnya (tunggu persetujuan sebelum menulis kode)
Usulan: 7D aset tetap + penyusutan ALG-08, lalu 7E transaksi berulang, lalu 7F Penerimaan/Retur. Jangan mulai Tahap 8.
