/* Uji layar Laporan (langkah 4e) di Chromium sungguhan dengan server tiruan.
   Server tiruan mengikuti bentuk respons backend (AgingReportService, FinancialStatementService,
   AccountHistoryService, AccountBalanceService, PeriodController). PHP/MySQL tidak dipakai.
   Jalankan:  npm i playwright && npx playwright install chromium
              node tests/js/laporan.e2e.cjs <folder-proyek-laravel> [folder-keluaran-screenshot]  */
const http = require("http"), fs = require("fs"), path = require("path");
const { chromium } = require("playwright");

const ROOT = path.resolve(process.argv[2] || "."), OUT = process.argv[3] ? path.resolve(process.argv[3]) : null;
if (OUT) fs.mkdirSync(OUT, { recursive: true });

/* ---------- fixture ---------- */
const PERIODS = Array.from({ length: 12 }, (_, i) => { const m = i + 1, z = String(m).padStart(2, "0"); return { id: m, bulan: m, mulai: `2026-${z}-01`, selesai: `2026-${z}-${new Date(2026, m, 0).getDate()}`, status: m < 10 ? "closed" : "open" }; });
const cell = (j, p) => p === undefined ? { jumlah: j } : { jumlah: j, pembanding: p, selisih: (+j - +p).toFixed(4), persen: +p === 0 ? null : ((+j - +p) / Math.abs(+p) * 100).toFixed(2) };
const rowsOf = (list, cmp) => list.map(([id, kode, nama, j, p]) => ({ account_id: id, kode, nama, ...cell(j, cmp ? p : undefined) }));
const sec = (list, cmp) => { const rows = rowsOf(list, cmp); const t = (k) => list.reduce((a, r) => a + +r[k], 0).toFixed(4); return { rows, total: cmp ? cell(t(3), t(4)) : cell(t(3)) }; };
const balanceSheet = (q) => {
  const cmp = !!q.get("bandingkan");
  const aset = sec([[101, "1-1100", "Kas", "5000000.0000", "4000000.0000"], [102, "1-1200", "Piutang Usaha", "7000000.5000", "6000000.0000"]], cmp);
  const kew = sec([[201, "2-1100", "Utang Usaha", "3000000.0000", "2500000.0000"]], cmp);
  const eks = sec([[301, "3-1100", "Modal", "8000000.0000", "8000000.0000"], [null, null, "Laba Berjalan (belum ditutup)", "1000000.5000", "-500000.0000"]], cmp);
  const bad = q.get("period_id") === "9";
  return { periode: { id: +q.get("period_id"), mulai: "2026-11-01", selesai: "2026-11-30" }, per_tanggal: "2026-11-30", mode: q.get("mode"), pembanding: cmp ? { per_tanggal: "2026-10-31", periode_id: 10 } : null, catatan: null, aset, kewajiban: kew, ekuitas: eks, seimbang: !bad, selisih: bad ? "1500.0000" : "0.0000" };
};
const incomeStatement = (q) => {
  const cmp = !!q.get("bandingkan");
  return { periode: { dari: "2026-01-01", sampai: "2026-11-30" }, mode: q.get("mode"), pembanding: cmp ? { dari: "2025-01-01", sampai: "2025-11-30" } : null, catatan: null,
    pendapatan: sec([[401, "4-1100", "Penjualan", "120000000.0000", "100000000.0000"]], cmp), hpp: sec([[501, "5-1100", "HPP", "70000000.0000", "60000000.0000"]], cmp),
    laba_kotor: cmp ? cell("50000000.0000", "40000000.0000") : cell("50000000.0000"), beban_operasional: sec([[601, "6-1100", "Beban Gaji", "60000000.0000", "35000000.0000"]], cmp),
    laba_usaha: cmp ? cell("-10000000.0000", "5000000.0000") : cell("-10000000.0000"), pendapatan_lain: sec([], cmp), beban_lain: sec([], cmp), laba_bersih: cmp ? cell("-10000000.0000", "5000000.0000") : cell("-10000000.0000") };
};
const trialBalance = (q) => ({ periode: { id: +q.get("period_id"), mulai: "2026-11-01", selesai: "2026-11-30" }, sebelum_penutup: !q.get("termasuk_penutup"),
  rows: [{ account_id: 101, kode: "1-1100", nama: "Kas", tipe: "asset", awal_debit: "1000.0000", awal_kredit: "0.0000", mutasi_debit: "500.0000", mutasi_kredit: "200.0000", akhir_debit: "1300.0000", akhir_kredit: "0.0000" },
    { account_id: 301, kode: "3-1100", nama: "Modal", tipe: "equity", awal_debit: "0.0000", awal_kredit: "1000.0000", mutasi_debit: "0.0000", mutasi_kredit: "300.0000", akhir_debit: "0.0000", akhir_kredit: "1300.0000" }],
  total: { awal_debit: "1000.0000", awal_kredit: "1000.0000", mutasi_debit: "500.0000", mutasi_kredit: "500.0000", akhir_debit: "1300.0000", akhir_kredit: "1300.0000" }, seimbang: true, selisih: "0.0000" });
const glRow = (i) => ({ account_id: 100 + i, kode: "1-" + (1000 + i), nama: "Akun " + i, tipe: "asset", saldo_normal: "debit", saldo_awal: "0.0000", debit: i + ".0000", kredit: "0.0000", saldo_akhir: i + ".0000" });
const generalLedger = (q) => { const first = !q.get("cursor"); return { periode: { dari: q.get("dari"), sampai: q.get("sampai") }, sebelum_penutup: true, rows: first ? [1, 2, 3].map(glRow) : [4, 5].map(glRow), next_cursor: first ? "1-1003" : null }; };
const history = (q) => {
  const first = !q.get("cursor"), mk = (n, tgl, d, k, s) => ({ line_id: n, journal_id: n, nomor: "JU-" + n, tanggal: tgl, tipe: n === 2 ? "auto" : "general", memo: n === 1 ? "=HYPERLINK(\"x\")" : "baris " + n, debit: d, kredit: k, saldo: s });
  return { account: { id: +q.get("account_id"), kode: "1-1100", nama: "Kas", saldo_normal: "debit" }, dari: q.get("dari"), sampai: q.get("sampai"), saldo_awal: "1000.0000", total_debit: "900.0000", total_kredit: "400.0000", saldo_akhir: "1500.0000",
    rows: first ? [mk(1, "2026-11-02", "500.0000", "0.0000", "1500.0000"), mk(2, "2026-11-03", "0.0000", "400.0000", "1100.0000")] : [mk(3, "2026-11-04", "400.0000", "0.0000", "1500.0000")], next_cursor: first ? "2026-11-03|2" : null };
};
const aging = (sisi, q) => {
  const rinci = q.get("mode") === "rinci", B = (a, b, c, d, e) => ({ belum_jatuh_tempo: a, d1_30: b, d31_60: c, d61_90: d, d_lebih_90: e });
  const k1 = { kode: "C-001", nama: "Toko Maju", buckets: B("1000000.0000", "500000.0000", "0.0000", "0.0000", "0.0000"), total: "1500000.0000", jumlah_faktur: 2,
    faktur: rinci ? [{ nomor: "INV-1", tanggal: "2026-10-01", jatuh_tempo: "2026-11-30", hari_lewat_jatuh_tempo: 0, bucket: "belum_jatuh_tempo", total: "1000000.0000", sisa: "1000000.0000" }, { nomor: "INV-2", tanggal: "2026-09-01", jatuh_tempo: "2026-11-01", hari_lewat_jatuh_tempo: 18, bucket: "d1_30", total: "500000.0000", sisa: "500000.0000" }] : [] };
  const k2 = { kode: "C-002", nama: "<b>Warung</b> Sejahtera", buckets: B("0.0000", "0.0000", "0.0000", "0.0000", "250000.0000"), total: "250000.0000", jumlah_faktur: 1,
    faktur: rinci ? [{ nomor: "INV-3", tanggal: "2026-05-01", jatuh_tempo: "2026-06-01", hari_lewat_jatuh_tempo: 171, bucket: "d_lebih_90", total: "250000.0000", sisa: "250000.0000" }] : [] };
  return { sisi, per_tanggal: q.get("per_tanggal"), mode: q.get("mode"), bucket_keys: ["belum_jatuh_tempo", "d1_30", "d31_60", "d61_90", "d_lebih_90"], kontak: [k1, k2],
    total: { buckets: B("1000000.0000", "500000.0000", "0.0000", "0.0000", "250000.0000"), total: "1750000.0000", jumlah_faktur: 3 } };
};
const ACCOUNTS = [{ id: 100, kode: "1-0000", nama: "Aset", is_header: true, is_aktif: true }, { id: 101, kode: "1-1100", nama: "Kas", is_header: false, is_aktif: true }, { id: 102, kode: "1-1200", nama: "Piutang Usaha", is_header: false, is_aktif: true }];

const ALL = ["laporan_keuangan", "histori_akun", "umur_piutang", "umur_utang", "daftar_laporan", "akun_perkiraan", "proses_akhir_bulan", "dashboard"];
const PERMS = {
  Owner: ALL.flatMap(f => ["view", "export", "print"].map(a => ({ fitur: f, aksi: a }))),
  Kasir: [{ fitur: "daftar_laporan", aksi: "view" }, { fitur: "umur_piutang", aksi: "view" }, { fitur: "dashboard", aksi: "view" }],
  /* Admin tanpa hak lihat Akun Perkiraan: histori hanya bisa dibuka lewat penelusuran */
  TanpaAkun: ALL.filter(f => f !== "akun_perkiraan").flatMap(f => ["view"].map(a => ({ fitur: f, aksi: a }))),
};

/* ---------- server tiruan ---------- */
let ROLE = "Owner", LOG = [], DELAY = {}, FAIL = {};
const MIME = { ".js": "text/javascript", ".css": "text/css" };
const server = http.createServer((req, res) => {
  const u = new URL(req.url, "http://x"), p = u.pathname, q = u.searchParams;
  const json = (o, code = 200) => { res.writeHead(code, { "Content-Type": "application/json" }); res.end(JSON.stringify(o)); };
  if (p === "/__role") { ROLE = q.get("r"); return json({}); }
  if (p === "/__reset") { LOG = []; DELAY = {}; FAIL = {}; return json({}); }
  if (p === "/__log") return json(LOG);
  if (p === "/__delay") { DELAY[q.get("p")] = +q.get("ms"); return json({}); }
  if (p === "/__fail") { FAIL[q.get("p")] = { code: +q.get("code"), msg: q.get("msg") }; return json({}); }
  if (p === "/app") {
    const html = fs.readFileSync(path.join(ROOT, "resources/views/kasira/app.blade.php"), "utf8").replace(/\{\{ asset\('([^']+)'\) \}\}\?v=\{\{[^}]*\}\}/g, "/$1?v=1");
    res.writeHead(200, { "Content-Type": "text/html" }); return res.end(html);
  }
  if (p.startsWith("/kasira/")) {
    const f = path.join(ROOT, "public", p);
    if (!f.startsWith(path.join(ROOT, "public")) || !fs.existsSync(f)) { res.writeHead(404); return res.end(); }
    res.writeHead(200, { "Content-Type": MIME[path.extname(f)] || "text/plain" }); return res.end(fs.readFileSync(f));
  }
  if (p === "/sanctum/csrf-cookie") { res.writeHead(204); return res.end(); }
  if (!p.startsWith("/api/v1/")) { res.writeHead(404); return res.end(); }
  const a = p.slice(8);
  if (!a.startsWith("auth/me") && !a.startsWith("session") && !a.startsWith("company") && a !== "dashboard") LOG.push(a + "?" + q.toString());
  const done = () => {
    const fl = FAIL[a]; if (fl) { delete FAIL[a]; return json({ message: fl.msg }, fl.code); }
    switch (true) {
      case a === "auth/me": return json({ data: { id: 1, name: "Budi Santoso", email: "budi@contoh.id", companies: [{ public_id: "co1", nama: "PT Contoh Abadi", role: ROLE === "Kasir" ? "Kasir" : "Owner" }] } });
      case a === "session/company": return json({ data: {} });
      case a === "company": return json({ data: { nama: "PT Contoh Abadi" } });
      case a === "company/permissions": return json({ data: PERMS[ROLE] });
      case a === "dashboard": return json({ data: { tanggal: "2026-11-19", widgets: {} } });
      case a === "fiscal-years": return json({ data: [{ id: 1, tahun: 2026, mulai: "2026-01-01", selesai: "2026-12-31", status: "open", periode: PERIODS }] });
      case a === "accounts": return ROLE === "TanpaAkun" ? json({ message: "Tidak punya hak." }, 403) : json({ data: ACCOUNTS });
      case a === "reports/ar-aging": return json({ data: aging("piutang", q) });
      case a === "reports/ap-aging": return json({ data: aging("utang", q) });
      case a === "reports/balance-sheet": return json({ data: balanceSheet(q) });
      case a === "reports/income-statement": return json({ data: incomeStatement(q) });
      case a === "reports/trial-balance": return json({ data: trialBalance(q) });
      case a === "reports/general-ledger": return json({ data: generalLedger(q) });
      case a === "reports/account-history": return json({ data: history(q) });
      default: return json({ message: "tidak ada: " + a }, 404);
    }
  };
  const d = DELAY[a]; if (d) { delete DELAY[a]; setTimeout(done, d); } else done();
});

/* ---------- kerangka uji ---------- */
let pass = 0, fail = 0; const failures = [];
const ok = (cond, name) => { cond ? pass++ : (fail++, failures.push(name)); console.log((cond ? "  ok   " : "  GAGAL ") + name); };
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  await new Promise(r => server.listen(0, "127.0.0.1", r));
  const base = "http://127.0.0.1:" + server.address().port;
  const get = async u => (await fetch(base + u)).json();
  const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
  const errors = [];
  async function open(role, hash, vp) {
    await get("/__role?r=" + role); await get("/__reset");
    const ctx = await browser.newContext({ viewport: vp || { width: 1280, height: 900 }, acceptDownloads: true, deviceScaleFactor: 1 });
    await ctx.route(u => !u.href.startsWith(base), r => r.abort());          // font Google dll.
    const pgx = await ctx.newPage();
    pgx.on("pageerror", e => errors.push(hash + ": " + e.message));
    pgx.on("console", m => { if (m.type() === "error" && !/Failed to load resource|net::ERR/.test(m.text())) errors.push(hash + ": " + m.text()); });
    await pgx.goto(base + "/app#/" + hash);
    await pgx.waitForSelector("#pg .hd", { timeout: 5000 });
    return pgx;
  }
  async function openRep(role, key, vp) {
    const pgx = await open(role, "daftar_laporan", vp);
    await pgx.waitForSelector("#rh");
    if (key) await pgx.click(`[data-r=${key}]`);
    return pgx;
  }
  const text = (pgx, sel) => pgx.locator(sel).innerText();
  const log = async () => get("/__log");
  const lastReq = async (prefix) => (await log()).filter(x => x.startsWith(prefix)).pop() || "";
  const shot = async (pgx, name) => { if (OUT) await pgx.screenshot({ path: path.join(OUT, name + ".png"), fullPage: false }); };

  /* ===== Umur Piutang ===== */
  console.log("Umur Piutang");
  let p = await openRep("Owner", "umur_piutang");
  await p.waitForSelector("#rb .rtb");
  ok(/per_tanggal=2\d{3}-\d\d-\d\d&mode=ringkas/.test(await lastReq("reports/ar-aging")), "meminta ar-aging ringkas per hari ini");
  ok((await p.locator("#rb tr.ct").count()) === 2, "dua baris kontak");
  const body1 = await text(p, "#rb");
  ok(body1.includes("1.750.000"), "total keseluruhan 1.750.000");
  ok(body1.includes("3 faktur terbuka"), "jumlah faktur terbuka dari server");
  ok((await p.locator("#rb .rbar i").count()) === 5, "bar komposisi 5 segmen");
  ok((await p.locator("#rb td b b").count()) === 0, "nama kontak berisi HTML di-escape (tidak jadi elemen)");
  ok((await text(p, "#rb")).includes("<b>Warung</b> Sejahtera"), "nama kontak tampil sebagai teks");
  await shot(p, "umur_piutang_ringkas");
  await p.selectOption("#fm", "rinci"); await p.waitForSelector("#rb tr.sub");
  ok((await p.locator("#rb tr.sub").count()) === 3, "mode rinci menampilkan 3 faktur");
  ok((await text(p, "#rb")).includes("lewat 18 hari"), "faktur menampilkan hari lewat jatuh tempo");
  await shot(p, "umur_piutang_rinci");
  await p.fill("#fq", "maju");
  ok((await p.locator("#rb tr.ct").count()) === 1 && (await text(p, "#rb")).includes("Menampilkan 1 dari 2"), "cari kontak menyaring di klien");
  const reqsBefore = (await log()).length; await p.fill("#fq", "");
  ok((await log()).length === reqsBefore, "mengetik pencarian tidak memanggil server");
  await p.fill("#fp", "2026-10-31"); await p.waitForFunction(() => document.querySelector("#rb")?.innerText.includes("31 Okt 2026"));
  ok((await lastReq("reports/ar-aging")).includes("per_tanggal=2026-10-31&mode=rinci"), "ganti tanggal memuat ulang dengan tanggal dan mode");
  await get("/__fail?p=reports/ar-aging&code=422&msg=" + encodeURIComponent("Tanggal tidak valid."));
  await p.fill("#fp", "2026-10-30"); await p.waitForSelector("#rt");
  ok((await text(p, "#rb")).includes("Tanggal tidak valid."), "galat 422 tampil dengan tombol coba lagi");
  await p.click("#rt"); await p.waitForSelector("#rb .rtb");
  ok(true, "coba lagi memuat ulang");
  /* download CSV */
  const dl = p.waitForEvent("download"); await p.click("[data-x]"); const d = await dl;
  const csv = fs.readFileSync(await d.path(), "utf8");
  ok(csv.charCodeAt(0) === 0xFEFF && csv.includes("Kode;Nama;Nomor faktur"), "CSV: BOM dan pemisah titik koma");
  ok(csv.includes("INV-2;2026-09-01;2026-11-01;;500000"), "CSV: faktur rinci, angka bulat tanpa pemisah ribuan");
  ok(/;1750000\r?\n?$/.test(csv.trim()), "CSV: baris total");
  ok(d.suggestedFilename().startsWith("umur_piutang_"), "CSV: nama berkas");
  /* balapan: respons lama tidak boleh menimpa yang baru */
  await get("/__delay?p=reports/ar-aging&ms=500");
  await p.fill("#fp", "2026-01-31");
  await sleep(50); await p.fill("#fp", "2026-02-28");
  await sleep(900);
  ok((await text(p, "#rb")).includes("28 Feb 2026") && !(await text(p, "#rb")).includes("31 Jan 2026"), "respons lambat dari permintaan lama diabaikan");
  await p.context().close();

  /* ===== Umur Utang ===== */
  console.log("Umur Utang");
  p = await openRep("Owner", "umur_utang"); await p.waitForSelector("#rb .rtb");
  ok((await lastReq("reports/")).startsWith("reports/ap-aging"), "memakai endpoint ap-aging");
  ok((await text(p, "#rb")).includes("Total semua pemasok") && (await text(p, "#rb th:nth-child(1)").catch(() => "")) !== undefined, "label pemasok");
  await p.context().close();

  /* ===== Kasir: hanya Umur Piutang ===== */
  console.log("Peran Kasir");
  p = await openRep("Kasir", null); await p.waitForSelector("#rb .rtb");
  const nav = await text(p, ".rnav");
  ok(nav.includes("Umur Piutang") && !nav.includes("Umur Utang") && !nav.includes("Neraca") && !nav.includes("Histori Akun"), "Daftar Laporan hanya memuat laporan yang boleh dibuka");
  ok((await text(p, "#rh")).includes("Toko Maju"), "Umur Piutang terbuka otomatis sebagai satu-satunya laporan");
  ok(!(await p.locator("[data-x]").count()) && !(await p.locator("[data-p]").count()), "tanpa hak ekspor/cetak tombolnya tidak ada");
  await p.evaluate(() => { location.hash = "#/histori_akun"; }); await p.waitForSelector("#pg .bd.er");
  ok((await text(p, "#pg")).includes("Tidak ada akses"), "Histori Akun ditolak untuk Kasir");
  await p.context().close();

  /* ===== Laporan Keuangan ===== */
  console.log("Neraca");
  p = await openRep("Owner", null); await p.waitForSelector("#rb .rtb");
  ok((await lastReq("reports/")).startsWith("reports/balance-sheet?period_id=11&mode=rinci"), "default Neraca = periode berjalan (Nov) dan rinci");
  const ner = await text(p, "#rb");
  ok(ner.includes("Seimbang") && ner.includes("Piutang Usaha"), "neraca seimbang dan menampilkan akun");
  ok(ner.includes("7.000.001") , "angka dibulatkan ke rupiah (7.000.000,5 -> 7.000.001)");
  ok(ner.includes("Total kewajiban dan ekuitas") && ner.includes("12.000.001"), "total kewajiban + ekuitas dari dua total server");
  await shot(p, "neraca");
  await p.selectOption("#fc", "sebelumnya"); await p.waitForSelector("#rb th:has-text('Pembanding')");
  ok((await lastReq("reports/balance-sheet")).includes("bandingkan=sebelumnya"), "pembanding dikirim ke server");
  ok((await text(p, "#rb")).includes("(500.000)"), "angka negatif memakai tanda kurung");
  await shot(p, "neraca_banding");
  await p.selectOption("#fc", ""); await p.selectOption("#fm", "ringkas");
  await p.waitForFunction(() => document.querySelector("#rb")?.dataset.x !== "z" && !document.querySelector("#rb tr[data-acc]"));
  ok((await p.locator("#rb tr[data-acc]").count()) === 0, "mode ringkas: telusur dimatikan (baris gabungan induk)");
  await p.selectOption("#fm", "rinci"); await p.waitForSelector("#rb tr[data-acc]");
  await p.selectOption("#fp", "9"); await p.waitForSelector("#rb .warn.e");
  ok((await text(p, "#rb")).includes("tidak seimbang, selisih 1.500"), "neraca tidak seimbang memunculkan peringatan");
  await p.selectOption("#fp", "11"); await p.waitForSelector("#rb tr[data-acc]");

  console.log("Penelusuran ke Histori Akun");
  await p.locator("#rb tr[data-acc]").first().click(); await p.waitForSelector("#rb .rsum");
  ok(p.url().endsWith("#/histori_akun"), "klik akun membuka Histori Akun");
  ok((await p.inputValue("#fa")) === "101" && (await p.inputValue("#fd")) === "2026-11-01" && (await p.inputValue("#fs")) === "2026-11-30", "akun dan rentang terisi dari laporan");
  ok((await lastReq("reports/account-history")).includes("account_id=101&dari=2026-11-01&sampai=2026-11-30&limit=100"), "histori diminta untuk akun dan rentang itu");
  await p.click("#bk"); await p.waitForSelector("#rh #rb .rtb");
  ok(p.url().endsWith("#/daftar_laporan") && (await p.locator(".rnav button.on").innerText()) === "Neraca", "Kembali ke laporan: Daftar Laporan dengan Neraca terpilih");
  await p.context().close();

  console.log("Laba Rugi");
  p = await openRep("Owner", "labarugi"); await p.waitForSelector("#rb .rtb");
  ok((await lastReq("reports/income-statement")).includes("dari_period_id=1&sampai_period_id=11"), "default = awal tahun buku sampai periode berjalan");
  const lr = await text(p, "#rb");
  ok(lr.includes("Laba bersih") && lr.includes("(10.000.000)") && !lr.includes("Pendapatan lain"), "laba bersih negatif dalam kurung; bagian kosong disembunyikan");
  await shot(p, "laba_rugi");
  const nBefore = (await log()).length;
  await p.selectOption("#fd", "11"); await p.selectOption("#fs", "3");
  await p.waitForSelector("#rb .warn.e");
  ok((await text(p, "#rb")).includes("Periode akhir tidak boleh lebih awal"), "rentang terbalik ditolak di layar");
  ok((await log()).slice(nBefore).filter(x => x.startsWith("reports/income-statement") && x.includes("dari_period_id=11&sampai_period_id=3")).length === 0, "rentang terbalik tidak dikirim ke server");
  await p.selectOption("#fs", "11"); await p.selectOption("#fc", "tahun_lalu"); await p.waitForSelector("#rb th:has-text('Pembanding')");
  ok((await text(p, "#rb")).includes("20,0%"), "persen pembanding diformat gaya Indonesia");

  console.log("Neraca Saldo");
  await p.click("[data-r=neracasaldo]"); await p.waitForSelector("#rb .rtb");
  ok((await text(p, "#rb")).includes("Belum termasuk jurnal penutup"), "catatan sebelum penutup");
  await p.check("#fk"); await p.waitForFunction(() => document.querySelector("#rb .rtb") && !document.querySelector("#rb")?.innerText.includes("Belum termasuk jurnal penutup"));
  ok((await lastReq("reports/trial-balance")).includes("termasuk_penutup=1"), "centang penutup dikirim");
  ok((await text(p, "#rb")).includes("Seimbang"), "neraca saldo seimbang");

  console.log("Buku Besar");
  await p.click("[data-r=bukubesar]"); await p.waitForSelector("#rb .rtb");
  ok((await lastReq("reports/general-ledger")).includes("dari=2026-11-01&sampai=2026-11-30&limit=100"), "default rentang = periode berjalan");
  ok((await p.locator("#rb tbody tr").count()) === 3, "halaman pertama 3 akun");
  await p.click("#more"); await p.waitForFunction(() => document.querySelectorAll("#rb tbody tr").length === 5);
  ok((await lastReq("reports/general-ledger")).includes("cursor=1-1003"), "muat lainnya memakai cursor dari server");
  ok(!(await p.locator("#more").count()) && (await text(p, "#rb")).includes("(semua)"), "habis: tombol hilang");
  await p.fill("#fbd", "2026-12-01"); await p.waitForSelector("#rb .warn.e");
  ok((await text(p, "#rb")).includes("Tanggal akhir tidak boleh lebih awal"), "tanggal terbalik ditolak di layar");
  const dl2 = p.waitForEvent("download"); await p.fill("#fbd", "2026-11-01"); await p.waitForSelector("#rb .rtb");
  await p.click("[data-x]"); const csv2 = fs.readFileSync(await (await dl2).path(), "utf8");
  ok(csv2.includes("Kode;Nama;Saldo awal;Debit;Kredit;Saldo akhir") && csv2.includes("1-1001;Akun 1;0;1;0;1"), "CSV Buku Besar");
  await p.context().close();

  console.log("Histori Akun");
  p = await open("Owner", "histori_akun"); await p.waitForSelector("#fa");
  ok(!(await p.locator("#fa option", { hasText: "1-0000" }).count()), "akun header tidak ada di pilihan");
  ok((await text(p, "#rb")).includes("Pilih akun"), "belum memilih akun: petunjuk");
  await p.selectOption("#fa", "101"); await p.waitForSelector("#rb .rsum");
  ok((await p.locator("#rb tbody tr").count()) === 1 + 2 + 1, "baris: saldo awal + 2 mutasi + total");
  ok((await text(p, "#rb .rsum")).includes("1.500"), "ringkasan saldo akhir dari server");
  await p.click("#more"); await p.waitForFunction(() => document.querySelectorAll("#rb tbody tr").length === 5);
  ok((await lastReq("reports/account-history")).includes("cursor=2026-11-03%7C2"), "halaman berikut memakai cursor");
  await shot(p, "histori_akun");
  const dl3 = p.waitForEvent("download"); await p.click("[data-x]"); const csv3 = fs.readFileSync(await (await dl3).path(), "utf8");
  ok(csv3.includes("'=HYPERLINK") && !/;=HYPERLINK/.test(csv3), "CSV: teks berawalan = diberi tanda ' (cegah rumus)");
  await get("/__fail?p=reports/account-history&code=404&msg=x");
  await p.selectOption("#fa", "102"); await p.waitForSelector("#rt");
  ok((await text(p, "#rb")).includes("Akun tidak ditemukan"), "404 akun tampil sebagai pesan jelas");
  await p.context().close();

  console.log("Histori Akun tanpa hak lihat Akun Perkiraan");
  p = await openRep("TanpaAkun", null); await p.waitForSelector("#rb .rtb");
  await p.locator("#rb tr[data-acc]").first().click(); await p.waitForSelector("#rb .rsum");
  ok(await p.locator("#fa").isDisabled() && (await p.inputValue("#fa")) === "101", "pilihan akun terkunci tapi akun hasil penelusuran tetap terpilih");
  ok(!(await p.locator("[data-x]").count()), "tanpa hak ekspor: tidak ada tombol CSV");
  await p.context().close();

  console.log("Daftar Laporan (Owner)");
  p = await openRep("Owner", null);
  ok((await p.locator(".rnav button").count()) === 7, "Owner melihat 7 entri (6 laporan + Histori Akun)");
  await p.click("[data-r=bukubesar]"); await p.waitForSelector("#rh #rb .rtb");
  ok((await p.locator(".rnav button.on").innerText()) === "Buku Besar", "klik laporan menandai pilihan");
  await p.click("[data-r=histori_akun]"); await p.waitForSelector("#fa");
  ok(p.url().endsWith("#/histori_akun"), "entri Histori Akun membuka fiturnya");
  await p.context().close();

  /* tampilan ponsel + gelap */
  p = await openRep("Owner", "umur_piutang", { width: 390, height: 800 }); await p.waitForSelector("#rb .rtb");
  ok(await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), "ponsel: halaman tidak bergulir horizontal (tabel punya gulir sendiri)");
  await shot(p, "umur_piutang_ponsel");
  await p.evaluate(() => { document.documentElement.dataset.theme = "dark"; }); await shot(p, "umur_piutang_gelap");
  await p.emulateMedia({ media: "print" });
  ok(await p.evaluate(() => getComputedStyle(document.querySelector("#rail")).display === "none" && getComputedStyle(document.querySelector(".rnav")).display === "none"), "cetak: menu samping disembunyikan");
  await p.context().close();

  ok(errors.length === 0, "tidak ada galat JS/konsol selama semua uji" + (errors.length ? " -> " + errors.slice(0, 3).join(" | ") : ""));
  await browser.close(); server.close();
  console.log(`\n${pass} lulus, ${fail} gagal`);
  if (fail) { console.log("Gagal:\n- " + failures.join("\n- ")); process.exit(1); }
})().catch(e => { console.error(e); process.exit(2); });
