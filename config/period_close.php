<?php

/**
 * Proses Akhir Bulan (ALG-11). Dokumen yang dihitung sebagai "belum diposting" pada checklist.
 * Format: doc_type => [tabel, label]. Tabel wajib punya kolom company_id, status, tanggal; tabel/kolom yang
 * belum ada dilewati otomatis. Tambahkan baris di sini saat modul dokumen baru selesai (Step 7 berikutnya).
 */
return [
    'draft_tables' => [
        'sales_invoice' => ['sales_invoices', 'Faktur Penjualan'],
        'sales_receipt' => ['sales_receipts', 'Penerimaan Penjualan'],
        'sales_return' => ['sales_returns', 'Retur Penjualan'],
        'purchase_invoice' => ['purchase_invoices', 'Faktur Pembelian'],
        'purchase_payment' => ['purchase_payments', 'Pembayaran Pembelian'],
        'purchase_return' => ['purchase_returns', 'Retur Pembelian'],
        'cash_receipt' => ['cash_receipts', 'Penerimaan Kas/Bank'],
        'cash_payment' => ['cash_payments', 'Pembayaran Kas/Bank'],
        'expense' => ['expenses', 'Pencatatan Beban'],
        'bank_transfer' => ['bank_transfers', 'Transfer Bank'],
        'stock_adjustment' => ['stock_adjustments', 'Penyesuaian Persediaan'],
    ],
];
