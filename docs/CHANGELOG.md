# CHANGELOG

## 7C-2 dan 7C-3 - Sesi rekonsiliasi bank, ALG-09, checklist Akhir Bulan (ditulis, belum terverifikasi tes)
Baru:
- `CashBank/Models/BankReconciliation.php`, `ReconciliationItem.php`
- `CashBank/Reconciliation/ReconciliationMatcher.php` (ALG-09 murni), `BankReconciliationService.php`
- `CashBank/Http/Controllers/BankReconciliationController.php`
- Rute `api/v1/bank-reconciliations` (fitur `rekonsiliasi_bank`): GET daftar, POST buat dari `bank_statement_id`, GET/DELETE `{id}`,
  GET `{id}/suggestions`, POST `{id}/auto-match`, POST `{id}/matches`, DELETE `{id}/matches/{itemId}`, POST `{id}/transactions`,
  POST `{id}/complete`, POST `{id}/reopen`
- Tes: `ReconciliationMatcherTest` (murni), `BankReconciliationTest`
Diubah:
- `CashBank/Reconciliation/routes.php`: memuat rute koran (tetap) + rute rekonsiliasi di satu berkas.
- `Accounting/Periods/Close/PeriodCloseService.php`: item `rekonsiliasi_bank` tidak lagi "na": `warning` bila ada rekening koran di periode yang belum punya rekonsiliasi `selesai`.
- `tests/Feature/Accounting/PeriodCloseTest.php`: asersi lama "na" untuk rekonsiliasi diganti "ok"; tes baru rekonsiliasi.
Skema: tidak ada migrasi baru.

## 7B - Akhir Bulan dan Tutup Tahun (ALG-11)
Baru:
- `Accounting/Periods/Close/PeriodCloseService.php`, `FiscalYearCloseService.php`, `PeriodCloseException.php`
- `Accounting/Http/Controllers/PeriodCloseController.php`, `config/period_close.php`
- `tests/Feature/Accounting/PeriodCloseTest.php`
- Endpoint (prefix api/v1): `GET periods/{id}/close-check`, `GET periods/{id}/close-logs`, `POST periods/{id}/close`,
  `GET fiscal-years/{id}/close-preview`, `POST fiscal-years/{id}/close`, `POST fiscal-years/{id}/reopen`
Diubah:
- `Ledger/JournalDraft.php`: parameter `closing` (default false).
- `Ledger/JournalService.php`: `post()` menandai jurnal penutup/pembaliknya (`is_closing`, kolom saldo *_penutup);
  metode baru `reverseClosing()`. `reverse()` tetap menolak jurnal penutup.
- `Stock/Recalc/StockRecalcService.php`: `pendingCountUntil()`; `pendingCount()` tidak berubah.
- `Accounting/routes.php`: enam rute di atas.
Skema: tidak ada migrasi baru (`period_close_logs` dan `company_settings.kebijakan_draft_akhir_bulan` sudah ada).

## 7A - Hitung ulang HPP transaksi mundur (lihat zip 7A)

## 7C-1 Rekening Koran (ditulis, belum terverifikasi tes)
- BARU: Models BankStatement/BankStatementLine; Reconciliation/BankStatementCsvParser, BankStatementService; BankStatementController; Reconciliation/routes.php (5 rute `bank-statements`, fitur `rekening_koran`).
- DIUBAH: CashBankServiceProvider (+ load routes Reconciliation).
- Tes: BankStatementCsvParserTest (murni), BankStatementTest. Tidak ada migrasi baru (tabel sudah ada sejak skema awal).
