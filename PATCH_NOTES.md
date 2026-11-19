# PATCH 7C-2 / 7C-3 - Sesi Rekonsiliasi Bank, ALG-09, checklist Akhir Bulan

Basis: laravel_accounting_backend_step7a.zip + patch_7b.zip + patch_7c1.zip. Salin ke akar proyek (struktur folder sama).

## File BARU
- app/Modules/CashBank/Models/BankReconciliation.php, ReconciliationItem.php
- app/Modules/CashBank/Reconciliation/ReconciliationMatcher.php, BankReconciliationService.php
- app/Modules/CashBank/Http/Controllers/BankReconciliationController.php
- tests/Feature/CashBank/ReconciliationMatcherTest.php, BankReconciliationTest.php

## File DIUBAH (menimpa; asalnya dari patch_7c1 dan patch_7b, dibaca penuh)
- app/Modules/CashBank/Reconciliation/routes.php (rute koran tetap + 11 rute `bank-reconciliations`)
- app/Modules/Accounting/Periods/Close/PeriodCloseService.php (item rekonsiliasi_bank: "na" -> ok/warning; +1 metode)
- tests/Feature/Accounting/PeriodCloseTest.php (asersi "na" rekonsiliasi diganti "ok"; +1 tes)
- docs/*.md

## Cara pasang
1. Salin isi zip ke proyek. Tidak ada migrasi, composer, atau provider baru.
2. `php artisan route:clear` lalu `php artisan test --filter=Reconciliation` dan `--filter=PeriodClose`.

## Rute (prefix api/v1, fitur rekonsiliasi_bank)
GET/POST bank-reconciliations; GET/DELETE bank-reconciliations/{id}; GET {id}/suggestions; POST {id}/auto-match;
POST {id}/matches; DELETE {id}/matches/{itemId}; POST {id}/transactions; POST {id}/complete; POST {id}/reopen.

## BELUM terverifikasi
Semua tes belum pernah dijalankan (sandbox tanpa PHP/MySQL); hanya keseimbangan kurung yang dicek.
Tidak dikerjakan: ALG-09 1-ke-banyak (butuh migrasi). Asumsi rumus tokenOverlap tercatat di DECISIONS.md.
