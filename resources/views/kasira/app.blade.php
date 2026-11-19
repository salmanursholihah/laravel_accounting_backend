<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Kasira</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('kasira/css/kasira.css') }}?v={{ filemtime(public_path('kasira/css/kasira.css')) }}">
</head>
<body>
<div class="app">
<nav class="rail" id="rail" aria-label="Modul"></nav><div class="scrim" id="scrim"></div><aside class="fly" id="fly" aria-label="Fitur"></aside>
<div class="main"><header class="nav"><button class="co" id="co" aria-label="Ganti perusahaan"><i id="coi"></i><span id="con"></span> ▾</button><div class="sr"><input id="q" placeholder="Cari fitur atau dokumen" aria-label="Cari fitur"><kbd>Ctrl K</kbd><div class="dd" id="dd"></div></div>
<div class="r"><div style="position:relative"><button class="mk" id="mk">+ Buat cepat</button><div class="qd" id="qd"></div></div><button class="ib" id="bl" aria-label="Persetujuan"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 1112 0c0 6 2 7 2 7H4s2-1 2-7zM10 20h4"/></svg><span class="n"></span></button><button class="ib" id="th" aria-label="Ganti tema">◐</button><button class="av" id="av" aria-label="Akun" style="background:none;border:0;cursor:pointer;text-align:left"></button></div></header><main class="page" id="pg"></main></div>
</div>
<div class="ov" id="ov"><div class="dlg" id="dlg"></div></div><div class="toast" id="ts"></div>

{{-- Urutan penting: katalog fitur, klien API, pembantu dasar, lalu shell. Layar baru ditambahkan setelah shell.js. --}}
<script src="{{ asset('kasira/js/features.js') }}?v={{ filemtime(public_path('kasira/js/features.js')) }}"></script>
<script src="{{ asset('kasira/js/api.js') }}?v={{ filemtime(public_path('kasira/js/api.js')) }}"></script>
<script src="{{ asset('kasira/js/core.js') }}?v={{ filemtime(public_path('kasira/js/core.js')) }}"></script>
<script src="{{ asset('kasira/js/shell.js') }}?v={{ filemtime(public_path('kasira/js/shell.js')) }}"></script>
<script src="{{ asset('kasira/js/dashboard.js') }}?v={{ filemtime(public_path('kasira/js/dashboard.js')) }}"></script>
<script src="{{ asset('kasira/js/screens/master.js') }}?v={{ filemtime(public_path('kasira/js/screens/master.js')) }}"></script>
<script src="{{ asset('kasira/js/screens/invoice.js') }}?v={{ filemtime(public_path('kasira/js/screens/invoice.js')) }}"></script>
<script src="{{ asset('kasira/js/screens/payment.js') }}?v={{ filemtime(public_path('kasira/js/screens/payment.js')) }}"></script>
<script src="{{ asset('kasira/js/screens/cash.js') }}?v={{ filemtime(public_path('kasira/js/screens/cash.js')) }}"></script>
<script src="{{ asset('kasira/js/screens/journal.js') }}?v={{ filemtime(public_path('kasira/js/screens/journal.js')) }}"></script>
<script src="{{ asset('kasira/js/screens/adjust.js') }}?v={{ filemtime(public_path('kasira/js/screens/adjust.js')) }}"></script>
<script src="{{ asset('kasira/js/screens/report.js') }}?v={{ filemtime(public_path('kasira/js/screens/report.js')) }}"></script>
<script src="{{ asset('kasira/js/screens/stock.js') }}?v={{ filemtime(public_path('kasira/js/screens/stock.js')) }}"></script>
<script src="{{ asset('kasira/js/screens/closing.js') }}?v={{ filemtime(public_path('kasira/js/screens/closing.js')) }}"></script>
</body>
</html>
