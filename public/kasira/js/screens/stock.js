/* Langkah 4f: Laporan persediaan (pola R, hanya baca): Barang per Gudang dan Kartu Stok.
   - Barang per Gudang = fitur sendiri (modul Gudang), GET /reports/stock-by-warehouse.
   - Kartu Stok = laporan di Daftar Laporan (seperti prototipe), GET /reports/stock-card. Keduanya memakai izin barang_per_gudang.
   PENTING: kedua endpoint ini membalas JSON TANPA pembungkus { data: ... } (beda dengan endpoint lain): { rows, next } dan
   { saldo_awal, rows, next_after_id, perlu_hitung_ulang }. kBody() menerima kedua bentuk.
   HPP dan nilai persediaan hanya dikirim server untuk pemegang hak khusus lihat_hpp; layar menyembunyikan kolomnya bila tidak ada.
   Dimuat setelah report.js (memakai rNum, rAmt, rTgl, rTitle, rBtns, rCsv, rErr, RTOK, RSEL, invRef, invToday). */

let KPRESET = null;   // {item_id, warehouse_id, item, wh}: diisi saat menelusuri baris Barang per Gudang ke Kartu Stok
let KBACK = false;    // Kartu Stok dibuka dari Barang per Gudang: tampilkan tombol kembali

/* ---------- format ---------- */
const kBody = r => (r && r.rows === undefined && r.data ? r.data : r);                        // tanpa/dengan pembungkus data
const kQty = v => (rNum(v) + 0).toLocaleString("id-ID", { maximumFractionDigits: 4 });         // jumlah barang boleh pecahan
const kUnit = v => (rNum(v) + 0).toLocaleString("id-ID", { maximumFractionDigits: 2 });        // harga satuan/HPP: dua desimal
const kSum = (rows, k) => rows.reduce((a, r) => a + rNum(r[k]), 0).toFixed(4);
const KSRC = { sales_invoice: "Faktur Penjualan", purchase_invoice: "Faktur Pembelian", stock_adjustment: "Penyesuaian Persediaan", opening_balance: "Saldo Awal", stock_recalc: "Hitung Ulang HPP" };
const kSrc = r => (KSRC[r.source_type] || r.source_type || "-") + " #" + r.source_id;
const kAwalTahun = () => invToday().slice(0, 4) + "-01-01";
const kDrillOn = () => can("barang_per_gudang") && can("daftar_laporan") && !!findFeature("daftar_laporan");
const kLabel = o => o ? invKn(o) : "";
const kCostNote = `<div class="warn i">ⓘ HPP dan nilai persediaan hanya tampil untuk peran dengan hak khusus Lihat HPP.</div>`;

/* =====================================================================================
   Barang per Gudang: stok tiap barang di tiap gudang. Keyset: after_item_id + after_warehouse_id.
   ===================================================================================== */
function stockWhScreen() {
  return async function (f, m, j) {
    const tok = ++RTOK.n, alive = () => tok === RTOK.n && cur && cur[0] === m && cur[1] === j;
    const S = { wh: "", q: "", ada: false, W: null, rows: [], next: null, cost: false, loading: false, err: null, seq: 0 };
    stockWhScreen.last = S;   // dipakai pengujian

    const qs = after => `limit=50${S.wh ? "&warehouse_id=" + encodeURIComponent(S.wh) : ""}${S.q.trim() ? "&q=" + encodeURIComponent(S.q.trim()) : ""}${S.ada ? "&hanya_ada_stok=1" : ""}${after ? "&after_item_id=" + after.after_item_id + "&after_warehouse_id=" + after.after_warehouse_id : ""}`;

    async function load(more) {
      const seq = ++S.seq; S.err = null; S.loading = true;
      if (!more) { S.rows = []; S.next = null; }
      drawBody();
      try {
        const b = kBody(await API.get("/reports/stock-by-warehouse?" + qs(more ? S.next : null)));
        if (!alive() || seq !== S.seq) return;
        S.rows = more ? S.rows.concat(b.rows || []) : (b.rows || []); S.next = b.next || null;
        S.cost = S.rows.some(r => "nilai_saldo" in r);
      } catch (e) { if (!alive() || seq !== S.seq) return; if (!more) S.rows = []; S.err = e.first ? e.first() : (e.message || "Gagal memuat laporan."); }
      S.loading = false; drawBody();
    }

    function shell() {
      const W = S.W;
      pg.innerHTML = head(f, m) + rBtns(f.slug) + `<div class="card noprint"><div class="fl rf">
        <label><span>Gudang</span><select id="fw" ${W.ok ? "" : "disabled"}><option value="">Semua gudang</option>${W.list.map(w => `<option value="${w.id}">${esc(invKn(w))}</option>`).join("")}</select></label>
        <label style="flex:1;min-width:180px"><span>Cari barang</span><input id="fq" placeholder="Kode atau nama barang"></label>
        <label class="ck"><input id="fa" type="checkbox"> Hanya yang ada stok</label></div>
        ${!W.ok ? `<div class="warn">Daftar gudang tidak bisa dimuat (tanpa hak lihat Gudang). Laporan menampilkan semua gudang.</div>` : ""}</div><div id="rb"></div>`;
      $("#fw").onchange = e => { S.wh = e.target.value; load(); };
      $("#fa").onchange = e => { S.ada = e.target.checked; load(); };
      let t = null; $("#fq").oninput = e => { S.q = e.target.value; clearTimeout(t); t = setTimeout(() => load(), 300); };
      const x = pg.querySelector("[data-x]"), p = pg.querySelector("[data-p]");
      if (x) x.onclick = exportCsv; if (p) p.onclick = () => window.print();
      drawBody();
    }

    function drawBody() {
      const el = $("#rb"); if (!el || !alive()) return;
      if (S.loading && !S.rows.length) { el.innerHTML = `<div class="card"><div class="empty">Memuat…</div></div>`; return; }
      if (S.err) { el.innerHTML = rErr(S, "rt"); $("#rt").onclick = () => load(); return; }
      const c = S.cost, dr = kDrillOn(), tunda = S.rows.some(r => r.perlu_hitung_ulang);
      const low = r => rNum(r.stok_minimum) > 0 && rNum(r.qty_saldo) < rNum(r.stok_minimum);
      el.innerHTML = `<div class="card">${rTitle("Barang per Gudang", S.wh && S.W.ok ? kLabel(S.W.list.find(w => String(w.id) === String(S.wh))) : "Semua gudang")}
        ${tunda ? `<div class="warn">Nilai dan HPP barang bertanda "Perlu hitung ulang" masih sementara sampai hitung ulang HPP dijalankan.</div>` : ""}
        <div class="tw" style="margin-top:10px"><table class="rtb"><thead><tr><th>Barang</th><th>Gudang</th><th class="num">Stok</th><th class="num">Stok minimum</th>${c ? `<th class="num">HPP rata-rata</th><th class="num">Nilai</th>` : ""}<th>Status</th></tr></thead><tbody>
        ${S.rows.map((r, i) => `<tr${dr ? ` class="row" tabindex="0" data-i="${i}" title="Lihat Kartu Stok"` : ""}><td><b>${esc(r.nama_barang)}</b> <small style="color:var(--mut)">${esc(r.kode_barang)}</small></td><td>${esc(r.nama_gudang)} <small style="color:var(--mut)">${esc(r.kode_gudang)}</small></td>
          <td class="num"><b>${kQty(r.qty_saldo)}</b></td><td class="num">${rNum(r.stok_minimum) > 0 ? kQty(r.stok_minimum) : "-"}</td>${c ? `<td class="num">${kUnit(r.hpp_rata_rata)}</td><td class="num">${rAmt(r.nilai_saldo)}</td>` : ""}
          <td>${low(r) ? `<span class="bd wn">Di bawah minimum</span> ` : ""}${r.perlu_hitung_ulang ? `<span class="bd gr">Perlu hitung ulang</span>` : ""}</td></tr>`).join("")}</tbody></table></div>
        ${!S.rows.length ? `<div class="empty">${S.q.trim() || S.ada || S.wh ? "Tidak ada barang yang cocok dengan filter." : "Belum ada saldo stok."}</div>` : ""}
        ${S.rows.length && !c ? kCostNote : ""}
        <div class="ft"><span>${S.rows.length} baris${S.next ? "" : " (semua)"}${dr ? " · klik baris untuk melihat Kartu Stok" : ""}</span>${S.next ? `<a class="lk noprint" id="more">${S.loading ? "Memuat…" : "Muat lainnya"}</a>` : "<span></span>"}</div></div>`;
      const mo = $("#more"); if (mo) mo.onclick = () => { if (!S.loading) load(true); };
      el.querySelectorAll("tr[data-i]").forEach(tr => {
        const go = () => kDrill(S.rows[+tr.dataset.i]);
        tr.onclick = go; tr.onkeydown = e => { if (e.key === "Enter") go(); };
      });
    }

    function kDrill(r) {
      const hit = findFeature("daftar_laporan"); if (!r || !hit) return;
      KPRESET = { item_id: String(r.item_id), warehouse_id: String(r.warehouse_id), item: { id: r.item_id, kode: r.kode_barang, nama: r.nama_barang }, wh: { id: r.warehouse_id, kode: r.kode_gudang, nama: r.nama_gudang } };
      KBACK = true; RSEL = "kartu_stok"; show(hit[0], hit[1]);
    }

    /* CSV memuat SEMUA baris yang cocok dengan filter (bukan hanya yang sudah tampil), halaman demi halaman. */
    async function exportCsv() {
      if (S.err || S.loading) return toast("Tunggu laporan selesai dimuat");
      toast("Menyiapkan CSV…");
      let all = [], after = null, cost = false;
      try {
        for (let n = 0; n < 100; n++) {
          const b = kBody(await API.get("/reports/stock-by-warehouse?" + qs(after).replace("limit=50", "limit=100")));
          all = all.concat(b.rows || []); cost = cost || all.some(r => "nilai_saldo" in r);
          if (!b.next) { after = null; break; } after = b.next;
        }
      } catch (e) { return toast(e.first ? e.first() : "Gagal menyiapkan CSV"); }
      if (!alive()) return;
      if (!all.length) return toast("Belum ada data untuk diekspor");
      const hd = ["Kode barang", "Nama barang", "Kode gudang", "Nama gudang", "Stok", "Stok minimum"].concat(cost ? ["HPP rata-rata", "Nilai"] : []);
      rCsv("barang_per_gudang", [hd].concat(all.map(r => [r.kode_barang, r.nama_barang, r.kode_gudang, r.nama_gudang, rNum(r.qty_saldo), rNum(r.stok_minimum)].concat(cost ? [rNum(r.hpp_rata_rata), rInt(r.nilai_saldo)] : []))));
      if (after) toast("Peringatan: CSV dipotong di 10.000 baris. Persempit filter.");
    }

    /* ---------- mulai ---------- */
    pg.innerHTML = head(f, m) + `<div class="card"><div class="empty">Memuat…</div></div>`;
    S.W = await invRef("/warehouses?limit=100");
    if (!alive()) return;
    shell(); load();
  };
}
SCREENS["barang_per_gudang"] = stockWhScreen();

/* =====================================================================================
   Kartu Stok: mutasi satu barang di satu gudang dengan saldo berjalan. Dipasang di panel Daftar Laporan.
   Keyset: after_id (id baris buku stok terakhir).
   ===================================================================================== */
function kartuStokView(ctx) {
  const { host, alive } = ctx, H = s => host.querySelector(s);
  const S = { item: "", wh: "", dari: kAwalTahun(), sampai: invToday(), I: { ok: true, list: [] }, W: { ok: true, list: [] }, sel: null, selW: null, rows: [], D: null, loading: false, err: null, seq: 0, back: KBACK, cost: false };
  KBACK = false;
  if (KPRESET) { S.item = KPRESET.item_id; S.wh = KPRESET.warehouse_id; S.sel = KPRESET.item; S.selW = KPRESET.wh; KPRESET = null; }
  kartuStokView.last = S;   // dipakai pengujian

  const items = () => { const l = S.I.list.slice(); if (S.sel && !l.some(x => String(x.id) === String(S.sel.id))) l.unshift(S.sel); return l; };
  const whs = () => { const l = S.W.list.slice(); if (S.selW && !l.some(x => String(x.id) === String(S.selW.id))) l.unshift(S.selW); return l; };
  const opts = (l, val, ph) => `<option value="">${ph}</option>` + l.map(x => `<option value="${x.id}" ${String(x.id) === String(val) ? "selected" : ""}>${esc(invKn(x))}</option>`).join("");

  const url = after => `/reports/stock-card?item_id=${encodeURIComponent(S.item)}&warehouse_id=${encodeURIComponent(S.wh)}&dari=${S.dari}&sampai=${S.sampai}&limit=100${after ? "&after_id=" + after : ""}`;

  async function load(more) {
    const seq = ++S.seq; S.err = null;
    if (!S.item || !S.wh || !S.dari || !S.sampai) { S.D = null; S.rows = []; S.loading = false; drawBody(); return; }
    if (S.sampai < S.dari) { S.D = null; S.rows = []; S.loading = false; S.err = "Tanggal akhir tidak boleh lebih awal dari tanggal awal."; drawBody(); return; }
    if (!more) { S.D = null; S.rows = []; }
    S.loading = true; drawBody();
    try {
      const b = kBody(await API.get(url(more && S.D ? S.D.next_after_id : null)));
      if (!alive() || seq !== S.seq) return;
      S.D = b; S.rows = more ? S.rows.concat(b.rows || []) : (b.rows || []);
      S.cost = !!(b.saldo_awal && "nilai" in b.saldo_awal);
    } catch (e) { if (!alive() || seq !== S.seq) return; if (!more) { S.D = null; S.rows = []; } S.err = e.status === 404 ? "Barang atau gudang tidak ditemukan di perusahaan ini." : (e.first ? e.first() : (e.message || "Gagal memuat Kartu Stok.")); }
    S.loading = false; drawBody();
  }

  async function searchItems(q) {
    const seq = S.iseq = (S.iseq || 0) + 1;   // penghitung sendiri: pencarian barang tidak mengganggu pemuatan kartu
    const r = await invRef("/items?limit=50&tipe=barang" + (q ? "&q=" + encodeURIComponent(q) : ""));
    if (!alive() || seq !== S.iseq) return;
    S.I = r;
    const el = H("#fi"); if (el) el.innerHTML = opts(items(), S.item, "Pilih barang…");
  }

  function shell() {
    host.innerHTML = (S.back ? `<div class="rtools noprint"><button class="btn" id="kb">Kembali ke Barang per Gudang</button></div>` : "") + rBtns(ctx.slug) + `<div class="card noprint"><div class="fl rf">
      <label style="width:120px"><span>Cari barang</span><input id="fk" placeholder="Kode/nama"></label>
      <label style="flex:1;min-width:180px"><span>Barang</span><select id="fi" ${S.I.ok ? "" : "disabled"}>${opts(items(), S.item, "Pilih barang…")}</select></label>
      <label><span>Gudang</span><select id="fw" ${S.W.ok ? "" : "disabled"}>${opts(whs(), S.wh, "Pilih gudang…")}</select></label>
      <label><span>Dari tanggal</span><input id="fd" type="date" value="${esc(S.dari)}"></label><label><span>Sampai tanggal</span><input id="fs" type="date" value="${esc(S.sampai)}"></label></div>
      ${!S.I.ok || !S.W.ok ? `<div class="warn">Daftar ${!S.I.ok ? "barang" : "gudang"} tidak bisa dimuat (tanpa hak lihat ${!S.I.ok ? "Barang & Jasa" : "Gudang"}). Buka Kartu Stok dari baris Barang per Gudang untuk memilih barang dan gudang.</div>` : ""}</div><div id="rb"></div>`;
    const on = (id, fn) => { H(id).onchange = e => { fn(e.target); load(); }; };
    on("#fi", e => { S.item = e.value; S.sel = items().find(x => String(x.id) === String(e.value)) || null; });
    on("#fw", e => { S.wh = e.value; S.selW = whs().find(x => String(x.id) === String(e.value)) || null; });
    on("#fd", e => { S.dari = e.value; }); on("#fs", e => { S.sampai = e.value; });
    let t = null; H("#fk").oninput = e => { const v = e.target.value.trim(); clearTimeout(t); t = setTimeout(() => searchItems(v), 300); };
    const kb = H("#kb"); if (kb) kb.onclick = () => { const hit = findFeature("barang_per_gudang"); if (hit) show(hit[0], hit[1]); };
    const x = H("[data-x]"), p = H("[data-p]");
    if (x) x.onclick = exportCsv; if (p) p.onclick = () => window.print();
    drawBody();
  }

  function drawBody() {
    const el = H("#rb"); if (!el || !alive()) return;
    if (S.loading && !S.rows.length) { el.innerHTML = `<div class="card"><div class="empty">Memuat…</div></div>`; return; }
    if (S.err) { el.innerHTML = rErr(S, "rt"); H("#rt").onclick = () => load(); return; }
    if (!S.D) { el.innerHTML = `<div class="card"><div class="empty">Pilih barang, gudang, dan rentang tanggal untuk melihat Kartu Stok.</div></div>`; return; }
    const D = S.D, c = S.cost, done = !D.next_after_id, last = S.rows[S.rows.length - 1];
    const akhirQty = last ? last.qty_saldo : D.saldo_awal.qty, akhirNilai = last ? last.nilai_saldo : D.saldo_awal.nilai;
    const nc = 3 + (c ? 3 : 0);
    el.innerHTML = `<div class="card">${rTitle("Kartu Stok: " + (S.sel ? invKn(S.sel) : "Barang #" + S.item), (S.selW ? invKn(S.selW) : "Gudang #" + S.wh) + " · " + rTgl(S.dari) + " s.d. " + rTgl(S.sampai))}
      ${D.perlu_hitung_ulang ? `<div class="warn">HPP barang ini menunggu hitung ulang (ada transaksi bertanggal mundur). Harga dan nilai pada kartu masih sementara.</div>` : ""}
      <div class="rsum" style="margin-top:10px"><div><span>Saldo awal</span><b class="num">${kQty(D.saldo_awal.qty)}</b></div><div><span>Total masuk</span><b class="num">${done ? kQty(kSum(S.rows, "qty_masuk")) : "…"}</b></div><div><span>Total keluar</span><b class="num">${done ? kQty(kSum(S.rows, "qty_keluar")) : "…"}</b></div><div><span>Saldo akhir</span><b class="num">${done ? kQty(akhirQty) : "…"}</b></div></div>
      <div class="tw" style="margin-top:10px"><table class="rtb"><thead><tr><th>Tanggal</th><th>Sumber</th><th class="num">Masuk</th><th class="num">Keluar</th><th class="num">Saldo</th>${c ? `<th class="num">Harga satuan</th><th class="num">Nilai</th><th class="num">Nilai saldo</th>` : ""}</tr></thead><tbody>
      <tr class="sub"><td colspan="4">Saldo awal</td><td class="num">${kQty(D.saldo_awal.qty)}</td>${c ? `<td></td><td></td><td class="num">${rAmt(D.saldo_awal.nilai)}</td>` : ""}</tr>
      ${S.rows.map(r => `<tr><td>${esc(rTgl(r.tanggal))}</td><td>${esc(kSrc(r))}${r.pembalik ? ` <span class="bd gr">Pembalik</span>` : ""}</td><td class="num">${rNum(r.qty_masuk) ? kQty(r.qty_masuk) : "-"}</td><td class="num">${rNum(r.qty_keluar) ? kQty(r.qty_keluar) : "-"}</td><td class="num"><b>${kQty(r.qty_saldo)}</b></td>${c ? `<td class="num">${kUnit(r.harga_satuan)}</td><td class="num">${rAmt(r.nilai)}</td><td class="num">${rAmt(r.nilai_saldo)}</td>` : ""}</tr>`).join("")}
      ${done ? `<tr class="tt"><td colspan="2">Total mutasi</td><td class="num">${kQty(kSum(S.rows, "qty_masuk"))}</td><td class="num">${kQty(kSum(S.rows, "qty_keluar"))}</td><td class="num">${kQty(akhirQty)}</td>${c ? `<td></td><td class="num">${rAmt(kSum(S.rows, "nilai"))}</td><td class="num">${rAmt(akhirNilai)}</td>` : ""}</tr>` : ""}</tbody></table></div>
      ${!S.rows.length ? `<div class="empty">Tidak ada pergerakan stok pada rentang ini.</div>` : ""}
      ${!c ? kCostNote : ""}
      <div class="ft"><span>${S.rows.length} baris mutasi${done ? " (semua)" : ""} · nomor dokumen belum disediakan server, sumber tampil sebagai jenis + id</span>${!done ? `<a class="lk noprint" id="more">${S.loading ? "Memuat…" : "Muat lainnya"}</a>` : "<span></span>"}</div></div>`;
    const mo = H("#more"); if (mo) mo.onclick = () => { if (!S.loading) load(true); };
  }

  /* CSV memuat semua baris pada rentang (halaman demi halaman), bukan hanya yang sudah tampil. */
  async function exportCsv() {
    if (!S.D || S.err || S.loading) return toast("Pilih barang dan gudang lebih dulu");
    toast("Menyiapkan CSV…");
    let all = [], after = null, first = null;
    try {
      for (let n = 0; n < 100; n++) {
        const b = kBody(await API.get(url(after).replace("limit=100", "limit=200")));
        first = first || b; all = all.concat(b.rows || []);
        if (!b.next_after_id) { after = null; break; } after = b.next_after_id;
      }
    } catch (e) { return toast(e.first ? e.first() : "Gagal menyiapkan CSV"); }
    if (!alive()) return;
    const c = !!(first.saldo_awal && "nilai" in first.saldo_awal);
    const hd = ["Tanggal", "Sumber", "ID sumber", "Pembalik", "Masuk", "Keluar", "Saldo"].concat(c ? ["Harga satuan", "Nilai", "Nilai saldo"] : []);
    const awal = ["", "Saldo awal", "", "", "", "", rNum(first.saldo_awal.qty)].concat(c ? ["", "", rInt(first.saldo_awal.nilai)] : []);
    rCsv("kartu_stok", [hd, awal].concat(all.map(r => [r.tanggal, KSRC[r.source_type] || r.source_type, r.source_id, r.pembalik ? "ya" : "", rNum(r.qty_masuk), rNum(r.qty_keluar), rNum(r.qty_saldo)].concat(c ? [rNum(r.harga_satuan), rInt(r.nilai), rInt(r.nilai_saldo)] : []))));
    if (after) toast("Peringatan: CSV dipotong di 20.000 baris. Persempit rentang tanggal.");
  }

  /* ---------- mulai ---------- */
  host.innerHTML = `<div class="card"><div class="empty">Memuat…</div></div>`;
  Promise.all([invRef("/items?limit=50&tipe=barang"), invRef("/warehouses?limit=100")]).then(([I, W]) => {
    if (!alive()) return;
    S.I = I; S.W = W; shell();
    if (S.item && S.wh) load();
  });
}
