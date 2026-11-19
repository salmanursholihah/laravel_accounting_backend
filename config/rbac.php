<?php

/**
 * Katalog RBAC (Tahap 1 dan 2 analisis): 88 fitur + Dashboard, 6 role bawaan.
 * Level: F = penuh, C = lihat/tambah/ubah/hapus draft/cetak (+ posting), V = lihat/cetak/ekspor.
 * Nilai modul untuk satu role boleh string level atau array [fitur => level] (untuk tanda * di matriks).
 */
return [
    'aksi_dasar' => ['view', 'create', 'update', 'delete', 'print', 'export'],

    'modules' => [
        'dashboard' => ['dashboard'],
        'pengaturan' => ['preferensi', 'akses_grup', 'pengguna', 'penomoran', 'desain_cetakan', 'accurate_store'],
        'perusahaan' => ['pajak', 'syarat_pembayaran', 'proses_akhir_bulan', 'log_aktivitas', 'karyawan', 'transaksi_berulang', 'pengiriman', 'fob', 'gaji_tunjangan', 'kontak', 'transaksi_favorit', 'kalender'],
        'buku_besar' => ['akun_perkiraan', 'pencatatan_beban', 'jurnal_umum', 'histori_akun', 'anggaran', 'monitor_anggaran', 'log_aktivitas_jurnal', 'pencatatan_gaji', 'transfer_anggaran'],
        'kas_bank' => ['pembayaran', 'penerimaan', 'transfer_bank', 'rekening_koran', 'histori_bank', 'rekonsiliasi_bank', 'smartlink_e_banking', 'smartlink_virtual_account', 'smartlink_e_payment'],
        'penjualan' => ['pelanggan', 'faktur_penjualan', 'penerimaan_penjualan', 'retur_penjualan', 'penawaran_penjualan', 'pesanan_penjualan', 'pengiriman_pesanan', 'uang_muka_penjualan', 'kategori_pelanggan', 'penyesuaian_harga_diskon', 'kategori_penjualan', 'komisi_penjual', 'target_penjualan', 'smartlink_e_commerce', 'check_in'],
        'pembelian' => ['pemasok', 'faktur_pembelian', 'pembayaran_pembelian', 'retur_pembelian', 'pesanan_pembelian', 'penerimaan_barang', 'uang_muka_pembelian', 'kategori_pemasok', 'harga_pemasok', 'perintah_pembayaran', 'transfer_pemasok'],
        'persediaan' => ['barang_jasa', 'gudang', 'satuan_barang', 'penyesuaian_persediaan', 'barang_per_gudang', 'pemindahan_barang', 'pekerjaan_pesanan', 'penambahan_bahan_baku', 'perintah_stok_opname', 'hasil_stok_opname', 'barang_stok_minimum', 'permintaan_barang', 'merek_barang', 'pemenuhan_pesanan'],
        'aset_tetap' => ['aset_tetap', 'kategori_aset', 'disposisi_aset_tetap', 'kategori_aset_tetap_pajak', 'perubahan_aset_tetap', 'pindah_aset', 'aset_per_lokasi'],
        'smartlink_tax' => ['email_faktur_pajak', 'e_faktur_ctas'],
        'laporan' => ['daftar_laporan', 'laporan_keuangan', 'umur_piutang', 'umur_utang', 'spt_ppn_ppnbm', 'analisa_ai'],
    ],

    // Dokumen akuntansi/stok yang punya aksi tambahan: post dan void
    'dokumen_posting' => [
        'pencatatan_beban', 'jurnal_umum', 'pencatatan_gaji', 'pembayaran', 'penerimaan', 'transfer_bank',
        'faktur_penjualan', 'penerimaan_penjualan', 'retur_penjualan', 'uang_muka_penjualan', 'pengiriman_pesanan',
        'faktur_pembelian', 'pembayaran_pembelian', 'retur_pembelian', 'uang_muka_pembelian', 'penerimaan_barang', 'transfer_pemasok',
        'penyesuaian_persediaan', 'pemindahan_barang', 'hasil_stok_opname', 'pekerjaan_pesanan', 'penambahan_bahan_baku',
        'disposisi_aset_tetap', 'perubahan_aset_tetap',
    ],

    // Role yang posting-nya hanya untuk dokumen miliknya sendiri
    'post_hanya_miliknya' => ['Kasir', 'Gudang', 'Purchasing'],

    'matrix' => [
        'Owner' => ['*' => 'F'],
        'Admin' => [
            'dashboard' => 'F', 'pengaturan' => 'F', 'perusahaan' => 'F', 'buku_besar' => 'V', 'kas_bank' => 'V',
            'penjualan' => 'F', 'pembelian' => 'F', 'persediaan' => 'F', 'aset_tetap' => 'C', 'smartlink_tax' => 'V', 'laporan' => 'V',
        ],
        'Akuntan' => [
            'dashboard' => 'F', 'pengaturan' => 'V',
            'perusahaan' => ['pajak' => 'C', 'syarat_pembayaran' => 'C', 'proses_akhir_bulan' => 'C', 'transaksi_berulang' => 'C', 'log_aktivitas' => 'C'],
            'buku_besar' => 'F', 'kas_bank' => 'F', 'penjualan' => 'F', 'pembelian' => 'F', 'persediaan' => 'F',
            'aset_tetap' => 'F', 'smartlink_tax' => 'F', 'laporan' => 'F',
        ],
        'Kasir' => [
            'dashboard' => 'V', 'perusahaan' => 'V',
            'kas_bank' => ['penerimaan' => 'C', 'pembayaran' => 'C', 'transfer_bank' => 'C'],
            'penjualan' => ['faktur_penjualan' => 'C', 'penerimaan_penjualan' => 'C', 'retur_penjualan' => 'C', 'uang_muka_penjualan' => 'C', 'pelanggan' => 'C', 'penawaran_penjualan' => 'C', 'pesanan_penjualan' => 'C'],
            'pembelian' => ['pembayaran_pembelian' => 'V', 'perintah_pembayaran' => 'V'],
            'persediaan' => 'V',
            'laporan' => ['daftar_laporan' => 'V', 'umur_piutang' => 'V'],
        ],
        'Gudang' => [
            'dashboard' => 'V', 'perusahaan' => 'V',
            'penjualan' => ['pengiriman_pesanan' => 'C'],
            'pembelian' => ['penerimaan_barang' => 'C'],
            'persediaan' => 'F',
            'laporan' => ['daftar_laporan' => 'V'],
        ],
        'Purchasing' => [
            'dashboard' => 'V', 'perusahaan' => 'V',
            'pembelian' => ['pemasok' => 'C', 'faktur_pembelian' => 'C', 'retur_pembelian' => 'C', 'pesanan_pembelian' => 'C', 'penerimaan_barang' => 'C', 'uang_muka_pembelian' => 'C', 'kategori_pemasok' => 'C', 'harga_pemasok' => 'C', 'perintah_pembayaran' => 'C', 'transfer_pemasok' => 'C'],
            'persediaan' => ['barang_jasa' => 'V', 'barang_per_gudang' => 'V', 'barang_stok_minimum' => 'V', 'permintaan_barang' => 'C'],
            'laporan' => ['daftar_laporan' => 'V', 'umur_utang' => 'V'],
        ],
    ],

    // Hak khusus (Tahap 2 bagian 4). Disimpan di permissions dengan fitur = 'khusus'.
    'khusus' => [
        'jurnal_koreksi', 'tutup_periode', 'buka_kunci_periode', 'lihat_hpp', 'ubah_harga_diskon',
        'kelola_pengguna', 'ubah_preferensi', 'lihat_log', 'ekspor_massal', 'atur_integrasi', 'hapus_perusahaan',
    ],

    // role => [aksi_khusus => nilai_batas|null]; '*' = semua
    'khusus_role' => [
        'Owner' => '*',
        'Admin' => ['lihat_hpp' => null, 'ubah_harga_diskon' => null, 'kelola_pengguna' => null, 'ubah_preferensi' => null, 'lihat_log' => null, 'ekspor_massal' => null, 'atur_integrasi' => null],
        'Akuntan' => ['jurnal_koreksi' => null, 'tutup_periode' => null, 'lihat_hpp' => null, 'ubah_harga_diskon' => null, 'lihat_log' => null, 'ekspor_massal' => null],
        'Kasir' => ['ubah_harga_diskon' => 5], // batas diskon 5 persen (bisa diubah Owner di Akses Grup)
        'Gudang' => ['lihat_hpp' => null],
        'Purchasing' => ['lihat_hpp' => null],
    ],
];
