<?php

/** Jenis dokumen bernomor dan prefix bawaan. Format bawaan: {PREFIX}/{YYYY}/{MM}/{SEQ:5}, reset bulanan. */
return [
    'default_format' => '{PREFIX}/{YYYY}/{MM}/{SEQ:5}',
    'default_reset' => 'monthly',

    'types' => [
        'journal' => ['label' => 'Jurnal', 'prefix' => 'JU'],
        'expense' => ['label' => 'Pencatatan Beban', 'prefix' => 'EXP'],
        'payroll_run' => ['label' => 'Pencatatan Gaji', 'prefix' => 'PAY'],
        'cash_receipt' => ['label' => 'Penerimaan Kas/Bank', 'prefix' => 'KM'],
        'cash_payment' => ['label' => 'Pembayaran Kas/Bank', 'prefix' => 'KK'],
        'bank_transfer' => ['label' => 'Transfer Bank', 'prefix' => 'TB'],
        'sales_quotation' => ['label' => 'Penawaran Penjualan', 'prefix' => 'QT'],
        'sales_order' => ['label' => 'Pesanan Penjualan', 'prefix' => 'SO'],
        'sales_delivery' => ['label' => 'Pengiriman Pesanan', 'prefix' => 'DO'],
        'sales_down_payment' => ['label' => 'Uang Muka Penjualan', 'prefix' => 'DPS'],
        'sales_invoice' => ['label' => 'Faktur Penjualan', 'prefix' => 'INV'],
        'sales_receipt' => ['label' => 'Penerimaan Penjualan', 'prefix' => 'RC'],
        'sales_return' => ['label' => 'Retur Penjualan', 'prefix' => 'SR'],
        'purchase_order' => ['label' => 'Pesanan Pembelian', 'prefix' => 'PO'],
        'goods_receipt' => ['label' => 'Penerimaan Barang', 'prefix' => 'GR'],
        'purchase_down_payment' => ['label' => 'Uang Muka Pembelian', 'prefix' => 'DPP'],
        'purchase_invoice' => ['label' => 'Faktur Pembelian', 'prefix' => 'PI'],
        'purchase_payment' => ['label' => 'Pembayaran Pembelian', 'prefix' => 'PY'],
        'purchase_return' => ['label' => 'Retur Pembelian', 'prefix' => 'PR'],
        'payment_order' => ['label' => 'Perintah Pembayaran', 'prefix' => 'PMO'],
        'item_request' => ['label' => 'Permintaan Barang', 'prefix' => 'REQ'],
        'stock_transfer' => ['label' => 'Pemindahan Barang', 'prefix' => 'TRF'],
        'stock_adjustment' => ['label' => 'Penyesuaian Persediaan', 'prefix' => 'ADJ'],
        'stock_opname_order' => ['label' => 'Perintah Stok Opname', 'prefix' => 'SOP'],
        'stock_opname_result' => ['label' => 'Hasil Stok Opname', 'prefix' => 'SOR'],
        'work_order' => ['label' => 'Pekerjaan Pesanan', 'prefix' => 'WO'],
        'asset_disposal' => ['label' => 'Disposisi Aset Tetap', 'prefix' => 'DSP'],
    ],
];
