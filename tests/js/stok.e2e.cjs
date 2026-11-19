/* Uji layar Barang per Gudang dan Kartu Stok (langkah 4f) di Chromium sungguhan dengan server tiruan.
   Server tiruan mengikuti bentuk respons StockReportController/StockReportService: JSON TANPA pembungkus { data }.
   PHP/MySQL tidak dipakai.
   Jalankan:  npm i playwright && npx playwright install chromium
              node tests/js/stok.e2e.cjs <folder-proyek-laravel> [folder-keluaran-screenshot]  */
const http = require("http"), fs = require("fs"), path = require("path");
const { chromium } = require("playwright");

const ROOT = path.resolve(process.argv[2] || "."), OUT = process.argv[3] ? path.resolve(process.argv[3]) : null;
if (OUT) fs.mkdirSync(OUT, { recursive: true });

/* ---------- fixture ---------- */
const WH = [{ id: 1, kode: "GD-01", nama: "Gudang Utama" }, { id: 2, kode: "GD-02", nama: "Gudang Cabang" }];
const ITEMS = [{ id: 10, kode: "BRG-1", nama: "Kopi <i>Arabika</i>", tipe: "barang" }, { id: 11, kode: "BRG-2", nama: "Gula", tipe: "barang" }, { id: 12, kode: "BRG-3", nama: "Teh", tipe: "barang" }];
const BAL = [
  { item_id: 10, warehouse_id: 1, kode_barang: "BRG-1", nama_barang: "Kopi <i>Arabika</i>", kode_gudang: "GD-01", nama_gudang: "Gudang Utama", qty_saldo: "100.5000", stok_minimum: "20.0000", perlu_hitung_ulang: false, hpp_rata_rata: "12500.5000", nilai_saldo: "1256312.7500" },
  { item_id: 10, warehouse_id: 2, kode_barang: "BRG-1", nama_barang: "Kopi <i>Arabika</i>", kode_gudang: "GD-02", nama_gudang: "Gudang Cabang", qty_saldo: "5.0000", stok_minimum: "10.0000", perlu_hitung_ulang: true, hpp_rata_rata: "12000.0000", nilai_saldo: "60000.0000" },
  { item_id: 11, warehouse_id: 1, kode_barang: "BRG-2", nama_barang: "Gula", kode_gudang: "GD-01", nama_gudang: "Gudang Utama", qty_saldo: "0.0000", stok_minimum: "0.0000", perlu_hitung_ulang: false, hpp_rata_rata: "0.0000", nilai_saldo: "0.0000" },
  { item_id: 11, warehouse_id: 2, kode_barang: "BRG-2", nama_barang: "Gula", kode_gudang: "GD-02", nama_gudang: "Gudang Cabang", qty_saldo: "7.2500", stok_minimum: "0.0000", perlu_hitung_ulang: false, hpp_rata_rata: "9000.0000", nilai_saldo: "65250.0000" },
  { item_id: 12, warehouse_id: 1, kode_barang: "BRG-3", nama_barang: "Teh", kode_gudang: "GD-01", nama_gudang: "Gudang Utama", qty_saldo: "50.0000", stok_minimum: "0.0000", perlu_hitung_ulang: false, hpp_rata_rata: "5000.0000", nilai_saldo: "250000.0000" },
];
const PAGE_BAL = 3, PAGE_CARD = 2;   // ukuran halaman server tiruan (tetap, apa pun 'limit'), supaya paginasi teruji
const stockByWh = (q, cost) => {
  let r = BAL.filter(x => (!q.get("warehouse_id") || x.warehouse_id === +q.get("warehouse_id")) && (!q.get("hanya_ada_stok") || +x.qty_saldo !== 0)
    && (!q.get("q") || (x.kode_barang + " " + x.nama_barang).toLowerCase().includes(q.get("q").toLowerCase())));
  if (q.get("after_item_id")) { const ai = +q.get("after_item_id"), aw = +q.get("after_warehouse_id"); r = r.filter(x => x.item_id > ai || (x.item_id === ai && x.warehouse_id > aw)); }
  const more = r.length > PAGE_BAL; r = r.slice(0, PAGE_BAL);
  const rows = r.map(x => { const o = { ...x }; if (!cost) { delete o.hpp_rata_rata; delete o.nilai_saldo; } return o; });
  return { rows, next: more ? { after_item_id: r[r.length - 1].item_id, after_warehouse_id: r[r.length - 1].warehouse_id } : null };
};
const LEDGER = [
  { id: 1, tanggal: "2026-01-05", source_type: "opening_balance", source_id: 7, reversed_of_id: null, qty_masuk: "100.0000", qty_keluar: "0.0000", qty_saldo: "100.0000", harga_satuan: "12000.0000", nilai: "1200000.0000", nilai_saldo: "1200000.0000" },
  { id: 2, tanggal: "2026-02-10", source_type: "sales_invoice", source_id: 3, reversed_of_id: null, qty_masuk: "0.0000", qty_keluar: "10.0000", qty_saldo: "90.0000", harga_satuan: "12000.0000", nilai: "-120000.0000", nilai_saldo: "1080000.0000" },
  { id: 3, tanggal: "2026-02-10", source_type: "sales_invoice", source_id: 3, reversed_of_id: 2, qty_masuk: "10.0000", qty_keluar: "0.0000", qty_saldo: "100.0000", harga_satuan: "12000.0000", nilai: "120000.0000", nilai_saldo: "1200000.0000" },
];
const stockCard = (q, cost) => {
  const dari = q.get("dari"), sampai = q.get("sampai"), after = +(q.get("after_id") || 0);
  let rows = LEDGER.filter(x => x.tanggal >= dari && x.tanggal <= sampai && x.id > after);
  const more = rows.length > PAGE_CARD; rows = rows.slice(0, PAGE_CARD);
  const prior = LEDGER.filter(x => x.tanggal < dari).pop();
  const saldoAwal = { qty: prior ? prior.qty_saldo : "0.0000" }; if (cost) saldoAwal.nilai = prior ? prior.nilai_saldo : "0.0000";
  return { saldo_awal: saldoAwal, rows: rows.map(x => { const o = { id: x.id, tanggal: x.tanggal, source_type: x.source_type, source_id: x.source_id, pembalik: x.reversed_of_id !== null, qty_masuk: x.qty_masuk, qty_keluar: x.qty_keluar, qty_saldo: x.qty_saldo }; return cost ? { ...o, harga_satuan: x.harga_satuan, nilai: x.nilai, nilai_saldo: x.nilai_saldo } : o; }),
    next_after_id: more ? rows[rows.length - 1].id : null, perlu_hitung_ulang: q.get("warehouse_id") === "2" };
};

const base_ = ["dashboard", "daftar_laporan", "laporan_keuangan", "histori_akun"];
const grant = (f, a) => ({ fitur: f, aksi: a });
const PERMS = {
  Owner: ["barang_per_gudang", "gudang", "barang_jasa", ...base_].flatMap(f => ["view", "export", "print"].map(a => grant(f, a))),
  /* Peran gudang: hanya Barang per Gudang, tanpa Daftar Laporan, tanpa hak lihat HPP (server tidak mengirim kolom biaya) */
  Gudang: [grant("barang_per_gudang", "view"), grant("dashboard", "view")],
  /* Punya laporan tetapi tanpa hak lihat Gudang dan Barang & Jasa: daftar rujukan ditolak server */
  TanpaRef: ["barang_per_gudang", ...base_].flatMap(f => ["view"].map(a => grant(f, a))),
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
  const cost = ROLE !== "Gudang";
  const done = () => {
    const fl = FAIL[a]; if (fl) { delete FAIL[a]; return json({ message: fl.msg }, fl.code); }
    switch (true) {
      case a === "auth/me": return json({ data: { id: 1, name: "Budi Santoso", email: "budi@contoh.id", companies: [{ public_id: "co1", nama: "PT Contoh Abadi", role: "Owner" }] } });
      case a === "session/company": return json({ data: {} });
      case a === "company": return json({ data: { nama: "PT Contoh Abadi" } });
      case a === "company/permissions": return json({ data: PERMS[ROLE] });
      case a === "dashboard": return json({ data: { tanggal: "2026-11-19", widgets: {} } });
      case a === "warehouses": return ROLE === "TanpaRef" ? json({ message: "Tidak punya hak." }, 403) : json({ data: WH, next_after_id: null });
      case a === "items": {
        if (ROLE === "TanpaRef") return json({ message: "Tidak punya hak." }, 403);
        const s = (q.get("q") || "").toLowerCase();
        return json({ data: ITEMS.filter(x => !s || (x.kode + " " + x.nama).toLowerCase().includes(s)), next_after_id: null });
      }
      case a === "reports/stock-by-warehouse": return json(stockByWh(q, cost));                    // tanpa pembungkus data
      case a === "reports/stock-card": return json(stockCard(q, cost));                             // tanpa pembungkus data
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
    await ctx.route(u => !u.href.startsWith(base), r => r.abort());
    const pgx = await ctx.newPage();
    pgx.on("pageerror", e => errors.push(hash + ": " + e.message));
    pgx.on("console", m => { if (m.type() === "error" && !/Failed to load resource|net::ERR/.test(m.text())) errors.push(hash + ": " + m.text()); });
    await pgx.goto(base + "/app#/" + hash);
    await pgx.waitForSelector("#pg .hd", { timeout: 5000 });
    return pgx;
  }
  const text = (pgx, sel) => pgx.locator(sel).innerText();
  const log = async () => get("/__log");
  const lastReq = async prefix => (await log()).filter(x => x.startsWith(prefix)).pop() || "";
  const waitReq = async (prefix, sub) => { for (let i = 0; i < 50; i++) { if ((await lastReq(prefix)).includes(sub)) return; await sleep(100); } };
  const countReq = async prefix => (await log()).filter(x => x.startsWith(prefix)).length;
  const shot = async (pgx, name) => { if (OUT) await pgx.screenshot({ path: path.join(OUT, name + ".png"), fullPage: false }); };
  const download = async (pgx) => { const dl = pgx.waitForEvent("download"); await pgx.click("[data-x]"); const d = await dl; return { csv: fs.readFileSync(await d.path(), "utf8"), name: d.suggestedFilename() }; };
  const YEAR = new Date().getFullYear();

  /* ===== Barang per Gudang (Owner) ===== */
  console.log("Barang per Gudang");
  let p = await open("Owner", "barang_per_gudang");
  await p.waitForSelector("#rb .rtb");
  ok((await lastReq("reports/stock-by-warehouse")).startsWith("reports/stock-by-warehouse?limit=50"), "meminta stock-by-warehouse limit 50");
  ok((await p.locator("#rb tbody tr").count()) === 3, "halaman pertama 3 baris");
  const t1 = await text(p, "#rb");
  ok(t1.includes("HPP rata-rata") && t1.includes("Nilai"), "pemegang hak lihat HPP melihat kolom HPP dan Nilai");
  ok(t1.includes("100,5") && t1.includes("12.500,5") && t1.includes("1.256.313"), "jumlah pecahan, HPP dua desimal, nilai rupiah bulat");
  ok((await p.locator("#rb tbody tr .bd.wn").count()) === 1 && t1.includes("Di bawah minimum"), "hanya satu baris di bawah stok minimum");
  ok(t1.includes("Perlu hitung ulang") && t1.includes("masih sementara"), "tanda dan peringatan perlu hitung ulang");
  ok((await p.locator("#rb tbody b i").count()) === 0 && t1.includes("Kopi <i>Arabika</i>"), "nama barang berisi HTML di-escape");
  await shot(p, "barang_per_gudang");
  await p.click("#more"); await p.waitForFunction(() => document.querySelectorAll("#rb tbody tr").length === 5);
  ok(!(await p.locator("#more").count()) && (await text(p, "#rb .ft")).includes("5 baris (semua)"), "Muat lainnya menambah ke 5 baris dan selesai");
  ok((await lastReq("reports/stock-by-warehouse")).includes("after_item_id=11&after_warehouse_id=1"), "paginasi memakai kursor item + gudang");

  console.log("Filter");
  await p.selectOption("#fw", "2"); await p.waitForFunction(() => document.querySelectorAll("#rb tbody tr").length === 2);
  ok((await lastReq("reports/stock-by-warehouse")).includes("warehouse_id=2"), "filter gudang dikirim ke server");
  await p.selectOption("#fw", ""); await p.check("#fa");
  await waitReq("reports/stock-by-warehouse", "hanya_ada_stok=1");
  ok((await lastReq("reports/stock-by-warehouse")).includes("hanya_ada_stok=1"), "hanya yang ada stok dikirim ke server");
  await p.uncheck("#fa");
  const nq = await countReq("reports/stock-by-warehouse");
  await p.fill("#fq", "gu"); await p.fill("#fq", "gula"); await sleep(600);
  ok((await countReq("reports/stock-by-warehouse")) === nq + 1 && (await lastReq("reports/stock-by-warehouse")).includes("q=gula"), "pencarian ditunda 300 ms (satu permintaan untuk ketikan berturut-turut)");
  ok((await p.locator("#rb tbody tr").count()) === 2, "hasil pencarian 'gula' dua baris");
  await p.fill("#fq", "tidakada"); await sleep(600);
  ok((await text(p, "#rb")).includes("Tidak ada barang yang cocok"), "pencarian kosong menampilkan pesan");
  await p.fill("#fq", ""); await sleep(600);

  /* CSV memuat semua halaman */
  const n0 = await countReq("reports/stock-by-warehouse");
  const dl1 = await download(p);
  ok((await countReq("reports/stock-by-warehouse")) === n0 + 2, "CSV mengambil semua halaman (2 permintaan)");
  ok(dl1.csv.charCodeAt(0) === 0xFEFF && dl1.csv.includes("Kode barang;Nama barang;Kode gudang;Nama gudang;Stok;Stok minimum;HPP rata-rata;Nilai"), "CSV: BOM, titik koma, kolom HPP");
  ok(dl1.csv.trim().split(/\r?\n/).length === 6, "CSV memuat 5 baris data + header");
  ok(dl1.csv.includes("BRG-1;Kopi <i>Arabika</i>;GD-01;Gudang Utama;100.5;20;12500.5;1256313"), "CSV: angka tanpa pemisah ribuan");
  ok(dl1.name.startsWith("barang_per_gudang_"), "CSV: nama berkas");
  await p.context().close();

  /* ===== Peran tanpa hak lihat HPP dan tanpa Daftar Laporan ===== */
  console.log("Peran Gudang (tanpa HPP)");
  p = await open("Gudang", "barang_per_gudang"); await p.waitForSelector("#rb .rtb");
  const tg = await text(p, "#rb");
  ok(!tg.includes("HPP rata-rata") && tg.includes("Lihat HPP"), "tanpa hak HPP: kolom disembunyikan dan ada catatan");
  ok((await p.locator("#rb tr.row").count()) === 0, "tanpa Daftar Laporan: baris tidak bisa diklik");
  ok(!(await p.locator("[data-x]").count()), "tanpa hak ekspor: tombol CSV tidak ada");
  await p.context().close();

  /* ===== Penelusuran ke Kartu Stok ===== */
  console.log("Penelusuran ke Kartu Stok");
  p = await open("Owner", "barang_per_gudang"); await p.waitForSelector("#rb tr.row");
  await p.locator("#rb tr.row").first().click(); await p.waitForSelector("#rb .rsum");
  ok(p.url().endsWith("#/daftar_laporan") && (await p.locator(".rnav button.on").innerText()) === "Kartu Stok", "klik baris membuka Kartu Stok di Daftar Laporan");
  ok((await p.inputValue("#fi")) === "10" && (await p.inputValue("#fw")) === "1" && (await p.inputValue("#fd")) === YEAR + "-01-01", "barang, gudang, dan awal tahun terisi dari baris");
  ok((await lastReq("reports/stock-card")).includes("item_id=10&warehouse_id=1&dari=" + YEAR + "-01-01") && (await lastReq("reports/stock-card")).includes("limit=100"), "kartu diminta untuk barang dan gudang itu");
  ok(await p.locator("#kb").count() === 1, "tombol Kembali ke Barang per Gudang muncul");
  await p.click("#kb"); await p.waitForSelector("#rb .rtb");
  ok(p.url().endsWith("#/barang_per_gudang"), "Kembali: Barang per Gudang");
  await p.context().close();

  /* ===== Kartu Stok ===== */
  console.log("Kartu Stok");
  p = await open("Owner", "daftar_laporan"); await p.waitForSelector("#rh");
  ok(/persediaan/i.test(await text(p, ".rnav")) && (await text(p, ".rnav")).includes("Kartu Stok") && (await text(p, ".rnav")).includes("Barang per Gudang"), "Daftar Laporan memuat grup Persediaan");
  await p.click("[data-r=kartu_stok]"); await p.waitForSelector("#fi");
  ok((await text(p, "#rb")).includes("Pilih barang, gudang"), "tanpa pilihan: petunjuk, belum ada permintaan");
  ok((await countReq("reports/stock-card")) === 0, "belum memanggil stock-card tanpa barang dan gudang");
  ok((await p.locator("#fi option").count()) === 4, "pilihan barang dari /items (3 barang + placeholder)");
  await p.selectOption("#fi", "10"); await p.selectOption("#fw", "1");
  await p.waitForSelector("#rb tbody tr:not(.sub)");
  const k1 = await text(p, "#rb");
  ok(k1.includes("Saldo awal") && k1.includes("Saldo akhir"), "ringkasan saldo tampil");
  ok(k1.includes("Saldo Awal #7") && k1.includes("Faktur Penjualan #3"), "sumber diberi label jenis dokumen");
  ok((await p.locator("#rb .rsum div").nth(1).innerText()).includes("…"), "total belum final saat halaman belum lengkap");
  ok(k1.includes("Harga satuan") && k1.includes("12.000") && k1.includes("(120.000)"), "harga satuan dan nilai keluar (negatif, dalam kurung) tampil");
  await p.click("#more"); await p.waitForFunction(() => !document.querySelector("#more"));
  const k2 = await text(p, "#rb");
  ok((await p.locator("#rb tbody tr:not(.sub):not(.tt)").count()) === 3 && (await p.locator("#rb .bd.gr").count()) === 1 && k2.includes("Pembalik"), "3 baris, satu bertanda Pembalik");
  ok((await lastReq("reports/stock-card")).includes("after_id=2"), "paginasi memakai after_id baris terakhir");
  const sum = await text(p, "#rb .rsum");
  ok(/Total masuk\s*110/.test(sum) && /Total keluar\s*10\b/.test(sum) && /Saldo akhir\s*100/.test(sum), "total masuk 110, keluar 10, saldo akhir 100 setelah lengkap");
  ok((await text(p, "#rb tr.tt")).includes("Total mutasi"), "baris total mutasi muncul setelah lengkap");
  await shot(p, "kartu_stok");
  const nc = await countReq("reports/stock-card");
  const dl2 = await download(p);
  ok((await countReq("reports/stock-card")) - nc === 2, "CSV Kartu Stok mengambil semua halaman");
  ok(dl2.csv.includes("Tanggal;Sumber;ID sumber;Pembalik;Masuk;Keluar;Saldo;Harga satuan;Nilai;Nilai saldo") && dl2.csv.includes("2026-02-10;Faktur Penjualan;3;ya;10;0;100;12000;120000;1200000"), "CSV Kartu Stok: baris pembalik");
  ok(dl2.csv.trim().split(/\r?\n/).length === 5, "CSV: header + saldo awal + 3 baris");
  ok(dl2.name.startsWith("kartu_stok_"), "CSV: nama berkas");

  console.log("Saldo awal, rentang, dan galat");
  await p.fill("#fd", "2026-02-01"); await p.waitForFunction(() => document.querySelector("#rb .rsum")?.innerText.includes("100"));
  ok((await p.locator("#rb tr.sub").innerText()).includes("100"), "saldo awal dari server ikut rentang tanggal");
  const nr = await countReq("reports/stock-card");
  await p.fill("#fs", "2026-01-01"); await p.waitForSelector("#rt");
  ok((await text(p, "#rb")).includes("Tanggal akhir tidak boleh lebih awal") && (await countReq("reports/stock-card")) === nr, "rentang terbalik ditolak di layar tanpa memanggil server");
  await p.fill("#fs", "2026-12-31"); await p.waitForSelector("#rb .rsum");
  await get("/__fail?p=reports/stock-card&code=404&msg=x");
  await p.selectOption("#fw", "2"); await p.waitForSelector("#rt");
  ok((await text(p, "#rb")).includes("Barang atau gudang tidak ditemukan"), "404 tampil sebagai pesan jelas");
  await p.click("#rt"); await p.waitForSelector("#rb .rsum");
  ok((await text(p, "#rb")).includes("menunggu hitung ulang"), "peringatan perlu_hitung_ulang dari server tampil");

  console.log("Cari barang dan balapan");
  await p.fill("#fk", "gula"); await sleep(600);
  ok((await lastReq("items")).includes("q=gula") && (await lastReq("items")).includes("tipe=barang"), "cari barang memanggil /items?q= untuk barang saja");
  ok((await p.locator("#fi option").count()) === 3, "pilihan barang menyempit (placeholder + Gula + barang terpilih)");
  ok((await p.inputValue("#fi")) === "10", "pilihan barang yang aktif tetap terpilih setelah pencarian");
  await get("/__delay?p=reports/stock-card&ms=500");
  await p.fill("#fd", "2026-01-01"); await sleep(50); await p.fill("#fd", "2026-02-01"); await sleep(900);
  // dari=2026-01-01 (respons lama, lambat) memuat baris "Saldo Awal #7" dan saldo awal 0; dari=2026-02-01 (baru) tidak
  ok((await p.inputValue("#fd")) === "2026-02-01" && !(await text(p, "#rb")).includes("Saldo Awal #7") && (await p.locator("#rb tr.sub").innerText()).includes("100"), "respons lambat dari permintaan lama diabaikan");
  await p.context().close();

  /* ===== Tanpa hak lihat Barang & Jasa / Gudang ===== */
  console.log("Peran tanpa hak rujukan");
  p = await open("TanpaRef", "barang_per_gudang"); await p.waitForSelector("#rb .rtb");
  ok((await text(p, "#pg")).includes("Daftar gudang tidak bisa dimuat") && (await p.locator("#fw").isDisabled()), "daftar gudang ditolak: peringatan, pilihan nonaktif, laporan tetap jalan");
  await p.locator("#rb tr.row").nth(1).click(); await p.waitForSelector("#rb .rsum");
  ok((await p.inputValue("#fi")) === "10" && (await p.inputValue("#fw")) === "2" && (await p.locator("#fi").isDisabled()), "dari penelusuran: barang dan gudang terisi walau daftar tidak bisa dimuat");
  ok((await text(p, "#rb")).includes("Kopi <i>Arabika</i>") && (await text(p, "#rb")).includes("Gudang Cabang"), "judul memakai nama dari baris yang diklik");
  await p.context().close();

  /* ===== Ukuran layar kecil ===== */
  console.log("Layar sempit");
  p = await open("Owner", "barang_per_gudang", { width: 390, height: 800 }); await p.waitForSelector("#rb .rtb");
  ok(await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), "halaman tidak bergulir ke samping (tabel bergulir di dalam kartunya)");
  await shot(p, "barang_per_gudang_mobile");
  await p.context().close();

  /* ===== Bentuk respons ===== */
  console.log("Bentuk respons");
  p = await open("Owner", "barang_per_gudang"); await p.waitForSelector("#rb .rtb");
  ok(await p.evaluate(() => kBody({ rows: [1], next: null }).rows.length === 1 && kBody({ data: { rows: [1, 2] } }).rows.length === 2), "kBody menerima respons tanpa maupun dengan pembungkus data");
  ok(await p.evaluate(() => kQty("-0.0000") === "0" && kQty("1234.56789") === "1.234,5679" && kUnit("12500.555") === "12.500,56"), "format jumlah dan harga (nol negatif jadi 0)");
  await p.context().close();

  ok(errors.length === 0, "tanpa galat JS/konsol" + (errors.length ? ": " + errors.slice(0, 3).join(" | ") : ""));
  await browser.close(); server.close();
  console.log(`\n${pass} lulus, ${fail} gagal`);
  if (fail) { console.log("Gagal:\n- " + failures.join("\n- ")); process.exit(1); }
})().catch(e => { console.error(e); process.exit(1); });
