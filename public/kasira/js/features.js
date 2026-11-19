/* Kasira: katalog modul dan 88 fitur. Disalin apa adanya dari Prototipe Terpadu. */
/* ===== KONFIGURASI 88 FITUR =====
 Format: Nama|jenis(t transaksi,m master,l laporan,k konfigurasi)|pola|isian (pisah koma; "Label:opsi1/opsi2")|catatan (jurnal atau langkah wizard)
 Pola: D dokumen header-detail (P3), A dokumen dengan alokasi (P4), M master+panel (P5), R laporan (P6), W wizard (P7), S pengaturan (P8), G matriks hak akses (P8), I integrasi (P9) */
const MD=[["Beranda","M3 3h7v9H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 16h7v5H3z"],["Pengaturan","M4 7h9M17 7h3M4 17h3M11 17h9M15 7m-2 0a2 2 0 104 0 2 2 0 10-4 0M9 17m-2 0a2 2 0 104 0 2 2 0 10-4 0"],["Perusahaan","M4 21V5l8-2v18M12 9h8v12M8 8h1M8 12h1M8 16h1"],["Buku Besar","M6 3h12v18H6zM6 7H4M6 12H4M6 17H4M10 8h4"],["Kas & Bank","M3 10l9-6 9 6M5 10v8M9 10v8M15 10v8M19 10v8M3 21h18"],["Penjualan","M3 17l6-6 4 4 8-8M15 7h6v6"],["Pembelian","M3 4h2l2 12h11l2-8H7"],["Persediaan","M3 7l9-4 9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10"],["Aset Tetap","M3 21h18M5 21V9l7-5 7 5v12M10 21v-6h4v6"],["Pajak","M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6"],["Laporan","M12 3v9h9A9 9 0 0012 3zM10 5a8 8 0 108 8"]];
const FT=[null,[
"Preferensi|k|S|Nama perusahaan,NPWP,Status pajak:PKP/Non-PKP,Bulan mulai tahun buku:Januari/Juli,Tanggal mulai pembukuan,Izinkan stok minus:Tidak/Ya,Metode persediaan:Rata-rata/FIFO,Toleransi kirim %",
"Akses Grup|k|G|",
"Pengguna|k|M|Nama,Email,Grup akses:Owner/Admin/Akuntan/Kasir/Gudang/Purchasing,Cabang,Status:Aktif/Nonaktif",
"Penomoran|k|M|Jenis dokumen,Awalan,Format,Reset:Bulanan/Tahunan/Tidak,Nomor berikut",
"Desain Cetakan|k|S|Jenis dokumen:Faktur/PO/Bukti bayar,Ukuran kertas:A4/A5/Thermal,Tampilkan logo:Ya/Tidak,Catatan kaki",
"Toko Aplikasi|k|I|Aplikasi tambahan|Katalog layanan tambahan, aktif di fase lanjut."],[
"Pajak|m|M|Kode,Nama,Jenis:PPN/PPnBM/PPh,Tarif %,DPP pembilang,DPP penyebut,Berlaku dari,Berlaku sampai",
"Syarat Pembayaran|m|M|Nama,Jumlah hari,Diskon %,Diskon hari",
"Pengiriman|m|M|Kode,Nama,Akun biaya",
"FOB|m|M|Kode,Nama,Penanggung jawab kirim:Penjual/Pembeli",
"Gaji/Tunjangan|m|M|Nama,Tipe:Pokok/Tunjangan/Potongan,Akun,Kena pajak:Ya/Tidak",
"Karyawan|m|M|Nama,NIK,Jabatan,Departemen,Tanggal masuk,Gaji pokok",
"Transaksi Berulang|t|M|Nama,Jenis dokumen,Frekuensi:Bulanan/Mingguan/Harian/Tahunan,Mulai,Selesai,Nominal",
"Proses Akhir Bulan|t|W|Periode:Oktober 2026/November 2026|Periksa/Proses otomatis/Tinjau/Kunci periode",
"Kontak|l|M|Nama,Tipe:Perusahaan/Perorangan,Telepon,Email,Alamat,NPWP",
"Transaksi Favorit|l|M|Nama,Jenis dokumen",
"Kalender|l|R|Bulan,Jenis pengingat",
"Log Aktivitas|l|R|Pengguna,Jenis aksi:Semua/Buat/Ubah/Posting/Void,Tanggal dari,Tanggal sampai"],[
"Akun Perkiraan|m|M|Kode,Nama,Tipe:Aset/Kewajiban/Ekuitas/Pendapatan/HPP/Beban,Akun induk,Mata uang:IDR,Saldo awal,Tanggal saldo",
"Pencatatan Beban|t|D|Tanggal,Penerima,Akun pembayaran,Keterangan|Dr Beban · Cr Kas/Bank atau Utang",
"Pencatatan Gaji|t|D|Periode gaji,Tanggal bayar,Akun pembayaran|Dr Beban Gaji, Tunjangan · Cr Utang Gaji, PPh 21, BPJS",
"Jurnal Umum|t|D|Tanggal,Memo|Sesuai input. Debit harus sama dengan kredit",
"Monitor Anggaran|l|R|Anggaran,Periode",
"Transfer Anggaran|t|A|Tanggal,Anggaran asal,Anggaran tujuan,Nominal,Keterangan|-",
"Anggaran|k|M|Nama,Tahun buku,Akun,Periode,Nominal",
"Histori Akun|l|R|Akun,Tanggal dari,Tanggal sampai",
"Log Aktivitas Jurnal|l|R|Nomor jurnal,Pengguna"],[
"Pembayaran|t|D|Tanggal,Penerima,Rekening sumber,Keterangan|Dr Beban/Utang · Cr Kas/Bank",
"Penerimaan|t|D|Tanggal,Sumber dana,Rekening tujuan,Keterangan|Dr Kas/Bank · Cr Pendapatan/Lainnya",
"Transfer Bank|t|A|Tanggal,Rekening asal,Rekening tujuan,Nominal,Biaya admin|Dr Bank tujuan · Cr Bank asal",
"SmartLink e-Banking|m|I|Bank|Sinkron mutasi rekening otomatis.",
"Rekening Koran|l|W|Rekening,Format:CSV/OFX/MT940,Berkas|Pilih berkas/Petakan kolom/Tinjau/Simpan",
"Histori Bank|l|R|Rekening,Tanggal dari,Tanggal sampai",
"Rekonsiliasi Bank|l|W|Rekening,Periode,Saldo koran|Pilih rekening/Cocokkan/Buat transaksi/Selesai",
"SmartLink Virtual Account|l|I|Bank|Nomor VA per pelanggan, penerimaan otomatis.",
"SmartLink e-Payment|l|I|Penyedia:Midtrans|Pelanggan bayar lewat link, Penerimaan Penjualan dibuat otomatis."],[
"Penawaran Penjualan|t|D|Pelanggan,Tanggal,Berlaku sampai,Catatan|Tanpa jurnal",
"Pesanan Penjualan|t|D|Pelanggan,Tanggal pesanan,Tanggal kirim diminta,Catatan|Tanpa jurnal (reservasi stok opsional)",
"Pengiriman Pesanan|t|D|Nomor pesanan,Tanggal,Gudang asal,Metode pengiriman,No resi|Dr Barang Terkirim Belum Ditagih · Cr Persediaan",
"Uang Muka Penjualan|t|A|Pelanggan,Tanggal,Nominal,Rekening penerimaan|Dr Kas/Bank · Cr Uang Muka Penjualan",
"Faktur Penjualan|t|D|Pelanggan,Tanggal,Termin:Net 30/Net 14/Tunai,Gudang,Salesperson|Dr Piutang · Cr Penjualan, PPN Keluaran · Dr HPP · Cr Persediaan",
"Penerimaan Penjualan|t|A|Pelanggan,Tanggal,Rekening penerimaan,Nominal|Dr Kas/Bank · Cr Piutang Usaha",
"Retur Penjualan|t|D|Faktur asal,Tanggal,Alasan|Dr Retur Penjualan, PPN · Cr Piutang · Dr Persediaan · Cr HPP",
"Kategori Pelanggan|m|M|Kode,Nama,Level harga",
"Kategori Penjualan|m|M|Kode,Nama",
"Pelanggan|m|M|Kode,Nama,Alamat,Telepon,Email,NPWP,Kategori,Limit kredit,Termin:Net 30/Net 14/Tunai",
"Penyesuaian Harga/Diskon|k|M|Nama,Barang,Harga,Diskon %,Qty minimum,Mulai,Selesai",
"Komisi Penjual|k|M|Penjual,Dasar:Faktur Posted/Faktur Lunas,Persen",
"Target Penjualan|k|M|Periode,Penjual atau tim,Nilai target",
"SmartLink e-Commerce|k|I|Platform|Pesanan marketplace masuk sebagai Pesanan Penjualan.",
"Check In|l|I|Pelanggan|Butuh aplikasi mobile (kunjungan dan lokasi)."],[
"Pesanan Pembelian|t|D|Pemasok,Tanggal PO,Tanggal diharapkan,Catatan|Tanpa jurnal",
"Penerimaan Barang|t|D|Nomor PO,Tanggal,Gudang,No surat jalan|Dr Persediaan · Cr Barang Diterima Belum Ditagih",
"Uang Muka Pembelian|t|A|Pemasok,Tanggal,Nominal,Rekening pembayaran|Dr Uang Muka Pembelian · Cr Kas/Bank",
"Faktur Pembelian|t|D|Pemasok,No faktur pemasok,Tanggal,Termin:Net 30/Net 14/Tunai,No faktur pajak|Dr Persediaan/Beban, PPN Masukan · Cr Utang Usaha",
"Pembayaran Pembelian|t|A|Pemasok,Tanggal,Rekening pembayaran,Nominal|Dr Utang Usaha · Cr Kas/Bank",
"Retur Pembelian|t|D|Faktur asal,Tanggal,Alasan|Dr Utang · Cr Persediaan, PPN Masukan",
"Harga Pemasok|k|M|Pemasok,Barang,Harga beli,Mata uang:IDR,Berlaku dari,Berlaku sampai",
"Kategori Pemasok|m|M|Kode,Nama",
"Pemasok|m|M|Kode,Nama,Alamat,Telepon,Email,NPWP,Kategori,Termin:Net 30/Net 14/Tunai,No rekening",
"Perintah Pembayaran|t|D|Tanggal rencana,Rekening|Tanpa jurnal (hanya jadwal)",
"Transfer Pemasok|l|A|Pemasok,Rekening asal,Rekening tujuan pemasok,Nominal,Biaya admin|Dr Utang · Cr Bank"],[
"Permintaan Barang|t|D|Pemohon,Tanggal,Tanggal dibutuhkan,Keterangan|Tanpa jurnal",
"Pemindahan Barang|t|D|Gudang asal,Gudang tujuan,Tanggal|Tanpa jurnal (nilai tetap)",
"Penyesuaian Persediaan|t|D|Gudang,Tanggal,Alasan|Selisih plus: Dr Persediaan · Cr Penyesuaian",
"Pekerjaan Pesanan|t|D|No pekerjaan,Tanggal mulai,Tanggal selesai,Gudang hasil|Dr Barang Dalam Proses · Cr Persediaan Bahan Baku",
"Penambahan Bahan Baku|t|D|Pekerjaan terkait,Tanggal|Dr Barang Dalam Proses · Cr Persediaan",
"Perintah Stok Opname|t|D|Gudang,Tanggal|Tanpa jurnal",
"Hasil Stok Opname|t|D|Perintah opname,Disetujui oleh|Selisih jadi Penyesuaian Persediaan",
"Barang & Jasa|m|M|Kode,Nama,Jenis:Barang/Jasa,Satuan,Harga jual,Harga beli standar,Kategori,Merek,Barcode",
"Gudang|m|M|Kode,Nama,Alamat,Penanggung jawab",
"Satuan Barang|m|M|Kode,Nama,Satuan dasar,Rasio konversi",
"Merek Barang|m|M|Kode,Nama",
"Pemenuhan Pesanan|l|R|Pesanan,Gudang",
"Barang per Gudang|l|R|Gudang,Kategori",
"Barang Stok Minimum|l|M|Barang,Gudang,Batas stok minimum"],[
"Aset Tetap|m|M|Kode,Nama,Kategori,Tanggal perolehan,Harga perolehan,Umur manfaat (bulan),Metode:Garis lurus/Saldo menurun,Nilai residu,Lokasi",
"Kategori Aset|m|M|Kode,Nama,Akun aset,Akun akumulasi,Akun beban",
"Kategori Aset Tetap Pajak|m|M|Kelompok,Umur (tahun),Tarif garis lurus %,Tarif saldo menurun %",
"Perubahan Aset Tetap|t|A|Aset,Tanggal,Jenis perubahan,Nilai perubahan|Menyesuaikan nilai buku dan jadwal penyusutan",
"Disposisi Aset Tetap|t|A|Aset,Tanggal pelepasan,Nilai pelepasan,Alasan|Dr Kas, Akum. Penyusutan · Cr Aset ± Laba/Rugi",
"Pindah Aset|t|A|Aset,Lokasi asal,Lokasi tujuan,Tanggal|Tanpa jurnal",
"Aset per Lokasi|l|R|Lokasi"],[
"e-Faktur CTAS|l|I|NPWP,NITKU|Faktur pajak elektronik, aktif di fase integrasi.",
"Email Faktur Pajak|t|D|Faktur pajak,Email tujuan,Tanggal|Tanpa jurnal"],[
"Daftar Laporan|l|R|Periode dari,Periode sampai",
"SPT PPN / PPnBM|l|R|Masa pajak,Jenis:PPN/PPnBM",
"Analisa AI|l|I|Pertanyaan|Tanya data usaha dengan bahasa biasa."]];
const FE=FT.map((a,mi)=>a&&a.map(e=>{const p=e.split("|");return{n:p[0],t:p[1],p:p[2],f:p[3]?p[3].split(","):[],j:p[4]||""}}));
const TY={t:["Transaksi","ty"],m:["Data master","mm"],l:["Laporan dan monitoring","ll"],k:["Pengaturan","kk"]};
const PL={D:"Form dokumen (P2+P3)",A:"Dokumen dengan alokasi (P2+P4)",M:"Master dengan panel (P5)",R:"Laporan (P6)",W:"Wizard (P7)",S:"Pengaturan (P8)",G:"Matriks hak akses (P8)",I:"Integrasi (P9)"};
