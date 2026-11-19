# STEP 6B — CATATAN 6B.1 (sumber data laporan keuangan)

Tanggal: 2026-11-18. Dibaca: migration account_balances/journal_lines/journals/accounts/fiscal_years/accounting_periods,
AccountBalanceService, AccountHistoryService (awal), LedgerReportController, routes Accounting (bagian laporan), config/rbac.php, config/system_accounts.php.
Sumber PDF: Tahap 4 bag. 7 (periode/tutup tahun), Tahap 8 bag. 9 (account_balances), Tahap 7 P6 (laporan), ALG-10/11.

## Yang SUDAH ada (jangan dibuat ulang)
- account_balances (company, account, period): debit, kredit, debit_penutup, kredit_penutup. Ditulis hanya oleh AccountBalanceService dalam transaksi posting; ada rebuild() dan discrepancies().
- AccountBalanceService::trialBalance(period, includeClosing) = Neraca Saldo (route GET reports/trial-balance).
- AccountHistoryService::history() = Histori Akun dengan saldo berjalan, keyset (route GET reports/account-history).
- journals tidak punya kolom status; immutable (trigger). Void = jurnal pembalik (tipe reversal, reversed_of_id); keduanya ikut dijumlah => net nol.
- Money (bcmath, 4 desimal), accounts.tipe: asset, liability, equity, revenue, cogs, expense, other_income, other_expense.
- Akun sistem: laba_ditahan, laba_tahun_berjalan, ikhtisar_laba_rugi (tipe equity).

## Aturan perhitungan untuk 6B (disepakati dari kode + PDF, belum diimplementasi)
- Akun neraca: saldo kumulatif semua periode <= tanggal laporan, TERMASUK kolom *_penutup.
- Akun laba rugi: reset per tahun buku, TANPA kolom *_penutup (selaras trialBalance dan Histori Akun).
- Neraca: Aset = Kewajiban + Ekuitas + Laba berjalan. Laba berjalan = -(saldo bersih semua akun laba rugi s.d. tanggal laporan, termasuk kolom penutup),
  sehingga tahun lama yang belum ditutup tetap seimbang; setelah tutup tahun nilainya pindah ke Laba Ditahan lewat jurnal penutup.
- Laba Rugi: Pendapatan - HPP = Laba Kotor; - Beban = Laba Usaha; + Pendapatan Lain - Beban Lain = Laba Bersih. Jurnal penutup diabaikan.
- Saldo akun ditampilkan sesuai saldo_normal (kredit normal dibalik tandanya).
- Perbandingan periode: dua rentang yang dihitung terpisah dengan kode yang sama; selisih nominal dan persen (pembagi 0 => null).

## Temuan
- IZIN: daftar_laporan level 'V' dimiliki Owner, Admin, Akuntan, Kasir, Gudang, Purchasing. Route trial-balance saat ini hanya feature:daftar_laporan,view,
  jadi Kasir/Gudang/Purchasing bisa membuka Neraca Saldo. PDF Tahap 2: laporan keuangan penuh hanya Owner, Admin, Akuntan.
  Perlu fitur/izin terpisah untuk laporan keuangan (usulan: fitur 'laporan_keuangan' di modul laporan) dan route trial-balance dipindah ke sana.
  Konsekuensi: perusahaan yang sudah ada perlu menjalankan ulang RbacSeeder.
- Belum ada tabel report_definitions maupun Service Laporan Neraca/Laba Rugi/Buku Besar.

## Rencana substep
6B.2 Neraca | 6B.3 Laba Rugi | 6B.4 Buku Besar | 6B.5 Perbandingan periode + mode ringkas/rinci | 6B.6 API + izin | 6B.7 Test + dok + ZIP
Di luar 6B (belum diminta): Arus Kas, drill-down ke dokumen sumber (sudah ada lewat Histori Akun), ekspor (6E).
