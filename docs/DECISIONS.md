# DECISIONS

## 7B
1. Kebijakan Draft saat tutup bulan = `company_settings.kebijakan_draft_akhir_bulan` (default `warn`). `warn` -> status
   warning, butuh `konfirmasi: true`; `block` -> failed. Draft dan Pending Approval dihitung sama.
2. Jurnal penutup (Keputusan A): diterbitkan saat periode ke-12 masih Open, satu transaksi dengan penutupan periode 12 dan
   tahun buku. `JournalService::assertPostable` tidak dilemahkan. Konsekuensi: periode ke-12 tidak bisa ditutup lewat
   `POST periods/{id}/close` (kode `use_fiscal_year_close`).
3. Laba rugi ditutup langsung ke Laba Ditahan (sesuai Tahap 4). Akun 3-9000 Ikhtisar Laba Rugi tidak dipakai.
4. Periode wajib ditutup berurutan, termasuk lintas tahun buku; periode sebelum tanggal mulai pembukuan dikecualikan.
5. Hasil penutupan dicatat di period_close_logs: `closed` (berhasil), `failed`/`warning` (ditolak). GET close-check tidak mencatat.
6. Buka kembali tahun = hak `buka_kunci_periode` (Owner), alasan wajib; tahun yang lebih baru tidak boleh Closed.
   Hanya periode ke-12 dan tahun yang dibuka; periode 1-11 tetap Closed (buka manual lewat `periods/{id}/reopen`).
   Jurnal penutup dibalik lewat `JournalService::reverseClosing()` (pembalik ditandai is_closing).
7. Tahun buku berikutnya dibuat otomatis saat tutup tahun bila belum ada (tanpa itu semua posting terhenti). Keputusan
   saya sendiri, bukan permintaan eksplisit; mudah dilepas di FiscalYearCloseService::close().
8. Saldo akun laba rugi yang ditutup dihitung dari kolom operasional account_balances (tanpa kolom penutup).
9. Item "na": transaksi berulang, penyusutan aset, rekonsiliasi bank (modulnya belum ada). Draft dihitung dari tabel di
   config/period_close.php; tabel/kolom yang belum ada dilewati otomatis.
10. Hitung ulang HPP tertunda hanya menghalangi bila titik awalnya <= akhir periode (`pendingCountUntil`).

## 7C (Rekonsiliasi Bank)
- Arah mutasi memakai sisi bank: kredit koran = uang masuk = baris jurnal bank DEBIT; debit koran = uang keluar = baris jurnal bank KREDIT. (Asumsi; analisis tidak menetapkan.)
- Rekening koran tidak punya update: salah = hapus lalu buat ulang. Hapus ditolak bila ada baris dicocokkan atau rekonsiliasi `selesai` menutup periodenya.
- Rekening koran wajib seimbang: saldo awal + kredit - debit = saldo akhir. Periode tidak boleh tumpang tindih pada rekening yang sama.
- Rekening bertanda is_kas ditolak untuk rekening koran. Saldo per baris (kolom saldo) disimpan apa adanya, tidak diverifikasi berurutan (urutan CSV bisa terbalik).
- CSV: header wajib, pemisah otomatis , ; tab. '1.000' dibaca seribu (konvensi Indonesia). Maks 5000 baris dan 2 MB per berkas.
- Admin memegang kas_bank 'V' sehingga bisa melihat rekening koran; analisis menyebut hanya Akuntan. Diikuti matriks RBAC apa adanya.
- journalsReconciled() (void) menolak void bila baris jurnal sudah punya reconciliation_item, termasuk sesi `berjalan`; lebih ketat dari analisis, dipertahankan.

## 7C-2 / 7C-3 (Rekonsiliasi Bank, ALG-09)
- Sumber ALG-09: Analisis_Sistem_Akuntansi hlm. 38. Skor = 0.5 + 0.3*(1 - hari/toleransi) + 0.2*tokenOverlap; kandidat: arah sama, nominal sama persis, selisih hari <= toleransi (default 3, 0-30 lewat parameter). >= 0.8 otomatis, 0.5-<0.8 saran (tidak disimpan).
- Pemilihan satu-ke-satu: semua pasangan diurutkan skor turun (seri: selisih hari kecil, id kecil), diambil serakah. Dipilih agar "dua penerimaan nominal sama beda tanggal tidak tertukar" tidak bergantung urutan baris koran.
- ASUMSI: tokenOverlap = |token referensi buku yang ada di deskripsi koran| / |token referensi buku|. Referensi buku = nomor jurnal + memo jurnal + memo baris. Analisis tidak merinci rumusnya.
- BELUM: kasus 1-ke-banyak (subset maks 5). Skema `reconciliation_items` punya unique `uq_ri_stmt` (satu baris koran = satu item). Perlu migrasi bila diminta (7C-4).
- Satu sesi = satu rekening koran (rekening + periode persis sama); tidak ada kolom bank_statement_id di tabel sesi, sehingga dicari lewat rekening + periode. Unique `uq_recon_period` membatasi satu sesi per rekening+periode_selesai.
- selisih = saldo_koran - (saldo_buku - transaksi_beredar). saldo_buku = seluruh baris jurnal akun bank <= periode_selesai (termasuk saldo awal dan pasangan void). transaksi_beredar = baris jurnal bank belum dicocokkan, bukan saldo awal, bukan jurnal yang dibalik/pembalik.
- Baris koran belum cocok tidak dianggap "dijelaskan". Selesai butuh selisih 0 DAN nol baris koran belum cocok.
- KETERBATASAN DIKETAHUI: baris buku lama (sebelum periode koran pertama) yang tidak pernah dicocokkan ikut menjadi "beredar" dan memunculkan selisih pada rekonsiliasi pertama sebuah rekening. Belum ada fitur "tandai sudah bersih sebelum tanggal X". Dicatat sebagai pekerjaan lanjutan.
- Cocok manual mewajibkan arah dan nominal sama (tidak ada pencocokan selisih nominal).
- Transaksi baru: kredit koran -> Penerimaan Kas & Bank, debit koran -> Pembayaran Kas & Bank; tanpa pajak; diposting langsung lewat orkestrator (ALG-02). Bila kena aturan approval, seluruh proses dibatalkan (`approval_required`), sebab pencocokan butuh baris jurnal yang sudah terposting. Status baris koran dicatat `manual`; tipe item `transaksi_baru`. Enum `status_cocok` tidak punya nilai 'transaksi_baru'.
- Buka kembali: wajib alasan; ditolak bila ada periode akuntansi Closed yang beririsan. Status jadi `dibuka_kembali` dan boleh diubah lagi seperti `berjalan`.
- Checklist Akhir Bulan: item `rekonsiliasi_bank` = warning (bukan failed), sesuai ALG-11 ("peringatan"). Hanya rekening yang punya rekening koran beririsan dengan periode yang diperiksa.
- RBAC: Akuntan/Owner penuh, Admin hanya lihat, Kasir tidak punya akses (matriks apa adanya).

## 7D-1 (Aset Tetap, ALG-08)
- Penyusutan hanya garis lurus. Bulanan = (harga - residu) / umur, dipotong ke 4 desimal (bcdiv); bulan terakhir = sisa sehingga nilai buku akhir = residu. Dijaga agar akumulasi tidak melewati harga - residu.
- Awal penyusutan memakai kolom yang SUDAH ada `company_settings.aturan_awal_penyusutan` (default tabel `acquisition_month`, bukan `next_month` seperti usulan saya). Hasilnya disimpan di `fixed_assets.tanggal_mulai_susut` (selalu tanggal 1) saat aset dibuat, jadi mengubah Preferensi tidak mengubah aset lama.
- Jadwal harus berurutan: bulan ke-n hanya bisa diproses bila n-1 bulan sebelumnya sudah ada; kalau tidak ditolak `previous_period_not_run` dan tidak ada yang tertulis. Konsekuensinya penyusutan tidak bisa dikejar sesudah periode terkunci, maka checklist Akhir Bulan memberi status `failed` (memblokir) untuk aset yang belum disusutkan. ALG-11 hanya menyebut gagal untuk hitung ulang HPP dan neraca saldo; ini penyesuaian saya, mohon dikonfirmasi.
- Satu jurnal per (periode, kategori), tanggal = akhir periode, tipe `auto`, source_type `depreciation_run`, source_id = `depreciation_runs.id`. Dr akun beban, Cr akun akumulasi (dari Kategori Aset). Run dengan total 0 tidak membuat jurnal.
- Idempotensi: unique `uq_dep_sched` (aset, jenis, periode) dan `uq_dep_run` (periode, kategori) ditambah pelewatan aset yang sudah punya jadwal. Seluruh periode dalam satu transaksi dengan kunci baris periode.
- Perolehan aset dicatat manual tanpa jurnal perolehan (usulan 3 diterima). Aset dengan tanggal perolehan sebelum tanggal mulai pembukuan ditolak (`before_books_start`), karena butuh akumulasi awal.
- Field keuangan aset (kategori, tanggal, harga, residu, umur) terkunci setelah ada jadwal; hanya nama dan keterangan yang bisa diubah. Akun Kategori Aset terkunci setelah dipakai aset.
- Hak: kategori memakai fitur `kategori_aset`, aset `aset_tetap` (Admin = C), menjalankan penyusutan memakai hak khusus `tutup_periode` (Owner, Akuntan), sama dengan Proses Akhir Bulan.
- Kolom `jenis` ('fiskal') disiapkan di jadwal tetapi belum dipakai.
- Belum ada pembatalan penyusutan. Jurnal penyusutan yang di-void lewat Jurnal Umum tidak membatalkan baris jadwalnya.
- Penyusutan otomatis lewat scheduler tidak dibuat (usulan 2 diterima: manual per periode).

## 7D-2 Disposisi Aset (keputusan yang saya ambil, mohon dikonfirmasi)
1. Penyusutan wajib lengkap SAMPAI BULAN SEBELUM bulan pelepasan (bulan pelepasan sendiri tidak disusutkan). Bila periode bulan pelepasan
   sudah terlanjur disusutkan, akumulasinya ikut dihitung. Belum lengkap = `depreciation_not_run`; ada jadwal sesudah bulan pelepasan = `depreciation_after_disposal`.
2. Laba dan rugi memakai satu akun sistem `laba_rugi_pelepasan_aset` (7-1000): Kredit bila laba, Debit bila rugi (sesuai Tahap 4).
3. Nilai pelepasan 0 = penghapusan (write-off), tanpa akun penerimaan; seluruh nilai buku menjadi rugi.
4. Akun penerimaan harus bertipe `asset` (kas/bank/piutang), aktif, bukan akun induk.
5. Angka (akumulasi, nilai buku, laba/rugi) dihitung ulang saat POSTING di dalam transaksi dengan aset terkunci, bukan saat draf.
6. Void memulihkan aset ke `aktif`, tetapi DITOLAK bila penyusutan bulan setelah bulan pelepasan sudah dijalankan (jadwal akan bolong
   karena belum ada pembatalan penyusutan) atau jurnal penerimaan sudah direkonsiliasi bank.
7. Satu disposisi aktif per aset, dijaga UNIQUE pada kolom turunan `aktif_key` (NULL bila void).
8. Jurnal disposisi tidak melewati gate approval: modul Approval tidak ada di zip ini. Aturan approval bawaan `asset_disposal` (Tahap 7) berlaku begitu modul itu terpasang.
