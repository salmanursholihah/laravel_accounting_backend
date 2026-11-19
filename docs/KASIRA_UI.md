# Antarmuka Kasira di dalam backend Laravel

Prototipe HTML dipindahkan ke backend ini secara bertahap. Tidak ada build tool (tidak ada Vite/npm):
aset statis di `public/kasira/`, satu halaman Blade di `resources/views/kasira/app.blade.php`.

## Cara menjalankan (lokal)
1. `.env`: MySQL 8.0.16+ (SQLite/MariaDB tidak didukung migrasi), `APP_URL=http://127.0.0.1:8000`.
   `SANCTUM_STATEFUL_DOMAINS` harus memuat host:port yang dipakai membuka halaman (default sudah memuat `localhost` dan `127.0.0.1:8000`).
2. `php artisan migrate && php artisan db:seed --class=DemoSeeder`
3. `php artisan serve`, buka `http://127.0.0.1:8000/app`, masuk `budi@contoh.id` / `rahasia123`.

## Struktur
| File | Isi |
|---|---|
| `public/kasira/css/kasira.css` | Seluruh CSS prototipe (sudah termasuk gaya layar yang belum dipindahkan) |
| `public/kasira/js/features.js` | Katalog 11 modul dan 88 fitur (apa adanya dari prototipe) |
| `public/kasira/js/api.js` | Klien API: cookie sesi + CSRF + header `X-Company-Id`, galat 401/419/422 |
| `public/kasira/js/core.js` | Pembantu global yang namanya sama dengan prototipe (`$`, `rp`, `pg`, `toast`, `dlg`, `head`, ...) |
| `public/kasira/js/dashboard.js` | Beranda dari `GET /api/v1/dashboard` (KPI, grafik 12 bulan, perlu perhatian, umur piutang/utang, stok menipis) |
| `public/kasira/js/screens/master.js` | Mesin master generik (tabel, cari, filter status, panel samping, simpan, aktif/nonaktif, hapus) + konfigurasi 9 master (Pelanggan, Pemasok, Syarat Pembayaran, Barang & Jasa, Satuan, Merek, Gudang, Akun Perkiraan, Pajak) |
| `public/kasira/js/screens/invoice.js` | Mesin dokumen: Faktur Penjualan dan Faktur Pembelian (daftar, form, simpan draft, posting, void, hapus draft) |
| `public/kasira/js/screens/payment.js` | Mesin dokumen beralokasi (pola A): Pembayaran Pembelian (daftar, form, faktur terbuka, alokasi otomatis/manual, simpan draft, posting, void, hapus draft) |
| `public/kasira/js/screens/cash.js` | Mesin dokumen Kas & Bank: Penerimaan, Pembayaran, Pencatatan Beban (header + baris akun, pajak dihitung server) dan Transfer Bank (header saja). Daftar, form, simpan draft, posting, void, hapus draft |
| `public/kasira/js/screens/journal.js` | Jurnal Umum: daftar (filter tipe/tanggal/nomor), detail, jurnal baru langsung posting (tanpa draft), void untuk jurnal umum/koreksi |
| `public/kasira/js/screens/adjust.js` | Penyesuaian Persediaan: daftar (filter status), form barang + qty selisih, simpan draft, posting, void, hapus draft |
| `public/kasira/js/screens/report.js` | Laporan (pola R, hanya baca). **Daftar Laporan** = pintu tunggal (daftar di kiri, isi di kanan, seperti prototipe) untuk Neraca, Laba Rugi, Neraca Saldo, Buku Besar (izin `laporan_keuangan`), Umur Piutang (`umur_piutang`), Umur Utang (`umur_utang`). **Histori Akun** = fitur sendiri (modul Buku Besar). Angka akun pada laporan bisa diklik untuk menelusuri ke Histori Akun. Ekspor CSV (hak `export`) dan Cetak (hak `print`) |
| `public/kasira/js/screens/stock.js` | Laporan persediaan (pola R, hanya baca): **Barang per Gudang** (fitur sendiri di modul Gudang; filter gudang, cari barang, hanya yang ada stok, Muat lainnya) dan **Kartu Stok** (laporan di Daftar Laporan; pilih barang + gudang + rentang tanggal, saldo berjalan). Dimuat setelah report.js |
| `public/kasira/js/screens/closing.js` | Proses Akhir Bulan (pola W, wizard 4 langkah: Periksa, Proses otomatis, Tinjau, Kunci periode) dan Tutup Tahun Buku (periode ke-12), plus Buka kunci. Dimuat setelah stock.js |
| `public/kasira/js/shell.js` | Rail, flyout, pencarian, masuk, pilih/buat perusahaan, menu menurut izin, router layar |

## Cara menambah layar (langkah berikutnya)
Buat berkas baru `public/kasira/js/screens/<nama>.js`, muat setelah `shell.js` di Blade, lalu daftarkan:
- `SCREENS["faktur_penjualan"] = (f, m, j) => { ... }` untuk layar satu fitur, atau
- `PATTERN["M"] = (f, m, j) => { ... }` untuk satu pola yang dipakai banyak fitur (D, A, M, R, W, S, G, I).
Kunci `SCREENS` adalah slug izin backend (`f.slug`). Menu, hak akses, dan penolakan akses sudah ditangani shell.
Data selalu lewat `API.get/post/put/del` (balasan `{ data: ... }`), jangan pakai data contoh prototipe.

## Laporan (langkah 4e)
- Umur Piutang, Umur Utang, dan Laporan Keuangan **bukan** menu sendiri di katalog prototipe (`features.js`); semuanya dibuka dari
  Daftar Laporan. Karena itu alamat `#/umur_piutang` atau `#/laporan_keuangan` tidak ada. Izinnya tetap per fitur backend:
  Kasir melihat Umur Piutang, Purchasing Umur Utang, Owner/Admin/Akuntan semua laporan keuangan (sejak 4f, Kartu Stok juga tampil untuk setiap peran yang punya izin `barang_per_gudang` dan `daftar_laporan`, lihat bagian 4f).
- Pemilih periode (Neraca, Laba Rugi, Neraca Saldo) memakai `GET /fiscal-years` yang butuh hak `proses_akhir_bulan:view`.
  Peran khusus yang punya `laporan_keuangan` tetapi tidak punya hak itu akan melihat pesan "Daftar periode tidak dapat dimuat".
  Buku Besar dan Histori Akun memakai rentang tanggal sehingga tidak terpengaruh.
- Penelusuran akun hanya aktif di mode Rinci (mode Ringkas menggabungkan akun ke induknya, yang tidak punya mutasi sendiri).
  Histori Akun butuh hak lihat Akun Perkiraan untuk memilih akun sendiri; tanpa itu, akun hanya datang dari penelusuran.
- Angka ditampilkan dalam rupiah penuh (pembulatan tampilan); tanda kurung = negatif. CSV memakai titik koma + BOM (untuk Excel
  berlokal Indonesia), angka bulat tanpa pemisah ribuan, dan teks berawalan `=`, `+`, `@` diberi tanda `'`.
- Umur Piutang/Utang belum mengurangkan retur dan uang muka (modulnya belum ada di backend; layar menampilkan catatan itu).
- Belum ada: Arus Kas, Penjualan/Pembelian, SPT PPN; penelusuran dari Histori Akun ke dokumen sumber.

## Persediaan (langkah 4f)
- **Barang per Gudang** (`#/barang_per_gudang`) dan **Kartu Stok** memakai satu izin backend: `barang_per_gudang`. Kartu Stok bukan menu sendiri; ia dibuka dari
  Daftar Laporan (grup "Persediaan", bersama tautan ke Barang per Gudang). Klik baris Barang per Gudang menelusuri ke Kartu Stok barang + gudang itu
  (rentang awal tahun sampai hari ini), dengan tombol "Kembali ke Barang per Gudang". Penelusuran hanya aktif bila peran juga punya `daftar_laporan`.
- **Respons tanpa pembungkus `data`**: `reports/stock-by-warehouse` membalas `{ rows, next }` dan `reports/stock-card` membalas
  `{ saldo_awal, rows, next_after_id, perlu_hitung_ulang }` langsung, tidak dibungkus `{ data }` seperti endpoint lain. `kBody()` di stock.js menerima kedua bentuk.
- **HPP dan nilai** hanya dikirim server untuk pemegang hak khusus `lihat_hpp` (menurut `config/rbac.php`: Owner, Admin, Akuntan, Gudang, Purchasing; **tidak** Kasir).
  Tanpa hak itu kolom HPP/Nilai/Harga satuan disembunyikan dan layar menampilkan catatan. Layar mendeteksinya dari ada tidaknya kunci `nilai_saldo` / `saldo_awal.nilai`.
- Jumlah barang boleh pecahan (sampai 4 desimal, `kQty`); HPP dan harga satuan 2 desimal (`kUnit`); nilai rupiah dibulatkan seperti laporan lain.
- Paginasi keyset: Barang per Gudang memakai `after_item_id` + `after_warehouse_id`, Kartu Stok memakai `after_id`. Total masuk/keluar/saldo akhir Kartu Stok baru
  ditampilkan setelah semua halaman termuat (sebelum itu "…"), karena dihitung dari baris yang sudah dimuat. **Ekspor CSV mengambil semua halaman** (bukan hanya yang tampil).
- Pemilih barang/gudang di Kartu Stok memakai `GET /items?tipe=barang` (hak Barang & Jasa) dan `GET /warehouses` (hak Gudang); daftar barang dibatasi 50 dan
  dicari lewat kolom "Cari barang". Tanpa hak itu, pilihan nonaktif dan barang/gudang hanya datang dari penelusuran Barang per Gudang.
- Batasan yang diketahui: server belum mengirim **nomor dokumen** sumber; Kartu Stok menampilkan jenis + id (mis. "Faktur Penjualan #3"). Jenis yang belum punya label
  tampil sebagai nilai `source_type` mentah. Pemindahan antar-gudang, retur, dan penerimaan barang belum ada di backend, jadi belum muncul di kartu.

## Proses Akhir Bulan (langkah 4g)
- `#/proses_akhir_bulan` (modul Perusahaan, izin `proses_akhir_bulan`). Pilih periode (default = periode terbuka paling awal, karena penutupan harus berurutan), lalu empat langkah:
  1. **Periksa**: `GET /periods/{id}/close-check` + `close-logs`. Item berstatus Lolos / Peringatan / Gagal / Belum tersedia (modul belum ada di backend).
  2. **Proses otomatis**: hanya tampil bila item bersangkutan gagal. *Hitung ulang HPP* memanggil `GET /stock/recalc/pending` lalu `POST /stock/recalc` satu barang+gudang per panggilan
     (butuh hak khusus `jurnal_koreksi`; endpoint tidak punya mode "semua", jadi layar yang mengulang). *Penyusutan aset* memakai `depreciation-preview` (butuh `aset_tetap:view`) dan
     `POST /periods/{id}/depreciation` (hak khusus `tutup_periode`). Setelah tiap proses, checklist diperiksa ulang. Item lain (draft, neraca saldo, saldo ringkas, urutan) hanya bisa diperbaiki manual.
  3. **Tinjau**: selalu memeriksa ulang dari server. Peringatan butuh centang konfirmasi; gagal memblokir.
  4. **Kunci periode**: `POST /periods/{id}/close` dengan `{ konfirmasi }`. Bila server menolak (409 `confirm_required` atau 422 `close_blocked`), checklist terbaru dari `hasil` ditampilkan di langkah Tinjau.
- **Periode ke-12** ditolak oleh `POST /periods/{id}/close`; layar otomatis memakai alur **Tutup Tahun Buku**: `GET /fiscal-years/{id}/close-preview` (checklist + ringkasan jurnal penutup: jumlah akun,
  laba/rugi bersih) dan `POST /fiscal-years/{id}/close`. Hasilnya menampilkan nomor jurnal penutup dan apakah tahun buku berikutnya dibuat otomatis.
- **Buka kunci** (hak khusus `buka_kunci_periode`, Owner): `POST /periods/{id}/reopen` atau, untuk tahun yang sudah ditutup, `POST /fiscal-years/{id}/reopen`; alasan wajib. Periode 1 sampai 11 tahun yang dibuka kembali tetap terkunci.
  Backend menolak membuka satu periode bila tahun bukunya masih Closed (pesan server ditampilkan apa adanya).
- **Hak khusus** dibaca dari `/company/permissions` sebagai fitur `khusus` (`can("khusus", "tutup_periode")`). Tanpa hak itu, tombol Kunci periode dan proses otomatis nonaktif/disembunyikan dengan catatan.
- Riwayat penutupan memakai `close-logs` (20 terbaru). Hanya penolakan dan penutupan berbasis checklist yang dicatat server.
- Belum ada: Rekonsiliasi Bank (pola W lain), Rekening Koran, kotak persetujuan; layar ini juga belum menautkan item gagal ke dokumennya (mis. daftar draft).
- Rincian `detail` item checklist hanya diterjemahkan untuk kunci yang dikenal (`per_jenis`, `periode_terbuka`, `rekening_koran`, `jumlah`); kunci baru dari backend diabaikan sampai ditambahkan di `clsDetail()`.

## Pengujian layar laporan
`tests/js/laporan.e2e.cjs`: Chromium (Playwright) + server tiruan yang mengikuti bentuk respons backend. Tidak butuh PHP/MySQL.
```
npm i playwright && npx playwright install chromium
node tests/js/laporan.e2e.cjs . /tmp/screenshot
node tests/js/stok.e2e.cjs . /tmp/screenshot      # Barang per Gudang + Kartu Stok (langkah 4f)
node tests/js/akhirbulan.e2e.cjs . /tmp/screenshot # Proses Akhir Bulan + Tutup Tahun (langkah 4g)
# Bila browser Playwright ada di tempat lain: CHROMIUM_PATH=/jalur/ke/chrome node tests/js/....
```

## Rencana langkah
1. Kerangka + masuk + pilih perusahaan + menu menurut izin (selesai)
2. Beranda dari `GET /api/v1/dashboard` (selesai)
3. Pola M (master): Pelanggan, Pemasok, Syarat Pembayaran (3a) dan Barang & Jasa, Satuan, Merek, Gudang, Akun Perkiraan, Pajak (3b) selesai. Sisa master lain (Karyawan, Pengguna, Penomoran, dst.) menyusul bila endpointnya ada
4. Pola D/A (dokumen): Faktur Penjualan/Pembelian (4a, selesai). 4b: Pembayaran Pembelian (selesai). Penerimaan Penjualan BELUM bisa: backend belum punya model, service, controller, posting handler, dan route untuk penerimaan piutang (hanya tabel `sales_receipts` dan `sales_receipt_allocations` di migrasi). Setelah backend itu ada, cukup menambah satu entri di `PAYMENTS` pada payment.js. 4c: Penerimaan, Pembayaran, Pencatatan Beban, Transfer Bank (selesai). 4d: Jurnal Umum dan Penyesuaian Persediaan (selesai; jurnal koreksi dan saldo awal belum). Berikutnya: backend + layar Penerimaan Penjualan, lalu dokumen lain yang endpointnya ada (cek `routes.php` tiap modul)
5. Pola R (laporan): **4e selesai** (Neraca, Laba Rugi, Neraca Saldo, Buku Besar, Umur Piutang, Umur Utang, Histori Akun, Daftar Laporan). **4f selesai** (Barang per Gudang, Kartu Stok). **4g selesai** (Proses Akhir Bulan + Tutup Tahun Buku, pola W). Berikutnya: Rekonsiliasi Bank / Rekening Koran (pola W lain; lihat `app/Modules/CashBank/Reconciliation/routes.php`) dan kotak persetujuan
6. Pengaturan (S/G) dan integrasi (I)
