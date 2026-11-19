# PROJECT STATUS

| Step | Isi | Status kode | Status tes |
|---|---|---|---|
| 1 | Platform: tenancy, RBAC, audit, buat perusahaan | ditulis | belum dijalankan |
| 2a/2b | Bagan akun, periode, pajak, mesin jurnal, orkestrator posting, jurnal umum, saldo awal | ditulis | belum dijalankan |
| 3 | Kas & Bank | ditulis | belum dijalankan |
| 4 | Stok inti, HPP rata-rata, penyesuaian | ditulis | belum dijalankan |
| 5 | Penjualan (Faktur), Pembelian (+Pembayaran, ALG-06) | ditulis, **Penerimaan/Retur belum ada** | belum dijalankan |
| 6 | Approval, laporan keuangan, umur piutang/utang, kartu stok, dashboard, ekspor | ditulis | belum dijalankan |
| 7A | Hitung ulang HPP transaksi mundur | ditulis | belum dijalankan |
| 7B | Akhir Bulan dan Tutup Tahun (ALG-11) | ditulis | belum dijalankan |
| 7C-1 | Rekening Koran (input manual, impor CSV) | ditulis | belum dijalankan |
| 7C-2/3 | Sesi rekonsiliasi, ALG-09 satu-ke-satu, selesai/buka kembali, checklist Akhir Bulan | ditulis | belum dijalankan |
| **7D-1** | **Aset Tetap + penyusutan garis lurus (ALG-08), checklist Akhir Bulan** | **ditulis (sesi ini)** | **belum dijalankan** |
| 7C+ | Penawaran, pesanan, pengiriman, PO, uang muka, stok opname, pemindahan barang, anggaran, aset tetap, transaksi berulang | belum | - |
| 8 | Komisi, target, gaji, FIFO, Midtrans, e-Faktur | belum (jangan dimulai) | - |

## Cakupan 7B
Checklist (urutan, draft, hitung ulang HPP tertunda, neraca saldo seimbang, saldo ringkas cocok; transaksi berulang,
penyusutan, rekonsiliasi = "na"), tutup bulan, tutup tahun dengan jurnal penutup, buka kembali tahun (Owner), riwayat
di period_close_logs. Endpoint: lihat CHANGELOG.md.

## Cakupan 7C-2/3
Sesi per rekening koran, pencocokan otomatis (ALG-09), saran, cocok manual/lepas, transaksi baru dari baris koran, selesai dan
buka kembali, checklist Akhir Bulan menjadi warning. Belum: ALG-09 1-ke-banyak. Lihat DECISIONS.md.

## Cakupan 7D-1
Kategori Aset, Aset Tetap, penyusutan garis lurus idempoten per periode (pratinjau dan jalankan), jadwal per aset, checklist Akhir Bulan.
Belum: disposisi, saldo menurun, perubahan/pindah aset, aset fiskal, migrasi aset lama, pembatalan penyusutan. Lihat DECISIONS.md.

- 7D-2 Disposisi Aset: ditulis, belum terverifikasi tes (lihat CHANGELOG).
