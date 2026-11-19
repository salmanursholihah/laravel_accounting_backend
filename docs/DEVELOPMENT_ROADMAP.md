# DEVELOPMENT ROADMAP

- [x] Step 1-6, 7A (ditulis, belum terverifikasi tes)
- [x] 7B Akhir Bulan dan Tutup Tahun (ditulis, belum terverifikasi tes)
- [ ] 0  Jalankan seluruh tes di MySQL 8 dan perbaiki yang merah (prasyarat sebelum menambah modul)
- [x] 7C Rekonsiliasi bank (ditulis, belum terverifikasi tes; mengaktifkan item checklist "rekonsiliasi_bank")
  - [x] 7C-1 Rekening Koran: input manual + impor CSV, validasi saldo, hapus (ditulis, belum terverifikasi tes)
  - [x] 7C-2 Sesi rekonsiliasi, ALG-09 (satu-ke-satu), cocok manual/lepas, transaksi baru dari baris koran (ditulis, belum terverifikasi tes). Kasus 1-ke-banyak ALG-09 belum (butuh migrasi)
  - [x] 7C-3 Selesai/Buka Kembali, aktivasi checklist Akhir Bulan (peringatan), tes, docs (ditulis, belum terverifikasi tes)
  - [ ] 7C-4 (opsional, butuh migrasi) ALG-09 1-ke-banyak: hapus uq_ri_stmt, subset maks 5 item
- [ ] 7D Aset tetap + penyusutan ALG-08 (mengaktifkan item "penyusutan_aset")
- [ ] 7E Transaksi berulang (mengaktifkan item "transaksi_berulang")
- [ ] 7F Penerimaan/Retur Penjualan, Retur Pembelian (celah Step 5)
- [ ] 7G Penawaran, pesanan, pengiriman, PO, uang muka
- [ ] 7H Stok opname, pemindahan barang (aturan approval bawaan sudah menyebut keduanya)
- [ ] 7I Anggaran
- [ ] Step 8 (komisi, target, gaji, FIFO, Midtrans ALG-12, e-Faktur): jangan dimulai sebelum persetujuan

## Tahap 7D Aset Tetap dan Penyusutan (ALG-08)
- [x] 7D-1 Kategori Aset, Aset Tetap (garis lurus), jadwal penyusutan idempoten, jurnal per kategori, checklist Akhir Bulan (ditulis, belum terverifikasi tes)
- [x] 7D-2 (ditulis, belum terverifikasi tes) Disposisi aset (penyusutan sampai bulan sebelum pelepasan, laba/rugi pelepasan; hak `disposisi_aset_tetap`, penomoran `asset_disposal` sudah ada)
- [ ] 7D-3 Saldo menurun, Perubahan Aset, Pindah Aset + Lokasi, aset fiskal, migrasi aset lama (akumulasi awal), pembatalan penyusutan
