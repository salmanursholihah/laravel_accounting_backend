<?php

/**
 * Data awal master Pajak dan Syarat Pembayaran yang disalin ke tiap perusahaan baru.
 *
 * Ini DATA, bukan logika: setelah disalin, tiap perusahaan mengelolanya sendiri lewat master Pajak.
 * Tarif bisa berubah kapan saja dengan menambah versi baru (berlaku_dari); versi lama tidak disentuh.
 * Kondisi saat analisis (Tahap 4 bagian 4): sejak 1 Jan 2025 tarif PPN 12%, barang/jasa non-mewah memakai DPP nilai
 * lain 11/12 (tarif efektif 11%), barang mewah 12% penuh tanpa pengecilan DPP.
 * KONSULTASIKAN ke konsultan pajak sebelum rilis komersial.
 *
 * akun_penjualan / akun_pembelian = kunci akun sistem (config/system_accounts.php), diterjemahkan ke akun
 * nyata perusahaan saat penyalinan.
 */
return [
    // Salin data awal ini saat perusahaan dibuat. Dimatikan di TestCase dasar; tes yang membutuhkannya menyalakan lagi.
    'seed_on_company_created' => true,

    'payment_terms' => [
        ['nama' => 'Tunai', 'jumlah_hari' => 0],
        ['nama' => 'Net 7', 'jumlah_hari' => 7],
        ['nama' => 'Net 14', 'jumlah_hari' => 14],
        ['nama' => 'Net 30', 'jumlah_hari' => 30],
        ['nama' => 'Net 60', 'jumlah_hari' => 60],
        ['nama' => '2/10 Net 30', 'jumlah_hari' => 30, 'diskon_persen' => '2', 'diskon_hari' => 10],
    ],

    'tax_codes' => [
        [
            'kode' => 'PPN', 'nama' => 'PPN', 'jenis' => 'ppn',
            'akun_penjualan' => 'ppn_keluaran', 'akun_pembelian' => 'ppn_masukan',
            'versi' => [
                ['tarif' => '0.11', 'dpp' => [1, 1], 'berlaku_dari' => '2022-04-01', 'berlaku_sampai' => '2024-12-31'],
                ['tarif' => '0.12', 'dpp' => [11, 12], 'berlaku_dari' => '2025-01-01'],
            ],
        ],
        [
            'kode' => 'PPN-MEWAH', 'nama' => 'PPN Barang Mewah (DPP penuh)', 'jenis' => 'ppn',
            'akun_penjualan' => 'ppn_keluaran', 'akun_pembelian' => 'ppn_masukan',
            'versi' => [
                ['tarif' => '0.11', 'dpp' => [1, 1], 'berlaku_dari' => '2022-04-01', 'berlaku_sampai' => '2024-12-31'],
                ['tarif' => '0.12', 'dpp' => [1, 1], 'berlaku_dari' => '2025-01-01'],
            ],
        ],
        [
            'kode' => 'PPN-0', 'nama' => 'PPN 0% (ekspor/dibebaskan)', 'jenis' => 'ppn',
            'akun_penjualan' => 'ppn_keluaran', 'akun_pembelian' => 'ppn_masukan',
            'versi' => [
                ['tarif' => '0', 'dpp' => [1, 1], 'berlaku_dari' => '2000-01-01'],
            ],
        ],
        // PPnBM tidak diisi: tarifnya per kelompok barang. Tambahkan lewat master Pajak (jenis ppnbm).
        // PPh 22/23 disiapkan sebagai jenis di master; fitur lengkap fase lanjut.
    ],
];
