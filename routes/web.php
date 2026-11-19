<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
| Kasira berjalan sebagai satu halaman (SPA) di /app. Navigasi di dalamnya memakai hash (#/faktur_penjualan),
| jadi semua data tetap lewat API /api/v1 dengan sesi cookie (Sanctum SPA) dan header X-Company-Id.
*/

Route::redirect('/', '/app');
Route::view('/app/{any?}', 'kasira.app')->where('any', '.*');
