/* Uji layar Proses Akhir Bulan dan Tutup Tahun Buku (langkah 4g) di Chromium sungguhan dengan server tiruan.
   Server tiruan mengikuti bentuk respons PeriodController, PeriodCloseController, StockRecalcController dan
   DepreciationController (semua dibungkus { data }; galat penutupan membawa { message, code, hasil }). PHP/MySQL tidak dipakai.
   Jalankan:  npm i playwright && npx playwright install chromium
              node tests/js/akhirbulan.e2e.cjs <folder-proyek-laravel> [folder-keluaran-screenshot]
   Opsional: CHROMIUM_PATH=/jalur/ke/chrome bila browser Playwright dipasang di tempat lain. */
const http = require("http"), fs = require("fs"), path = require("path");
const { chromium } = require("playwright");

const ROOT = path.resolve(process.argv[2] || "."), OUT = process.argv[3] ? path.resolve(process.argv[3]) : null;
if (OUT) fs.mkdirSync(OUT, { recursive: true });

/* ---------- fixture: tahun buku 2026 (dipendekkan: bulan 9 s.d. 12) ---------- */
const PER = { 9: { bulan: 9, mulai: "2026-09-01", selesai: "2026-09-30" }, 10: { bulan: 10, mulai: "2026-10-01", selesai: "2026-10-31" },
  11: { bulan: 11, mulai: "2026-11-01", selesai: "2026-11-30" }, 12: { bulan: 12, mulai: "2026-12-01", selesai: "2026-12-31" } };
let RS;   // keadaan server tiruan
const fresh = () => ({ closed: { 9: true }, yearClosed: false, recalc: false, dep: false, blockOnce: false, logs: [] });
RS = fresh();
const item = (kode, label, status, pesan, detail) => ({ kode, label, status, pesan, ...(detail ? { detail } : {}) });
const itemsFor = id => {
  if (+id === 11) return [item("urutan", "Periode sebelumnya sudah ditutup", "ok", "Semua periode sebelumnya sudah dikunci."), item("transaksi_berulang", "Transaksi berulang", "na", "Modul transaksi berulang belum tersedia.")];
  return [
    item("urutan", "Periode sebelumnya sudah ditutup", "ok", "Semua periode sebelumnya sudah dikunci."),
    item("draft", "Dokumen Draft", "warning", `Ada 3 dokumen belum diposting <i>x</i> (periode ${id}).`, { total: 3, per_jenis: [{ doc_type: "sales_invoice", label: "Faktur Penjualan", jumlah: 3 }], kebijakan: "warn" }),
    RS.recalc ? item("hpp_tertunda", "Hitung ulang HPP", "ok", "Tidak ada hitung ulang HPP yang tertunda.")
      : item("hpp_tertunda", "Hitung ulang HPP", "failed", "Ada 2 barang/gudang yang menunggu hitung ulang HPP.", { jumlah: 2 }),
    item("neraca_saldo", "Neraca saldo seimbang", "ok", "Total debit sama dengan total kredit."),
    item("saldo_cocok", "Saldo ringkas cocok dengan jurnal", "ok", "account_balances sama dengan journal_lines."),
    item("transaksi_berulang", "Transaksi berulang", "na", "Modul transaksi berulang belum tersedia."),
    RS.dep ? item("penyusutan_aset", "Penyusutan aset tetap", "ok", "Penyusutan periode ini sudah dijalankan.")
      : item("penyusutan_aset", "Penyusutan aset tetap", "failed", "2 aset belum disusutkan untuk periode ini.", { jumlah: 2 }),
    item("rekonsiliasi_bank", "Rekonsiliasi bank", "ok", "Tidak ada rekening koran yang belum direkonsiliasi."),
  ];
};
const assemble = (id, items) => {
  const st = items.map(x => x.status), overall = st.includes("failed") ? "failed" : st.includes("warning") ? "warning" : "passed";
  return { periode: { id: +id, ...PER[id], status: RS.closed[id] ? "closed" : "open" }, status: overall, items, bisa_ditutup: overall !== "failed", butuh_konfirmasi: overall === "warning" };
};
const years = () => [{ id: 1, tahun: 2026, mulai: "2026-01-01", selesai: "2026-12-31", status: RS.yearClosed ? "closed" : "open",
  periode: Object.keys(PER).map(id => ({ id: +id, bulan: PER[id].bulan, mulai: PER[id].mulai, selesai: PER[id].selesai, status: RS.closed[id] || (+id === 12 && RS.yearClosed) ? "closed" : "open" })) }];

const g = (f, a) => ({ fitur: f, aksi: a });
const sp = a => g("khusus", a);
const PERMS = {
  Owner: [g("dashboard", "view"), g("proses_akhir_bulan", "view"), g("proses_akhir_bulan", "create"), g("aset_tetap", "view"), g("barang_per_gudang", "view"), sp("tutup_periode"), sp("buka_kunci_periode"), sp("jurnal_koreksi")],
  Akuntan: [g("dashboard", "view"), g("proses_akhir_bulan", "view"), g("aset_tetap", "view"), sp("tutup_periode"), sp("jurnal_koreksi")],
  Viewer: [g("dashboard", "view"), g("proses_akhir_bulan", "view")],
};

/* ---------- server tiruan ---------- */
let ROLE = "Owner", LOG = [], POSTS = [], DELAY = {}, FAIL = {};
const MIME = { ".js": "text/javascript", ".css": "text/css" };
const server = http.createServer((req, res) => {
  let raw = ""; req.on("data", c => raw += c); req.on("end", () => handle(req, res, raw));
});
function handle(req, res, raw) {
  const u = new URL(req.url, "http://x"), p = u.pathname, q = u.searchParams;
  const json = (o, code = 200) => { res.writeHead(code, { "Content-Type": "application/json" }); res.end(JSON.stringify(o)); };
  if (p === "/__role") { ROLE = q.get("r"); return json({}); }
  if (p === "/__reset") { LOG = []; POSTS = []; DELAY = {}; FAIL = {}; RS = fresh(); return json({}); }
  if (p === "/__set") { Object.assign(RS, JSON.parse(q.get("v"))); return json({}); }
  if (p === "/__log") return json(LOG);
  if (p === "/__posts") return json(POSTS);
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
  const a = p.slice(8), body = raw ? JSON.parse(raw) : {};
  if (!a.startsWith("auth/me") && !a.startsWith("session") && !a.startsWith("company") && a !== "dashboard") (req.method === "GET" ? LOG : POSTS).push(req.method === "GET" ? a : req.method + " " + a + " " + raw);
  const done = () => {
    const fl = FAIL[a]; if (fl) { delete FAIL[a]; return json({ message: fl.msg }, fl.code); }
    let m;
    switch (true) {
      case a === "auth/me": return json({ data: { id: 1, name: "Budi Santoso", email: "budi@contoh.id", companies: [{ public_id: "co1", nama: "PT Contoh Abadi", role: ROLE }] } });
      case a === "session/company": return json({ data: {} });
      case a === "company": return json({ data: { nama: "PT Contoh Abadi" } });
      case a === "company/permissions": return json({ data: PERMS[ROLE] });
      case a === "dashboard": return json({ data: { tanggal: "2026-11-19", widgets: {} } });
      case a === "fiscal-years": return json({ data: years() });
      case !!(m = a.match(/^periods\/(\d+)\/close-check$/)): return json({ data: assemble(m[1], itemsFor(m[1])) });
      case !!(m = a.match(/^periods\/(\d+)\/close-logs$/)): return json({ data: RS.logs });
      case !!(m = a.match(/^fiscal-years\/(\d+)\/close-preview$/)):
        return json({ data: { ...assemble(12, itemsFor(11)), tahun: 2026, status_tahun: "open", penutup: { jumlah_akun: 14, laba_bersih: "125000000.0000", jumlah_baris_jurnal: 15 } } });
      case a === "stock/recalc/pending": return json({ data: [{ item_id: 10, warehouse_id: 1, item_kode: "BRG-1", gudang_kode: "GD-01", hitung_ulang_dari: "2026-10-05" }, { item_id: 11, warehouse_id: 2, item_kode: "BRG-2", gudang_kode: "GD-02", hitung_ulang_dari: null }] });
      case a === "stock/recalc": if (body.item_id === 11 && RS.recalcFailSecond) return json({ message: "gagal" }, 422); if (body.item_id === 11) RS.recalc = true; return json({ data: { status: "selesai" } });
      case !!(m = a.match(/^periods\/(\d+)\/depreciation-preview$/)): return json({ data: { period_id: +m[1], periode: "2026-10", jumlah_aset: 2, total: "1500000.0000", rincian: [] } });
      case !!(m = a.match(/^periods\/(\d+)\/depreciation$/)): RS.dep = true; return json({ data: { period_id: +m[1], jumlah_aset: 2, total: "1500000.0000", jurnal: [{ category_id: 1, journal_id: 7, nomor: "JU/2026/10/00007", total: "1500000.0000" }] } }, 201);
      case !!(m = a.match(/^periods\/(\d+)\/close$/)): {
        const hasil = assemble(m[1], itemsFor(m[1]));
        if (RS.blockOnce) { RS.blockOnce = false; const it = itemsFor(m[1]).map(x => x.kode === "hpp_tertunda" ? item("hpp_tertunda", "Hitung ulang HPP", "failed", "Ada 1 barang baru yang menunggu hitung ulang.") : x); return json({ message: "Penutupan ditolak: ada pemeriksaan yang gagal. Perbaiki lalu coba lagi.", code: "close_blocked", hasil: assemble(m[1], it) }, 422); }
        if (!hasil.bisa_ditutup) return json({ message: "Penutupan ditolak: ada pemeriksaan yang gagal. Perbaiki lalu coba lagi.", code: "close_blocked", hasil }, 422);
        if (hasil.butuh_konfirmasi && !body.konfirmasi) return json({ message: "Ada peringatan pada checklist. Kirim konfirmasi = true bila tetap ingin menutup.", code: "confirm_required", hasil }, 409);
        RS.closed[m[1]] = true; return json({ data: { id: +m[1], status: "closed", hasil } });
      }
      case a === "fiscal-years/1/close": RS.yearClosed = true; return json({ data: { tahun: 2026, jurnal_penutup: { id: 5, nomor: "JU/2026/12/00009", laba_bersih: "125000000.0000" }, hasil: {}, tahun_berikutnya_dibuat: true } });
      case a === "fiscal-years/1/reopen": RS.yearClosed = false; return json({ data: { tahun: 2026, jurnal_dibalik: [] } });
      case !!(m = a.match(/^periods\/(\d+)\/reopen$/)): if (!body.alasan) return json({ message: "Alasan wajib diisi.", code: "reopen_rejected" }, 422); RS.closed[m[1]] = false; return json({ data: { id: +m[1], status: "open" } });
      default: return json({ message: "tidak ada: " + a }, 404);
    }
  };
  const d = DELAY[a]; if (d) { delete DELAY[a]; setTimeout(done, d); } else done();
}

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
  async function open(role, vp) {
    await get("/__role?r=" + role); await get("/__reset");
    const ctx = await browser.newContext({ viewport: vp || { width: 1280, height: 900 }, deviceScaleFactor: 1 });
    await ctx.route(u => !u.href.startsWith(base), r => r.abort());
    const pgx = await ctx.newPage();
    pgx.on("pageerror", e => errors.push(role + ": " + e.message));
    pgx.on("console", m => { if (m.type() === "error" && !/Failed to load resource|net::ERR/.test(m.text())) errors.push(role + ": " + m.text()); });
    await pgx.goto(base + "/app#/proses_akhir_bulan");
    await pgx.waitForSelector("#pg .hd", { timeout: 5000 });
    return pgx;
  }
  const text = (pgx, sel) => pgx.locator(sel).innerText();
  const log = () => get("/__log"), posts = () => get("/__posts");
  const nGet = async prefix => (await log()).filter(x => x.startsWith(prefix)).length;
  const shot = async (pgx, name) => { if (OUT) await pgx.screenshot({ path: path.join(OUT, name + ".png") }); };
  const waitText = (pgx, sel, sub) => pgx.waitForFunction(([s, t]) => { const e = document.querySelector(s); return e && e.innerText.includes(t); }, [sel, sub], { timeout: 4000 });

  /* ===== Owner: alur lengkap periode Oktober ===== */
  console.log("Langkah 1: Periksa");
  let p = await open("Owner");
  await p.waitForSelector("#cb .chk");
  ok((await log()).includes("periods/10/close-check") && !(await log()).includes("periods/9/close-check"), "periode awal = periode terbuka paling awal (Oktober), bukan yang sudah ditutup");
  ok((await log()).includes("periods/10/close-logs"), "riwayat penutupan dimuat");
  ok((await text(p, ".steps")).replace(/\s+/g, " ").includes("1. Periksa 2. Proses otomatis 3. Tinjau 4. Kunci periode"), "empat langkah wizard mengikuti prototipe");
  ok((await p.locator("#cb .chk").count()) === 8, "delapan item checklist tampil");
  ok((await p.locator("#cb .chk b.ng").count()) === 2 && (await p.locator("#cb .chk b.wn").count()) === 1 && (await p.locator("#cb .chk b.gr").count()) === 1, "status gagal (2), peringatan (1), belum tersedia (1) dibedakan");
  ok((await text(p, "#cb")).includes("Faktur Penjualan: 3"), "rincian draft per jenis tampil");
  ok((await p.locator("#cb .chk i").count()) === 0 && (await text(p, "#cb")).includes("<i>x</i>"), "pesan server berisi HTML di-escape");
  ok(await p.locator("#nx").isEnabled(), "Lanjut aktif setelah pemeriksaan");
  ok(await p.locator("#bk").isDisabled(), "Kembali nonaktif di langkah pertama");
  await shot(p, "akhir_bulan_periksa");

  console.log("Langkah 2: Proses otomatis");
  await p.click("#nx");
  await p.waitForSelector("#rc");
  ok((await text(p, "#cb")).includes("Hitung ulang HPP") && (await text(p, "#cb")).includes("Penyusutan aset tetap"), "kedua proses otomatis tampil karena keduanya gagal");
  await p.waitForFunction(() => document.querySelector("#cb").innerText.includes("1.500.000"));
  ok((await text(p, "#cb")).includes("Akan disusutkan: 2 aset, total Rp 1.500.000"), "pratinjau penyusutan tampil");
  await shot(p, "akhir_bulan_otomatis");
  await p.click("#rc");
  await waitText(p, "#cb", "2 barang selesai dihitung ulang");
  let po = (await posts()).filter(x => x.includes("stock/recalc"));
  ok(po.length === 2 && po[0].includes('"item_id":10') && po[0].includes('"warehouse_id":1') && po[1].includes('"item_id":11'), "hitung ulang memanggil satu barang+gudang per panggilan");
  ok((await nGet("periods/10/close-check")) === 2 && !(await p.locator("#rc").count()), "checklist diperiksa ulang dan bagian HPP hilang setelah berhasil");
  await p.click("#dp");
  await waitText(p, "#cb", "JU/2026/10/00007");
  ok((await posts()).some(x => x.startsWith("POST periods/10/depreciation")), "penyusutan dijalankan lewat POST periods/10/depreciation");
  ok((await text(p, "#cb")).includes("Tidak ada proses otomatis"), "tidak ada proses tersisa setelah keduanya selesai");

  console.log("Langkah 3: Tinjau");
  const before = await nGet("periods/10/close-check");
  await p.click("#nx"); await p.waitForSelector("#kf");
  ok((await nGet("periods/10/close-check")) === before + 1, "Tinjau memeriksa ulang dari server");
  ok((await p.locator("#cb .chk b.ng").count()) === 0 && (await text(p, "#cb")).includes("Ada peringatan"), "hanya peringatan draft yang tersisa");
  ok(await p.locator("#nx").isDisabled(), "Lanjut nonaktif sebelum peringatan dikonfirmasi");
  await p.check("#kf");
  ok(await p.locator("#nx").isEnabled(), "Lanjut aktif setelah konfirmasi");
  await shot(p, "akhir_bulan_tinjau");

  console.log("Langkah 4: Kunci periode");
  await p.click("#nx"); await p.waitForSelector("#kc");
  ok((await text(p, "#cb")).includes("final") && (await text(p, "#cb")).includes("Okt 2026"), "peringatan final dan nama periode tampil");
  await p.dblclick("#kc");
  await p.waitForSelector("#ok");
  po = (await posts()).filter(x => x.startsWith("POST periods/10/close"));
  ok(po.length === 1 && po[0].includes('"konfirmasi":true'), "dua klik cepat hanya mengirim satu permintaan tutup, dengan konfirmasi true");
  ok((await text(p, "#ok")).includes("dikunci") && (await p.locator("#pp option:checked").innerText()).includes("(ditutup)"), "hasil sukses dan periode kini bertanda (ditutup)");
  await shot(p, "akhir_bulan_selesai");
  await p.click("#nxp");
  await p.waitForSelector("#cb .chk");
  ok((await log()).includes("periods/11/close-check") && (await p.locator("#pp").inputValue()) === "11", "Proses periode berikutnya memilih November dan memeriksanya");
  ok((await text(p, ".warn")).includes("Semua pemeriksaan lolos"), "November lolos semua (status passed)");
  await p.click("#nx"); await p.waitForSelector("#cb .empty");
  ok((await text(p, "#cb")).includes("Tidak ada proses otomatis"), "tanpa kegagalan, langkah proses otomatis kosong");
  await p.click("#nx"); await p.waitForFunction(() => !document.querySelector("#kf") && document.querySelector("#nx") && !document.querySelector("#nx").disabled);
  ok(!(await p.locator("#kf").count()), "tanpa peringatan tidak ada kotak konfirmasi");
  await p.context().close();

  /* ===== Penutupan ditolak server (data berubah sejak Tinjau) ===== */
  console.log("Penutupan ditolak");
  p = await open("Owner");
  await get("/__set?v=" + encodeURIComponent(JSON.stringify({ recalc: true, dep: true })));
  await p.selectOption("#pp", "10"); await p.waitForSelector("#cb .chk");
  await p.click("#nx"); await p.click("#nx"); await p.waitForSelector("#kf"); await p.check("#kf"); await p.click("#nx"); await p.waitForSelector("#kc");
  await get("/__set?v=" + encodeURIComponent(JSON.stringify({ blockOnce: true })));
  await p.click("#kc");
  await p.waitForSelector("#cb .chk b.ng");
  ok((await text(p, ".steps .on")).includes("Tinjau") && (await text(p, "#cb")).includes("barang baru yang menunggu"), "kembali ke Tinjau dengan checklist terbaru dari server");
  ok(await p.locator("#nx").isDisabled(), "tidak bisa lanjut selama ada yang gagal");
  await p.context().close();

  /* ===== Tutup tahun buku (periode ke-12) ===== */
  console.log("Tutup tahun buku");
  p = await open("Owner");
  await get("/__set?v=" + encodeURIComponent(JSON.stringify({ closed: { 9: true, 10: true, 11: true } })));
  await p.selectOption("#pp", "12"); await p.waitForSelector("#cb .chk");
  ok((await log()).includes("fiscal-years/1/close-preview") && !(await log()).includes("periods/12/close-check"), "periode ke-12 memakai close-preview tahun, bukan close-check bulanan");
  ok((await text(p, ".steps")).includes("4. Tutup tahun buku"), "langkah terakhir berlabel Tutup tahun buku");
  await p.click("#nx"); await p.waitForSelector("#cb .empty"); await p.click("#nx"); await p.waitForFunction(() => document.querySelector("#cb").innerText.includes("Jurnal penutup tahun 2026"));
  const yt = await text(p, "#cb");
  ok(yt.includes("14 akun") && yt.includes("15 baris") && yt.includes("Laba bersih") && yt.includes("125.000.000"), "ringkasan jurnal penutup: akun, baris, laba bersih");
  await shot(p, "tutup_tahun");
  await p.click("#nx"); await p.waitForSelector("#kc");
  ok((await p.locator("#kc").innerText()) === "Tutup tahun buku", "tombol final berlabel Tutup tahun buku");
  await p.click("#kc"); await p.waitForSelector("#ok");
  ok((await posts()).some(x => x.startsWith("POST fiscal-years/1/close")) && !(await posts()).some(x => x.includes("periods/12/close")), "tutup tahun lewat fiscal-years/1/close");
  ok((await text(p, "#cb")).includes("JU/2026/12/00009") && (await text(p, "#cb")).includes("Tahun buku berikutnya dibuat"), "nomor jurnal penutup dan info tahun baru tampil");
  await p.context().close();

  /* ===== Buka kunci ===== */
  console.log("Buka kunci");
  p = await open("Owner");
  await p.selectOption("#pp", "9"); await p.waitForSelector("#ro");
  ok((await text(p, "#cb")).includes("sudah ditutup"), "periode tertutup menampilkan status, bukan wizard");
  ok((await p.locator(".steps").count()) === 0 && (await p.locator("#nx").count()) === 0, "wizard disembunyikan untuk periode tertutup");
  await p.click("#ro");
  ok(await p.locator("#okr").isDisabled(), "tombol buka kunci nonaktif tanpa alasan");
  await p.fill("#rs", "Koreksi faktur");
  await p.click("#okr");
  await p.waitForFunction(() => document.querySelector("#pp") && !document.querySelector("#ro"));
  po = (await posts()).filter(x => x.startsWith("POST periods/9/reopen"));
  ok(po.length === 1 && po[0].includes("Koreksi faktur"), "reopen mengirim alasan");
  ok(!(await p.locator("#pp option:checked").innerText()).includes("(ditutup)"), "periode kembali terbuka setelah buka kunci");
  await p.context().close();

  /* ===== Hak akses ===== */
  console.log("Hak akses");
  p = await open("Akuntan");
  await p.selectOption("#pp", "9"); await p.waitForSelector("#cb .warn");
  ok(!(await p.locator("#ro").count()) && (await text(p, "#cb")).includes("Hanya pemegang hak Buka Kunci"), "Akuntan tidak melihat tombol Buka kunci");
  await p.selectOption("#pp", "10"); await p.waitForSelector("#cb .chk");
  await p.click("#nx"); await p.waitForSelector("#rc");
  ok(await p.locator("#rc").count() === 1 && await p.locator("#dp").count() === 1, "Akuntan boleh hitung ulang HPP dan jalankan penyusutan");
  await p.context().close();
  p = await open("Viewer");
  await p.waitForSelector("#cb .chk");
  await p.click("#nx"); await p.waitForSelector("#cb .warn");
  ok(!(await p.locator("#rc").count()) && !(await p.locator("#dp").count()) && (await text(p, "#cb")).includes("butuh hak khusus"), "Viewer: tombol proses otomatis tidak ada, ada catatan hak");
  ok(!(await text(p, "#cb")).includes("Akan disusutkan"), "tanpa hak lihat Aset Tetap pratinjau penyusutan tidak diminta");
  await get("/__set?v=" + encodeURIComponent(JSON.stringify({ recalc: true, dep: true })));
  await p.click("#bk"); await p.click("#pp"); await p.selectOption("#pp", "11"); await p.waitForSelector("#cb .chk");
  await p.click("#nx"); await p.waitForSelector("#cb .empty"); await p.click("#nx"); await p.waitForSelector("#nx:not([disabled])");
  await p.click("#nx"); await p.waitForSelector("#kc");
  ok(await p.locator("#kc").isDisabled(), "Viewer: tombol Kunci periode nonaktif tanpa hak tutup_periode");
  await p.context().close();

  /* ===== Balapan dan galat ===== */
  console.log("Balapan dan galat");
  p = await open("Owner");
  await p.waitForSelector("#cb .chk");
  await get("/__delay?p=" + encodeURIComponent("periods/10/close-check") + "&ms=600");
  await p.selectOption("#pp", "9"); await p.selectOption("#pp", "10");   // permintaan Oktober akan lambat
  await p.selectOption("#pp", "11");
  await p.waitForSelector("#cb .chk");
  await sleep(900);
  ok((await p.locator("#pp").inputValue()) === "11" && !(await text(p, "#cb")).includes("periode 10") && (await p.locator("#cb .chk").count()) === 2, "balasan lambat periode lama tidak menimpa periode yang dipilih sekarang");
  await get("/__fail?p=" + encodeURIComponent("periods/11/close-check") + "&code=500&msg=" + encodeURIComponent("Server sedang sibuk"));
  await p.selectOption("#pp", "10"); await p.selectOption("#pp", "11");
  await p.waitForSelector("#rt");
  ok((await text(p, "#cb")).includes("Server sedang sibuk"), "galat pemeriksaan tampil dengan pesan server");
  await p.click("#rt"); await p.waitForSelector("#cb .chk");
  ok((await p.locator("#cb .chk").count()) === 2, "Coba lagi memuat ulang");
  await p.context().close();

  /* ===== Layar sempit ===== */
  console.log("Layar sempit");
  p = await open("Owner", { width: 390, height: 800 });
  await p.waitForSelector("#cb .chk");
  ok(await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), "halaman tidak bergulir ke samping");
  await shot(p, "akhir_bulan_mobile");
  await p.context().close();

  ok(errors.length === 0, "tanpa galat JS/konsol" + (errors.length ? ": " + errors.slice(0, 3).join(" | ") : ""));
  await browser.close(); server.close();
  console.log(`\n${pass} lulus, ${fail} gagal`);
  if (fail) { console.log("Gagal:\n- " + failures.join("\n- ")); process.exit(1); }
})().catch(e => { console.error(e); process.exit(1); });
