# patch_7d2: Disposisi Aset Tetap (7D-2)
Basis: laravel_accounting_backend_1.zip (sudah berisi 7C dan 7D-1). BELUM pernah dijalankan: sandbox tanpa PHP/MySQL (hanya keseimbangan kurung dicek).

## Pasang
1. Ekstrak di root proyek (timpa). Berkas yang MENIMPA kode lama: `app/Modules/FixedAssets/FixedAssetsServiceProvider.php`,
   `app/Modules/FixedAssets/routes.php`, `app/Modules/FixedAssets/Assets/FixedAssetService.php`, dan docs/*.md (hanya penambahan).
2. `php artisan migrate`  (migrasi baru 2026_11_21_140001_create_asset_disposals)
3. `php artisan route:clear`
4. `php artisan test --filter=AssetDisposalTest` lalu `php artisan test` penuh di MySQL 8 asli.

## Isi
Migrasi; Models/AssetDisposal; Disposal/{DisposalCalculator, AssetDisposalService, AssetDisposalPostingHandler}; Http/Controllers/AssetDisposalController;
provider mendaftarkan handler + aturan jurnal ke orkestrator; routes `asset-disposals` (hak `disposisi_aset_tetap`); tes AssetDisposalTest.

## Catatan keamanan
Semua query lewat model BelongsToCompany atau DB::table dengan company_id; FK komposit (id, company_id); uang BCMath/DECIMAL(19,4);
posting dan void hanya lewat PostingOrchestrator (hak per dokumen, periode terbuka, satu transaksi, audit log).
Keputusan terbuka ada di docs/DECISIONS.md bagian 7D-2.
