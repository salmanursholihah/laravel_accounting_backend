<?php

/**
 * Template bagan akun. Pola kode: 1 Aset, 2 Kewajiban, 3 Ekuitas, 4 Pendapatan, 5 HPP,
 * 6 Beban Operasional, 7 Pendapatan/Beban Lain.
 *
 * Baris: [kode, nama, tipe, sub_tipe, kode_induk, opsi...]
 * opsi: 'header' => true | 'key' => kunci akun sistem | 'normal' => 'debit'|'credit' (akun kontra)
 *       | 'only' => [template yang memakai akun ini]; tanpa 'only' = semua template.
 * Induk harus ditulis sebelum anaknya. Anak harus bertipe sama dengan induknya.
 */
return [
    'templates' => ['dagang', 'jasa', 'manufaktur'],

    'accounts' => [
        // 1 ASET
        ['1-0000', 'ASET', 'asset', null, null, 'header' => true],
        ['1-1000', 'Aset Lancar', 'asset', null, '1-0000', 'header' => true],
        ['1-1100', 'Kas dan Bank', 'asset', 'cash_bank', '1-1000', 'header' => true],
        ['1-1110', 'Kas', 'asset', 'cash_bank', '1-1100'],
        ['1-1120', 'Bank', 'asset', 'cash_bank', '1-1100'],
        ['1-1200', 'Piutang Usaha', 'asset', 'receivable', '1-1000', 'key' => 'piutang_usaha'],
        ['1-1300', 'Persediaan', 'asset', 'inventory', '1-1000', 'key' => 'persediaan', 'only' => ['dagang', 'manufaktur']],
        ['1-1310', 'Persediaan Barang Jadi', 'asset', 'inventory', '1-1000', 'key' => 'persediaan_barang_jadi', 'only' => ['manufaktur']],
        ['1-1320', 'Barang Dalam Proses', 'asset', 'inventory', '1-1000', 'key' => 'barang_dalam_proses', 'only' => ['manufaktur']],
        ['1-1350', 'Barang Terkirim Belum Ditagih', 'asset', 'inventory', '1-1000', 'key' => 'barang_terkirim_belum_ditagih', 'only' => ['dagang', 'manufaktur']],
        ['1-1500', 'PPN Masukan', 'asset', 'tax_receivable', '1-1000', 'key' => 'ppn_masukan'],
        ['1-1600', 'Uang Muka Pembelian', 'asset', 'prepayment', '1-1000', 'key' => 'uang_muka_pembelian'],
        ['1-2000', 'Aset Tetap', 'asset', null, '1-0000', 'header' => true],
        ['1-2100', 'Aset Tetap (Harga Perolehan)', 'asset', 'fixed_asset', '1-2000'],
        ['1-2900', 'Akumulasi Penyusutan', 'asset', 'accumulated_depreciation', '1-2000', 'key' => 'akumulasi_penyusutan', 'normal' => 'credit'],

        // 2 KEWAJIBAN
        ['2-0000', 'KEWAJIBAN', 'liability', null, null, 'header' => true],
        ['2-1000', 'Kewajiban Lancar', 'liability', null, '2-0000', 'header' => true],
        ['2-1100', 'Utang Usaha', 'liability', 'payable', '2-1000', 'key' => 'utang_usaha'],
        ['2-1200', 'Barang Diterima Belum Ditagih', 'liability', 'accrual', '2-1000', 'key' => 'barang_diterima_belum_ditagih', 'only' => ['dagang', 'manufaktur']],
        ['2-1300', 'PPN Keluaran', 'liability', 'tax_payable', '2-1000', 'key' => 'ppn_keluaran'],
        ['2-1310', 'Utang PPnBM', 'liability', 'tax_payable', '2-1000', 'key' => 'utang_ppnbm'],
        ['2-1400', 'Uang Muka Penjualan', 'liability', 'prepayment', '2-1000', 'key' => 'uang_muka_penjualan'],
        ['2-2000', 'Utang Gaji', 'liability', 'payroll', '2-1000', 'key' => 'utang_gaji'],
        ['2-2100', 'Utang PPh 21', 'liability', 'tax_payable', '2-1000', 'key' => 'utang_pph21'],
        ['2-2200', 'Utang BPJS', 'liability', 'payroll', '2-1000', 'key' => 'utang_bpjs'],

        // 3 EKUITAS
        ['3-0000', 'EKUITAS', 'equity', null, null, 'header' => true],
        ['3-1000', 'Modal Disetor', 'equity', 'capital', '3-0000'],
        ['3-2000', 'Laba Ditahan', 'equity', 'retained_earnings', '3-0000', 'key' => 'laba_ditahan'],
        ['3-3000', 'Laba Tahun Berjalan', 'equity', 'current_earnings', '3-0000', 'key' => 'laba_tahun_berjalan'],
        ['3-9000', 'Ikhtisar Laba Rugi', 'equity', 'closing', '3-0000', 'key' => 'ikhtisar_laba_rugi'],

        // 4 PENDAPATAN
        ['4-0000', 'PENDAPATAN', 'revenue', null, null, 'header' => true],
        ['4-1000', 'Penjualan', 'revenue', 'sales', '4-0000', 'key' => 'penjualan', 'only' => ['dagang', 'manufaktur']],
        ['4-1000', 'Pendapatan Jasa', 'revenue', 'sales', '4-0000', 'key' => 'penjualan', 'only' => ['jasa']],
        ['4-1100', 'Diskon Penjualan', 'revenue', 'sales_discount', '4-0000', 'key' => 'diskon_penjualan', 'normal' => 'debit'],
        ['4-1200', 'Retur Penjualan', 'revenue', 'sales_return', '4-0000', 'key' => 'retur_penjualan', 'normal' => 'debit'],

        // 5 HPP
        ['5-0000', 'HARGA POKOK PENJUALAN', 'cogs', null, null, 'header' => true, 'only' => ['dagang', 'manufaktur']],
        ['5-1000', 'Harga Pokok Penjualan', 'cogs', 'cogs', '5-0000', 'key' => 'hpp', 'only' => ['dagang', 'manufaktur']],
        ['5-2000', 'Penyesuaian Persediaan', 'cogs', 'inventory_adjustment', '5-0000', 'key' => 'penyesuaian_persediaan', 'only' => ['dagang', 'manufaktur']],

        // 6 BEBAN OPERASIONAL
        ['6-0000', 'BEBAN OPERASIONAL', 'expense', null, null, 'header' => true],
        ['6-1000', 'Beban Gaji', 'expense', 'payroll', '6-0000', 'key' => 'beban_gaji'],
        ['6-2000', 'Beban Sewa', 'expense', null, '6-0000'],
        ['6-2100', 'Beban Listrik, Air, dan Telepon', 'expense', null, '6-0000'],
        ['6-2200', 'Beban Perlengkapan Kantor', 'expense', null, '6-0000'],
        ['6-3000', 'Beban Penyusutan', 'expense', 'depreciation', '6-0000', 'key' => 'beban_penyusutan'],
        ['6-4000', 'Beban Administrasi Bank', 'expense', 'bank_fee', '6-0000', 'key' => 'beban_admin_bank'],
        ['6-5000', 'Beban Pemasaran', 'expense', null, '6-0000'],

        // 7 PENDAPATAN / BEBAN LAIN
        ['7-0000', 'PENDAPATAN LAIN-LAIN', 'other_income', null, null, 'header' => true],
        ['7-1000', 'Laba/Rugi Pelepasan Aset', 'other_income', 'asset_disposal', '7-0000', 'key' => 'laba_rugi_pelepasan_aset'],
        ['7-2000', 'Pendapatan Lain-lain', 'other_income', null, '7-0000', 'key' => 'pendapatan_lain'],
        ['7-5000', 'BEBAN LAIN-LAIN', 'other_expense', null, null, 'header' => true],
        ['7-5100', 'Pembulatan', 'other_expense', 'rounding', '7-5000', 'key' => 'pembulatan'],
        ['7-5200', 'Beban Lain-lain', 'other_expense', null, '7-5000'],
    ],
];
