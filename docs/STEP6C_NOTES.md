# STEP 6C - Umur Piutang, Umur Utang, Kartu Stok
Status: DITULIS, BELUM DIJALANKAN, BELUM DI-LINT (PHP tidak ada di sandbox). Test aktual belum ada.

## Temuan sumber data (6C.1)
- Kartu Stok sudah ada (StockReportService/StockReportController: saldo awal, saldo berjalan, paginasi, izin lihat_hpp, test). 6C.4 tidak dibuat ulang.
- Modul Penerimaan Penjualan, Retur Penjualan/Pembelian, Uang Muka TIDAK ada kodenya (hanya tabel). Tabel sales_receipt_allocations belum pernah terisi.
  Pembayaran Pembelian ada (PurchasePaymentPostingHandler): alokasi disimpan, baris alokasi tetap ada saat void.
- Rumus sisa harus ditambah pengurang Retur/Uang Muka/kredit pelanggan saat modul itu dibuat.

## Aturan hitung (ALG-10)
- sisa(T) = total faktur - SUM(alokasi dari pembayaran yang berlaku pada T). Kolom sisa_tagihan TIDAK dipakai (hanya benar untuk hari ini).
- Dokumen berlaku pada T bila tanggal <= T dan (status posted, atau void dengan jurnal pembalik bertanggal > T).
- hari = T - jatuh_tempo (kosong: tanggal faktur). Bucket: belum JT (<=0), 1-30, 31-60, 61-90, >90.
- Mode ringkas = per kontak; rinci = per kontak + daftar faktur. Filter kontak_kode. Urut saldo terbesar. Tanpa paginasi.

## API
GET /api/v1/reports/ar-aging  (fitur umur_piutang: Owner, Admin, Akuntan, Kasir)
GET /api/v1/reports/ap-aging  (fitur umur_utang: Owner, Admin, Akuntan, Purchasing)
Query: per_tanggal (default hari ini), mode=ringkas|rinci, kontak_kode.

## File
BARU: Accounting/Reports/AgingReportService.php, Accounting/Http/Controllers/AgingReportController.php, tests/Feature/Accounting/AgingReportTest.php (14 test)
DIUBAH: Accounting/routes.php (+1 use, +2 route), config/rbac.php (+2 fitur, Kasir +umur_piutang, Purchasing +umur_utang)
WAJIB: php artisan db:seed --class=RbacSeeder untuk perusahaan yang sudah ada.

## Belum dikerjakan
Drill-down faktur ke dokumen sumber, rekonsiliasi total umur vs saldo akun piutang/utang di Buku Besar, ekspor (6E).
