# STEP 6D - Dashboard
Status: DITULIS, BELUM DIJALANKAN, BELUM DI-LINT (PHP tidak ada di sandbox). Test aktual belum ada.

## API
GET /api/v1/dashboard (fitur dashboard/view, dimiliki semua role) -> {tanggal, widgets:{...}}. Hanya widget yang diizinkan yang dikirim.

## Widget -> izin (berdasarkan IZIN, bukan nama role, jadi role kustom ikut benar)
| Widget | Izin | Isi |
|---|---|---|
| kas_bank, laba_bulan_ini, penjualan_vs_pembelian (12 bln) | laporan_keuangan | saldo kas/bank; laba bersih bulan ini vs bulan lalu; grafik |
| piutang / utang | umur_piutang / umur_utang | total + bucket (AgingReportService, per hari ini) |
| penjualan_hari_ini, faktur_belum_dibayar | faktur_penjualan | jumlah, total, sisa, jumlah lewat jatuh tempo |
| tagihan_utang | faktur_pembelian | lewat jatuh tempo; jatuh tempo 7 hari |
| stok_menipis | barang_stok_minimum | jumlah + 5 teratas, hanya qty (tanpa nilai/HPP) |
| periode_belum_ditutup, draft_tersisa | hak khusus tutup_periode | periode Open yang sudah lewat; jumlah draft per tabel |
| menunggu_persetujuan | semua | ApprovalService::inbox(waiting), maks 100 |
Hasil per role (dari config/rbac.php): Owner/Akuntan semua; Kasir penjualan+piutang; Gudang stok menipis; Purchasing utang+tagihan+stok. Admin tidak punya tutup_periode.

## Cache (6D.5)
DashboardCache: kunci dash:{company_id}:{versi}:{blok}:{suffix}, TTL 300 dtk. Versi diganti (bump) di: JournalService::post (semua jurnal, termasuk pembalik/penutup),
PostingOrchestrator::outbox (post/void dokumen), PeriodService::close/reopen. Bump dilakukan langsung dan sekali lagi lewat DB::afterCommit.
Tidak di-cache: menunggu_persetujuan (milik user). Draft dibuat/diubah tidak mem-bump: angka draft_tersisa bisa tertinggal hingga 5 menit.

## Belum ada karena modulnya belum ada
Pengiriman tertunda (Gudang), PO berjalan (Purchasing), rekonsiliasi tertunda (Akuntan), Terlaris. Tambahkan saat modulnya dibuat.

## File
BARU: Accounting/Reports/{DashboardService,DashboardCache}.php, Accounting/Http/Controllers/DashboardController.php, tests/Feature/Accounting/DashboardTest.php (13 test)
DIUBAH: Accounting/routes.php (+1 use, +1 route), Accounting/Ledger/JournalService.php (+1 baris bump), Accounting/Posting/PostingOrchestrator.php (+1 baris bump di outbox()), Accounting/Periods/PeriodService.php (+2 baris bump)
Tidak ada migration dan tidak ada perubahan RBAC baru (memakai izin 6B/6C).
