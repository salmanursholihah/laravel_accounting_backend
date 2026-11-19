/* Langkah 4e: Laporan (pola R).
   Di katalog prototipe, Umur Piutang, Umur Utang, dan Laporan Keuangan BUKAN menu sendiri: semuanya dibuka dari
   "Daftar Laporan" (daftar di kiri, isi di kanan). Maka layar utamanya adalah SCREENS["daftar_laporan"]; tiap laporan
   dirender di panel kanan dan dijaga izinnya sendiri (umur_piutang, umur_utang, laporan_keuangan). Histori Akun
   adalah fitur tersendiri (modul Buku Besar) dengan layar sendiri; laporan menelusuri angka akun ke sana.
   Isi laporan: Neraca, Laba Rugi, Neraca Saldo, Buku Besar, Umur Piutang, Umur Utang, Histori Akun.
   Semua angka resmi dari server; layar hanya menampilkan (kecuali total Kewajiban + Ekuitas pada Neraca, dari dua
   total server). Laporan hanya membaca: tidak ada simpan/posting. Dimuat setelah invoice.js (memakai invRef, invToday). */

const RTOK = { n: 0 };
const RBLN = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];
let RPRESET = null;   // {account_id, dari, sampai}: diisi saat menelusuri angka ke Histori Akun
let RSEL = null;      // laporan terakhir yang dibuka di Daftar Laporan (kembali dari Histori Akun tetap di laporan itu)

/* ---------- format ---------- */
// Uang dari server berupa string 4 desimal. Tidak memakai u4 (x10000) supaya nilai besar tidak melewati batas presisi.
const rNum = v => { const n = parseFloat(v); return isFinite(n) ? n : 0; };
const rInt = v => Math.round(rNum(v)) + 0;                                   // "+ 0" mengubah -0 menjadi 0
const rAmt = v => { const n = rInt(v), s = Math.abs(n).toLocaleString("id-ID"); return n < 0 ? "(" + s + ")" : s; };
const rDash = v => rInt(v) === 0 ? "-" : rAmt(v);                            // kolom debit/kredit: nol tampil "-"
const rPct = v => v == null ? "-" : rNum(v).toLocaleString("id-ID", { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + "%";
const rTgl = s => { const d = String(s || "").split("-"); return d.length === 3 ? (+d[2]) + " " + RBLN[+d[1] - 1] + " " + d[0] : (s || "-"); };
const rPer = p => RBLN[+p.mulai.slice(5, 7) - 1] + " " + p.mulai.slice(0, 4);
const rBulanAwal = () => invToday().slice(0, 8) + "01";

/* ---------- periode (dari /fiscal-years, izin proses_akhir_bulan:view) ---------- */
async function rPeriods() {
  try {
    const r = await API.get("/fiscal-years", { silent: true }), list = [];
    (r.data || []).forEach(y => (y.periode || []).forEach(p => list.push({ id: p.id, mulai: p.mulai, selesai: p.selesai, status: p.status, tahun: y.tahun })));
    list.sort((a, b) => a.mulai < b.mulai ? -1 : a.mulai > b.mulai ? 1 : 0);
    return { ok: true, list };
  } catch (e) { return { ok: false, list: [] }; }
}
function rDefaultPeriod(list) {
  const t = invToday();
  return list.find(p => p.mulai <= t && t <= p.selesai) || list.filter(p => p.selesai <= t).pop() || list[list.length - 1] || null;
}
const rPeriodOpts = (list, val) => list.map(p => `<option value="${p.id}" ${String(p.id) === String(val) ? "selected" : ""}>${esc(rPer(p))}${p.status === "closed" ? " (ditutup)" : ""}</option>`).join("");

/* ---------- ekspor CSV dan cetak ---------- */
// Titik koma + BOM supaya terbuka benar di Excel berlokal Indonesia; angka dibulatkan ke rupiah penuh tanpa pemisah.
function rCsv(nama, rows) {
  const q = v => {
    let s = String(v == null ? "" : v);
    if (/^[=+@\t\r]/.test(s) || /^-(?!\d)/.test(s)) s = "'" + s;             // cegah rumus dari teks pengguna
    return /[";\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
  };
  const blob = new Blob(["\ufeff" + rows.map(r => r.map(q).join(";")).join("\r\n")], { type: "text/csv;charset=utf-8" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob); a.download = nama + "_" + invToday() + ".csv";
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}
const rBtns = slug => `<div class="rtools noprint">${can(slug, "export") ? `<button class="btn" data-x>Ekspor CSV</button>` : ""}${can(slug, "print") ? `<button class="btn" data-p>Cetak</button>` : ""}</div>`;
function rWire(host, nama, csv) {
  const x = host.querySelector("[data-x]"), p = host.querySelector("[data-p]");
  if (x) x.onclick = () => { const rows = csv(); rows ? rCsv(nama, rows) : toast("Belum ada data untuk diekspor"); };
  if (p) p.onclick = () => window.print();
}
const rTitle = (judul, sub) => `<div class="rt"><b>${esc(APP.company.nama)}</b><h3>${esc(judul)}</h3><span>${esc(sub)}</span></div>`;
const rErr = (S, id) => `<div class="card"><div class="warn e">${esc(S.err)} <a class="lk" id="${id}">Coba lagi</a></div></div>`;

/* Telusuri angka akun ke Histori Akun (hanya bila boleh melihat Histori Akun). */
function rDrill(accId, dari, sampai) {
  if (!can("histori_akun")) return;
  const hit = findFeature("histori_akun");
  if (!hit) return;
  RPRESET = { account_id: String(accId), dari, sampai };
  show(hit[0], hit[1]);
}
const rDrillOn = () => can("histori_akun") && !!findFeature("histori_akun");
function rBindDrill(root) {
  root.querySelectorAll("[data-acc]").forEach(tr => {
    const go = () => rDrill(tr.dataset.acc, tr.dataset.d, tr.dataset.s);
    tr.onclick = go; tr.onkeydown = e => { if (e.key === "Enter") go(); };
  });
}
const rDrillAttr = (id, d, s) => id != null && rDrillOn() ? ` class="row" tabindex="0" data-acc="${esc(id)}" data-d="${esc(d)}" data-s="${esc(s)}" title="Lihat Histori Akun"` : "";

/* =====================================================================================
   Pemasangan laporan di panel: ctx = { host, slug, alive }.
   alive() false bila layar sudah berganti (laporan lain dipilih, pindah menu, ganti perusahaan): hasil lama dibuang.
   ===================================================================================== */

/* ---------- Umur Piutang dan Umur Utang ---------- */
const AGING = {
  umur_piutang: { path: "/reports/ar-aging", party: "Pelanggan", judul: "Umur Piutang", kosong: "Tidak ada piutang terbuka" },
  umur_utang: { path: "/reports/ap-aging", party: "Pemasok", judul: "Umur Utang", kosong: "Tidak ada utang terbuka" },
};
const AGB = [["belum_jatuh_tempo", "Belum jatuh tempo", "var(--t)"], ["d1_30", "1–30 hari", "var(--ac)"], ["d31_60", "31–60 hari", "var(--k)"], ["d61_90", "61–90 hari", "var(--bad)"], ["d_lebih_90", "> 90 hari", "var(--l)"]];

function agingView(cfg, ctx) {
  const { host, alive } = ctx, H = s => host.querySelector(s);
  const S = { per: invToday(), mode: "ringkas", q: "", D: null, loading: true, err: null, seq: 0 };
  agingView.last = S;   // dipakai pengujian

  async function load() {
    const seq = ++S.seq; S.loading = true; S.err = null; drawBody();
    try {
      const r = await API.get(cfg.path + "?per_tanggal=" + encodeURIComponent(S.per) + "&mode=" + S.mode);
      if (!alive() || seq !== S.seq) return;
      S.D = r.data;
    } catch (e) { if (!alive() || seq !== S.seq) return; S.D = null; S.err = e.first ? e.first() : (e.message || "Gagal memuat laporan."); }
    S.loading = false; drawBody();
  }

  const shown = () => { const q = S.q.trim().toLowerCase(); return S.D.kontak.filter(k => !q || String(k.nama || "").toLowerCase().includes(q) || String(k.kode || "").toLowerCase().includes(q)); };

  function drawBody() {
    const el = H("#rb"); if (!el || !alive()) return;
    if (S.loading) { el.innerHTML = `<div class="card"><div class="empty">Memuat…</div></div>`; return; }
    if (S.err) { el.innerHTML = rErr(S, "rt"); H("#rt").onclick = load; return; }
    const D = S.D, T = D.total, tot = rNum(T.total), list = shown(), rinci = D.mode === "rinci";
    const pct = v => tot > 0 ? rNum(v) / tot * 100 : 0;
    const bar = tot > 0 ? `<div class="rbar" role="img" aria-label="Komposisi umur">${AGB.map(b => `<i style="width:${pct(T.buckets[b[0]]).toFixed(2)}%;background:${b[2]}" title="${esc(b[1])}: ${rAmt(T.buckets[b[0]])}"></i>`).join("")}</div>
      <div class="rleg">${AGB.map(b => `<span><i style="background:${b[2]}"></i>${esc(b[1])} <b>${rAmt(T.buckets[b[0]])}</b> (${pct(T.buckets[b[0]]).toFixed(0)}%)</span>`).join("")}</div>` : "";
    const rows = list.map(k => `<tr class="ct"><td><b>${esc(k.nama)}</b>${k.kode ? ` <small style="color:var(--mut)">${esc(k.kode)}</small>` : ""}${rinci ? "" : ` <small style="color:var(--mut)">· ${k.jumlah_faktur} faktur</small>`}</td>
        ${AGB.map(b => `<td class="num">${rDash(k.buckets[b[0]])}</td>`).join("")}<td class="num"><b>${rAmt(k.total)}</b></td></tr>` +
      (rinci ? (k.faktur || []).map(x => `<tr class="sub"><td>${esc(x.nomor)} · ${esc(rTgl(x.tanggal))}${x.jatuh_tempo ? " · JT " + esc(rTgl(x.jatuh_tempo)) : ""}${x.hari_lewat_jatuh_tempo > 0 ? " · lewat " + x.hari_lewat_jatuh_tempo + " hari" : ""}</td>
        ${AGB.map(b => `<td class="num">${x.bucket === b[0] ? rAmt(x.sisa) : ""}</td>`).join("")}<td class="num">${rAmt(x.sisa)}</td></tr>`).join("") : "")).join("");
    el.innerHTML = `<div class="card">${rTitle(cfg.judul, "Per " + rTgl(D.per_tanggal))}
      ${bar}
      <div class="tw" style="margin-top:12px"><table class="rtb"><thead><tr><th>${esc(cfg.party)}</th>${AGB.map(b => `<th class="num">${esc(b[1])}</th>`).join("")}<th class="num">Total</th></tr></thead><tbody>${rows}
      ${list.length ? `<tr class="tt"><td>Total semua ${esc(cfg.party.toLowerCase())}</td>${AGB.map(b => `<td class="num">${rDash(T.buckets[b[0]])}</td>`).join("")}<td class="num">${rAmt(T.total)}</td></tr>` : ""}</tbody></table></div>
      ${!D.kontak.length ? `<div class="empty">${esc(cfg.kosong)} per ${esc(rTgl(D.per_tanggal))}.</div>` : !list.length ? `<div class="empty">Tidak ada ${esc(cfg.party.toLowerCase())} yang cocok dengan pencarian.</div>` : ""}
      <div class="ft"><span>${list.length === D.kontak.length ? D.kontak.length + " " + cfg.party.toLowerCase() : "Menampilkan " + list.length + " dari " + D.kontak.length + " " + cfg.party.toLowerCase()} · ${T.jumlah_faktur} faktur terbuka</span><span>Dalam Rupiah</span></div>
      <div class="warn i">ⓘ Sisa dihitung per tanggal laporan dari faktur dan alokasi pembayaran yang berlaku pada tanggal itu. Retur dan uang muka belum dikurangkan karena modulnya belum ada.</div></div>`;
  }

  function csv() {
    if (!S.D || S.loading || S.err) return null;
    const D = S.D, out = [["Kode", "Nama", "Nomor faktur", "Tanggal", "Jatuh tempo"].concat(AGB.map(b => b[1]), ["Total"])];
    D.kontak.forEach(k => {
      out.push([k.kode || "", k.nama, "", "", ""].concat(AGB.map(b => rInt(k.buckets[b[0]])), [rInt(k.total)]));
      (k.faktur || []).forEach(x => out.push(["", "", x.nomor, x.tanggal, x.jatuh_tempo || ""].concat(AGB.map(b => x.bucket === b[0] ? rInt(x.sisa) : ""), [rInt(x.sisa)])));
    });
    out.push(["", "Total", "", "", ""].concat(AGB.map(b => rInt(D.total.buckets[b[0]])), [rInt(D.total.total)]));
    return out;
  }

  host.innerHTML = rBtns(ctx.slug) + `<div class="card noprint"><div class="fl rf">
    <label><span>Per tanggal</span><input id="fp" type="date" value="${esc(S.per)}"></label>
    <label><span>Tampilan</span><select id="fm"><option value="ringkas">Ringkas (per ${cfg.party.toLowerCase()})</option><option value="rinci">Rinci (dengan faktur)</option></select></label>
    <label style="flex:1;min-width:150px"><span>Cari ${cfg.party.toLowerCase()}</span><input id="fq" placeholder="Nama atau kode"></label></div></div><div id="rb"></div>`;
  H("#fp").onchange = e => { S.per = e.target.value; if (S.per) load(); };
  H("#fm").onchange = e => { S.mode = e.target.value; load(); };
  H("#fq").oninput = e => { S.q = e.target.value; drawBody(); };
  rWire(host, cfg.judul.toLowerCase().replace(/ /g, "_"), csv);
  load();
}

/* ---------- Laporan Keuangan: Neraca, Laba Rugi, Neraca Saldo, Buku Besar ---------- */
const FINCMP = [["", "Tanpa pembanding"], ["sebelumnya", "Periode sebelumnya"], ["tahun_lalu", "Tahun lalu"]];
const FINJUDUL = { neraca: "Neraca", labarugi: "Laba Rugi", neracasaldo: "Neraca Saldo", bukubesar: "Buku Besar" };

async function finView(tab, ctx) {
  const { host, alive } = ctx, H = s => host.querySelector(s);
  const S = { tab, P: null, per: "", dari: "", sampai: "", mode: "rinci", cmp: "", closing: false, bbDari: "", bbSampai: "", D: null, loading: true, err: null, seq: 0 };
  finView.last = S;   // dipakai pengujian
  host.innerHTML = `<div class="card"><div class="empty">Memuat…</div></div>`;

  const pById = id => S.P.list.find(p => String(p.id) === String(id));

  /* ---------- kontrol ---------- */
  function shell() {
    if (!alive()) return;
    host.innerHTML = rBtns(ctx.slug) + `<div class="card noprint"><div class="fl rf" id="rc"></div>${!S.P.ok ? `<div class="warn e">Daftar periode tidak dapat dimuat. Laporan per periode tidak tersedia.</div>` : !S.P.list.length && tab !== "bukubesar" ? `<div class="warn">Belum ada tahun buku. Buat tahun buku lebih dulu di Proses Akhir Bulan.</div>` : ""}</div><div id="rb"></div>`;
    drawControls();
    rWire(host, "laporan_" + tab, csv);
  }

  function drawControls() {
    const el = H("#rc"); if (!el) return;
    const per = (id, lb, val) => `<label><span>${lb}</span><select id="${id}" ${S.P.list.length ? "" : "disabled"}>${rPeriodOpts(S.P.list, val)}</select></label>`;
    const mode = `<label><span>Tampilan</span><select id="fm"><option value="rinci" ${S.mode === "rinci" ? "selected" : ""}>Rinci (per akun)</option><option value="ringkas" ${S.mode === "ringkas" ? "selected" : ""}>Ringkas (per akun induk)</option></select></label>`;
    const cmp = `<label><span>Bandingkan</span><select id="fc">${FINCMP.map(c => `<option value="${c[0]}" ${S.cmp === c[0] ? "selected" : ""}>${c[1]}</option>`).join("")}</select></label>`;
    const clo = `<label class="ck"><input id="fk" type="checkbox" ${S.closing ? "checked" : ""}> Termasuk jurnal penutup</label>`;
    el.innerHTML = tab === "neraca" ? per("fp", "Per akhir periode", S.per) + mode + cmp
      : tab === "labarugi" ? per("fd", "Dari periode", S.dari) + per("fs", "Sampai periode", S.sampai) + mode + cmp
        : tab === "neracasaldo" ? per("fp", "Periode", S.per) + clo
          : `<label><span>Dari tanggal</span><input id="fbd" type="date" value="${esc(S.bbDari)}"></label><label><span>Sampai tanggal</span><input id="fbs" type="date" value="${esc(S.bbSampai)}"></label>` + clo;
    const on = (id, fn) => { const e = H(id); if (e) e.onchange = ev => { fn(ev.target); load(); }; };
    on("#fp", e => { S.per = e.value; }); on("#fd", e => { S.dari = e.value; }); on("#fs", e => { S.sampai = e.value; });
    on("#fm", e => { S.mode = e.value; }); on("#fc", e => { S.cmp = e.value; }); on("#fk", e => { S.closing = e.checked; });
    on("#fbd", e => { S.bbDari = e.value; }); on("#fbs", e => { S.bbSampai = e.value; });
  }

  /* Ambil data. Buku Besar memakai paginasi keyset: 'more' menambah baris. Rentang terbalik ditolak di layar. */
  async function load(more) {
    const seq = ++S.seq; S.err = null;
    const stop = msg => { S.D = null; S.loading = false; S.err = msg || null; drawBody(); };
    let url;
    if (tab === "neraca" || tab === "neracasaldo") {
      if (!S.per) return stop();
      url = tab === "neraca" ? `/reports/balance-sheet?period_id=${S.per}&mode=${S.mode}${S.cmp ? "&bandingkan=" + S.cmp : ""}` : `/reports/trial-balance?period_id=${S.per}${S.closing ? "&termasuk_penutup=1" : ""}`;
    } else if (tab === "labarugi") {
      const a = pById(S.dari), b = pById(S.sampai);
      if (!a || !b) return stop();
      if (b.selesai < a.mulai) return stop("Periode akhir tidak boleh lebih awal dari periode awal.");
      url = `/reports/income-statement?dari_period_id=${S.dari}&sampai_period_id=${S.sampai}&mode=${S.mode}${S.cmp ? "&bandingkan=" + S.cmp : ""}`;
    } else {
      if (!S.bbDari || !S.bbSampai) return stop();
      if (S.bbSampai < S.bbDari) return stop("Tanggal akhir tidak boleh lebih awal dari tanggal awal.");
      url = `/reports/general-ledger?dari=${S.bbDari}&sampai=${S.bbSampai}&limit=100${S.closing ? "&termasuk_penutup=1" : ""}${more && S.D && S.D.next_cursor ? "&cursor=" + encodeURIComponent(S.D.next_cursor) : ""}`;
    }
    if (!more) { S.D = null; S.loading = true; drawBody(); }
    try {
      const r = await API.get(url);
      if (!alive() || seq !== S.seq) return;
      if (more && S.D) r.data.rows = S.D.rows.concat(r.data.rows);
      S.D = r.data;
    } catch (e) { if (!alive() || seq !== S.seq) return; if (!more) S.D = null; S.err = e.first ? e.first() : (e.message || "Gagal memuat laporan."); }
    S.loading = false; drawBody();
  }

  /* ---------- penyusun tabel ---------- */
  const cmpOn = D => !!D.pembanding;
  const nCols = D => 2 + (cmpOn(D) ? 3 : 0);
  const thCmp = D => cmpOn(D) ? `<th class="num">Pembanding</th><th class="num">Selisih</th><th class="num">%</th>` : "";
  const tdCells = (c, D) => `<td class="num">${rAmt(c.jumlah)}</td>` + (cmpOn(D) ? `<td class="num">${c.pembanding == null ? "-" : rAmt(c.pembanding)}</td><td class="num">${c.selisih == null ? "-" : rAmt(c.selisih)}</td><td class="num">${rPct(c.persen)}</td>` : "");
  const secRows = (title, sec, D, dari, sampai, drill) => `<tr class="sec"><td colspan="${nCols(D)}">${esc(title)}</td></tr>` +
    sec.rows.map(r => `<tr class="ind"${drill ? rDrillAttr(r.account_id, dari, sampai) : ""}><td>${r.kode ? esc(r.kode) + " - " : ""}${esc(r.nama)}</td>${tdCells(r, D)}</tr>`).join("") +
    `<tr class="tt"><td>Total ${esc(title.toLowerCase())}</td>${tdCells(sec.total, D)}</tr>`;
  const totRow = (label, c, D) => `<tr class="tt"><td>${esc(label)}</td>${tdCells(c, D)}</tr>`;
  const banner = D => (D.catatan ? `<div class="warn">${esc(D.catatan)}</div>` : "");

  function bodyNeraca(D) {
    const dr = S.mode === "rinci", p = D.periode, ke = { jumlah: (rNum(D.kewajiban.total.jumlah) + rNum(D.ekuitas.total.jumlah)).toFixed(4) };
    if (cmpOn(D)) {
      ke.pembanding = (rNum(D.kewajiban.total.pembanding) + rNum(D.ekuitas.total.pembanding)).toFixed(4);
      ke.selisih = (rNum(ke.jumlah) - rNum(ke.pembanding)).toFixed(4);
      ke.persen = rNum(ke.pembanding) === 0 ? null : (rNum(ke.selisih) / Math.abs(rNum(ke.pembanding)) * 100).toFixed(2);
    }
    return `${rTitle("Neraca", "Per " + rTgl(D.per_tanggal) + (cmpOn(D) ? " · pembanding per " + rTgl(D.pembanding.per_tanggal) : ""))}
      ${D.seimbang ? `<span class="bd ok">Seimbang</span>` : `<div class="warn e">Neraca tidak seimbang, selisih ${rAmt(D.selisih)}. Periksa jurnal yang diposting.</div>`}${banner(D)}
      <div class="tw" style="margin-top:10px"><table class="rtb"><thead><tr><th>Uraian</th><th class="num">Jumlah</th>${thCmp(D)}</tr></thead><tbody>
      ${secRows("Aset", D.aset, D, p.mulai, p.selesai, dr)}${secRows("Kewajiban", D.kewajiban, D, p.mulai, p.selesai, dr)}${secRows("Ekuitas", D.ekuitas, D, p.mulai, p.selesai, dr)}
      ${totRow("Total kewajiban dan ekuitas", ke, D)}</tbody></table></div>`;
  }

  function bodyLabaRugi(D) {
    const dr = S.mode === "rinci", p = D.periode, ada = sec => sec.rows.length || rInt(sec.total.jumlah) !== 0;
    return `${rTitle("Laba Rugi", rTgl(p.dari) + " s.d. " + rTgl(p.sampai) + (cmpOn(D) ? " · pembanding " + rTgl(D.pembanding.dari) + " s.d. " + rTgl(D.pembanding.sampai) : ""))}${banner(D)}
      <div class="tw" style="margin-top:10px"><table class="rtb"><thead><tr><th>Uraian</th><th class="num">Jumlah</th>${thCmp(D)}</tr></thead><tbody>
      ${secRows("Pendapatan", D.pendapatan, D, p.dari, p.sampai, dr)}${secRows("HPP", D.hpp, D, p.dari, p.sampai, dr)}${totRow("Laba kotor", D.laba_kotor, D)}
      ${secRows("Beban operasional", D.beban_operasional, D, p.dari, p.sampai, dr)}${totRow("Laba usaha", D.laba_usaha, D)}
      ${ada(D.pendapatan_lain) ? secRows("Pendapatan lain", D.pendapatan_lain, D, p.dari, p.sampai, dr) : ""}${ada(D.beban_lain) ? secRows("Beban lain", D.beban_lain, D, p.dari, p.sampai, dr) : ""}
      ${totRow("Laba bersih", D.laba_bersih, D)}</tbody></table></div>`;
  }

  const TBK = ["awal_debit", "awal_kredit", "mutasi_debit", "mutasi_kredit", "akhir_debit", "akhir_kredit"];
  function bodyNeracaSaldo(D) {
    const p = D.periode, T = D.total;
    return `${rTitle("Neraca Saldo", rPer(p) + " · " + rTgl(p.mulai) + " s.d. " + rTgl(p.selesai))}
      ${D.seimbang ? `<span class="bd ok">Seimbang</span>` : `<div class="warn e">Tidak seimbang, selisih ${rAmt(D.selisih)}.</div>`}
      ${D.sebelum_penutup ? `<div class="warn i">Belum termasuk jurnal penutup. Centang "Termasuk jurnal penutup" untuk melihat saldo setelah tutup tahun.</div>` : ""}
      <div class="tw" style="margin-top:10px"><table class="rtb"><thead><tr><th rowspan="2">Akun</th><th class="num" colspan="2">Saldo awal</th><th class="num" colspan="2">Mutasi</th><th class="num" colspan="2">Saldo akhir</th></tr>
      <tr><th class="num">Debit</th><th class="num">Kredit</th><th class="num">Debit</th><th class="num">Kredit</th><th class="num">Debit</th><th class="num">Kredit</th></tr></thead><tbody>
      ${D.rows.map(r => `<tr${rDrillAttr(r.account_id, p.mulai, p.selesai)}><td>${esc(r.kode)} - ${esc(r.nama)}</td>${TBK.map(k => `<td class="num">${rDash(r[k])}</td>`).join("")}</tr>`).join("")}
      <tr class="tt"><td>Total</td>${TBK.map(k => `<td class="num">${rAmt(T[k])}</td>`).join("")}</tr></tbody></table></div>
      ${!D.rows.length ? `<div class="empty">Belum ada saldo atau mutasi pada periode ini.</div>` : ""}`;
  }

  function bodyBukuBesar(D) {
    const p = D.periode;
    return `${rTitle("Buku Besar", rTgl(p.dari) + " s.d. " + rTgl(p.sampai))}
      ${D.sebelum_penutup ? `<div class="warn i">Belum termasuk jurnal penutup.</div>` : ""}
      <div class="tw" style="margin-top:10px"><table class="rtb"><thead><tr><th>Akun</th><th class="num">Saldo awal</th><th class="num">Debit</th><th class="num">Kredit</th><th class="num">Saldo akhir</th></tr></thead><tbody>
      ${D.rows.map(r => `<tr${rDrillAttr(r.account_id, p.dari, p.sampai)}><td>${esc(r.kode)} - ${esc(r.nama)}</td><td class="num">${rAmt(r.saldo_awal)}</td><td class="num">${rDash(r.debit)}</td><td class="num">${rDash(r.kredit)}</td><td class="num"><b>${rAmt(r.saldo_akhir)}</b></td></tr>`).join("")}</tbody></table></div>
      ${!D.rows.length ? `<div class="empty">Tidak ada mutasi pada rentang ini.</div>` : ""}
      <div class="ft"><span>${D.rows.length} akun${D.next_cursor ? "" : " (semua)"} · saldo sesuai saldo normal akun</span>${D.next_cursor ? `<a class="lk noprint" id="more">Muat lainnya</a>` : "<span></span>"}</div>`;
  }

  function drawBody() {
    const el = H("#rb"); if (!el || !alive()) return;
    if (S.loading) { el.innerHTML = `<div class="card"><div class="empty">Memuat…</div></div>`; return; }
    if (S.err) { el.innerHTML = rErr(S, "rt"); H("#rt").onclick = () => load(); return; }
    if (!S.D) { el.innerHTML = `<div class="card"><div class="empty">Pilih periode untuk menampilkan laporan.</div></div>`; return; }
    const D = S.D, tel = rDrillOn() && (tab === "neracasaldo" || tab === "bukubesar" || S.mode === "rinci");
    el.innerHTML = `<div class="card">${tab === "neraca" ? bodyNeraca(D) : tab === "labarugi" ? bodyLabaRugi(D) : tab === "neracasaldo" ? bodyNeracaSaldo(D) : bodyBukuBesar(D)}
      <div class="ft"><span>Dalam Rupiah${tel ? " · klik baris akun untuk melihat Histori Akun" : ""}</span><span></span></div></div>`;
    rBindDrill(el);
    const mo = H("#more"); if (mo) mo.onclick = () => load(true);
  }

  function csv() {
    const D = S.D; if (!D || S.loading || S.err) return null;
    const cm = cmpOn(D), hd = ["Uraian", "Jumlah"].concat(cm ? ["Pembanding", "Selisih", "Persen"] : []);
    const cell = o => [rInt(o.jumlah)].concat(cm ? [o.pembanding == null ? "" : rInt(o.pembanding), o.selisih == null ? "" : rInt(o.selisih), o.persen == null ? "" : o.persen] : []);
    const sec = (t, s) => [[t].concat(Array(cm ? 4 : 1).fill(""))].concat(s.rows.map(r => [(r.kode ? r.kode + " - " : "") + r.nama].concat(cell(r))), [["Total " + t.toLowerCase()].concat(cell(s.total))]);
    if (tab === "neraca") return [hd].concat(sec("Aset", D.aset), sec("Kewajiban", D.kewajiban), sec("Ekuitas", D.ekuitas));
    if (tab === "labarugi") return [hd].concat(sec("Pendapatan", D.pendapatan), sec("HPP", D.hpp), [["Laba kotor"].concat(cell(D.laba_kotor))], sec("Beban operasional", D.beban_operasional), [["Laba usaha"].concat(cell(D.laba_usaha))], sec("Pendapatan lain", D.pendapatan_lain), sec("Beban lain", D.beban_lain), [["Laba bersih"].concat(cell(D.laba_bersih))]);
    if (tab === "neracasaldo") return [["Kode", "Nama", "Awal debit", "Awal kredit", "Mutasi debit", "Mutasi kredit", "Akhir debit", "Akhir kredit"]].concat(D.rows.map(r => [r.kode, r.nama].concat(TBK.map(k => rInt(r[k])))), [["", "Total"].concat(TBK.map(k => rInt(D.total[k])))]);
    return [["Kode", "Nama", "Saldo awal", "Debit", "Kredit", "Saldo akhir"]].concat(D.rows.map(r => [r.kode, r.nama, rInt(r.saldo_awal), rInt(r.debit), rInt(r.kredit), rInt(r.saldo_akhir)]));
  }

  /* ---------- mulai ---------- */
  S.P = await rPeriods();
  if (!alive()) return;
  const L = S.P.list, dp = rDefaultPeriod(L), first = dp && L.find(p => p.tahun === dp.tahun);
  S.per = dp ? String(dp.id) : "";
  S.dari = first ? String(first.id) : "";          // Laba Rugi: awal tahun buku sampai periode terpilih
  S.sampai = S.per;
  S.bbDari = dp ? dp.mulai : rBulanAwal(); S.bbSampai = dp ? dp.selesai : invToday();
  shell();
  if (tab === "bukubesar" || S.per) load(); else { S.loading = false; drawBody(); }
}

/* =====================================================================================
   Histori Akun: fitur tersendiri (modul Buku Besar), mutasi satu akun dengan saldo berjalan
   ===================================================================================== */
const RTIPE = { general: "Umum", auto: "Otomatis", correction: "Koreksi", opening: "Saldo awal", closing: "Penutup", reversal: "Pembalik" };

function histScreen() {
  return async function (f, m, j) {
    const tok = ++RTOK.n, alive = () => tok === RTOK.n && cur && cur[0] === m && cur[1] === j;
    const S = { acc: "", dari: rBulanAwal(), sampai: invToday(), closing: false, A: null, D: null, rows: [], loading: false, err: null, seq: 0 };
    if (RPRESET) { S.acc = RPRESET.account_id; S.dari = RPRESET.dari; S.sampai = RPRESET.sampai; RPRESET = null; }
    histScreen.last = S;   // dipakai pengujian

    function shell() {
      const A = S.A, named = S.D ? S.D.account : null;
      // Tanpa hak lihat Akun Perkiraan, akun hanya bisa datang dari penelusuran laporan: tampilkan satu pilihan itu.
      const opts = A.ok ? A.list.map(a => `<option value="${a.id}" ${String(a.id) === String(S.acc) ? "selected" : ""}>${esc(invKn(a))}</option>`).join("")
        : (S.acc ? `<option value="${esc(S.acc)}" selected>${esc(named ? invKn(named) : "Akun #" + S.acc)}</option>` : "");
      pg.innerHTML = head(f, m, `<button class="btn" id="bk" ${RSEL ? "" : "hidden"}>Kembali ke laporan</button>`) + rBtns(f.slug) + `<div class="card noprint"><div class="fl rf">
        <label style="flex:1;min-width:220px"><span>Akun</span><select id="fa" ${A.ok ? "" : "disabled"}><option value="">Pilih akun…</option>${opts}</select></label>
        <label><span>Dari tanggal</span><input id="fd" type="date" value="${esc(S.dari)}"></label><label><span>Sampai tanggal</span><input id="fs" type="date" value="${esc(S.sampai)}"></label>
        <label class="ck"><input id="fk" type="checkbox" ${S.closing ? "checked" : ""}> Termasuk jurnal penutup</label></div>
        ${!A.ok ? `<div class="warn">Daftar akun tidak bisa dimuat (tanpa hak lihat Akun Perkiraan). Buka Histori Akun dari Daftar Laporan untuk memilih akun.</div>` : ""}</div><div id="rb"></div>`;
      const on = (id, fn) => { $(id).onchange = e => { fn(e.target); load(); }; };
      on("#fa", e => { S.acc = e.value; }); on("#fd", e => { S.dari = e.value; }); on("#fs", e => { S.sampai = e.value; }); on("#fk", e => { S.closing = e.checked; });
      const bk = $("#bk"); if (bk) bk.onclick = () => { const hit = findFeature("daftar_laporan"); if (hit) show(hit[0], hit[1]); };
      rWire(pg, "histori_akun", csv);
      drawBody();
    }

    async function load(more) {
      const seq = ++S.seq; S.err = null;
      if (!S.acc || !S.dari || !S.sampai) { S.D = null; S.rows = []; S.loading = false; drawBody(); return; }
      if (S.sampai < S.dari) { S.D = null; S.rows = []; S.loading = false; S.err = "Tanggal akhir tidak boleh lebih awal dari tanggal awal."; drawBody(); return; }
      if (!more) { S.D = null; S.rows = []; }
      S.loading = true; drawBody();
      try {
        const qs = `account_id=${encodeURIComponent(S.acc)}&dari=${S.dari}&sampai=${S.sampai}&limit=100${S.closing ? "&termasuk_penutup=1" : ""}${more && S.D && S.D.next_cursor ? "&cursor=" + encodeURIComponent(S.D.next_cursor) : ""}`;
        const r = await API.get("/reports/account-history?" + qs);
        if (!alive() || seq !== S.seq) return;
        S.D = r.data; S.rows = more ? S.rows.concat(r.data.rows) : r.data.rows;
      } catch (e) { if (!alive() || seq !== S.seq) return; if (!more) { S.D = null; S.rows = []; } S.err = e.status === 404 ? "Akun tidak ditemukan di perusahaan ini." : (e.first ? e.first() : (e.message || "Gagal memuat histori.")); }
      S.loading = false;
      if (!more && !S.A.ok && S.D) shell(); else drawBody();   // tanpa daftar akun, nama akun perlu digambar ulang di pilihan
    }

    function drawBody() {
      const el = $("#rb"); if (!el || !alive()) return;
      if (S.loading && !S.rows.length) { el.innerHTML = `<div class="card"><div class="empty">Memuat…</div></div>`; return; }
      if (S.err) { el.innerHTML = rErr(S, "rt"); $("#rt").onclick = () => load(); return; }
      if (!S.D) { el.innerHTML = `<div class="card"><div class="empty">${S.acc ? "Memuat…" : "Pilih akun dan rentang tanggal untuk melihat mutasi."}</div></div>`; return; }
      const D = S.D;
      el.innerHTML = `<div class="card">${rTitle("Histori Akun: " + invKn(D.account), rTgl(D.dari) + " s.d. " + rTgl(D.sampai))}
        <div class="rsum"><div><span>Saldo awal</span><b class="num">${rAmt(D.saldo_awal)}</b></div><div><span>Total debit</span><b class="num">${rAmt(D.total_debit)}</b></div><div><span>Total kredit</span><b class="num">${rAmt(D.total_kredit)}</b></div><div><span>Saldo akhir</span><b class="num">${rAmt(D.saldo_akhir)}</b></div></div>
        <div class="tw" style="margin-top:10px"><table class="rtb"><thead><tr><th>Tanggal</th><th>Nomor</th><th>Tipe</th><th>Memo</th><th class="num">Debit</th><th class="num">Kredit</th><th class="num">Saldo</th></tr></thead><tbody>
        <tr class="sub"><td colspan="6">Saldo awal</td><td class="num">${rAmt(D.saldo_awal)}</td></tr>
        ${S.rows.map(r => `<tr><td>${esc(rTgl(r.tanggal))}</td><td>${esc(r.nomor || "-")}</td><td>${esc(RTIPE[r.tipe] || r.tipe || "")}</td><td>${esc(r.memo || "")}</td><td class="num">${rDash(r.debit)}</td><td class="num">${rDash(r.kredit)}</td><td class="num">${rAmt(r.saldo)}</td></tr>`).join("")}
        <tr class="tt"><td colspan="4">Total mutasi</td><td class="num">${rAmt(D.total_debit)}</td><td class="num">${rAmt(D.total_kredit)}</td><td class="num">${rAmt(D.saldo_akhir)}</td></tr></tbody></table></div>
        ${!S.rows.length ? `<div class="empty">Tidak ada mutasi pada rentang ini.</div>` : ""}
        <div class="ft"><span>${S.rows.length} baris mutasi${D.next_cursor ? "" : " (semua)"} · saldo sesuai saldo normal akun (${D.account.saldo_normal === "debit" ? "debit" : "kredit"})</span>${D.next_cursor ? `<a class="lk noprint" id="more">${S.loading ? "Memuat…" : "Muat lainnya"}</a>` : "<span></span>"}</div></div>`;
      const mo = $("#more"); if (mo) mo.onclick = () => { if (!S.loading) load(true); };
    }

    function csv() {
      if (!S.D || S.err) return null;
      const D = S.D;
      return [["Tanggal", "Nomor", "Tipe", "Memo", "Debit", "Kredit", "Saldo"], ["", "", "", "Saldo awal", "", "", rInt(D.saldo_awal)]]
        .concat(S.rows.map(r => [r.tanggal, r.nomor || "", RTIPE[r.tipe] || r.tipe || "", r.memo || "", rInt(r.debit), rInt(r.kredit), rInt(r.saldo)]),
          [["", "", "", "Total mutasi", rInt(D.total_debit), rInt(D.total_kredit), rInt(D.saldo_akhir)]]);
    }

    /* ---------- mulai ---------- */
    pg.innerHTML = head(f, m) + `<div class="card"><div class="empty">Memuat…</div></div>`;
    const r = await invRef("/accounts");
    if (!alive()) return;
    S.A = { ok: r.ok, list: r.list.filter(a => !a.is_header) };
    shell();
    if (S.acc) load();
  };
}
SCREENS["histori_akun"] = histScreen();

/* =====================================================================================
   Daftar Laporan: daftar di kiri, isi laporan di kanan (seperti prototipe).
   Hanya laporan yang punya layar dan boleh dilihat peran ini yang tampil. Izin tiap laporan:
   laporan_keuangan (Neraca, Laba Rugi, Neraca Saldo, Buku Besar), umur_piutang, umur_utang;
   Histori Akun membuka fitur histori_akun.
   ===================================================================================== */
const RREPORTS = [
  { key: "neraca", slug: "laporan_keuangan", grp: "Laporan keuangan", t: "Neraca", mount: (c) => finView("neraca", c) },
  { key: "labarugi", slug: "laporan_keuangan", grp: "Laporan keuangan", t: "Laba Rugi", mount: (c) => finView("labarugi", c) },
  { key: "neracasaldo", slug: "laporan_keuangan", grp: "Laporan keuangan", t: "Neraca Saldo", mount: (c) => finView("neracasaldo", c) },
  { key: "bukubesar", slug: "laporan_keuangan", grp: "Laporan keuangan", t: "Buku Besar", mount: (c) => finView("bukubesar", c) },
  { key: "umur_piutang", slug: "umur_piutang", grp: "Umur", t: "Umur Piutang", mount: (c) => agingView(AGING.umur_piutang, c) },
  { key: "umur_utang", slug: "umur_utang", grp: "Umur", t: "Umur Utang", mount: (c) => agingView(AGING.umur_utang, c) },
  { key: "kartu_stok", slug: "barang_per_gudang", grp: "Persediaan", t: "Kartu Stok", mount: (c) => kartuStokView(c) },
  { key: "barang_per_gudang", slug: "barang_per_gudang", grp: "Persediaan", t: "Barang per Gudang ↗", link: true },
  { key: "histori_akun", slug: "histori_akun", grp: "Rincian", t: "Histori Akun ↗", link: true },
];

SCREENS["daftar_laporan"] = function (f, m, j) {
  const items = RREPORTS.filter(x => can(x.slug) && (x.link ? findFeature(x.slug) && SCREENS[x.slug] : true));
  const grps = [...new Set(items.map(x => x.grp))];
  const sel = items.find(x => x.key === RSEL && !x.link) || items.find(x => !x.link) || null;
  pg.innerHTML = head(f, m) + (items.length ? `<div class="rlay"><div class="card rnav noprint">${grps.map(g => `<h4>${esc(g)}</h4>${items.filter(x => x.grp === g).map(x => `<button data-r="${x.key}">${esc(x.t)}</button>`).join("")}`).join("")}</div><div id="rh"></div></div>`
    : `<div class="card"><div class="empty">Belum ada laporan yang bisa dibuka oleh peran ${esc(APP.company.role)}.</div></div>`)
    + `<div class="warn i noprint">ⓘ Laporan lain di prototipe (Arus Kas, Penjualan, Pembelian, SPT PPN) belum punya layar di langkah ini.</div>`;
  if (!items.length) return;

  function open(x) {
    if (x.link) { const hit = findFeature(x.slug); return show(hit[0], hit[1]); }
    RSEL = x.key;
    const host = $("#rh"), tok = ++RTOK.n;
    pg.querySelectorAll("[data-r]").forEach(b => b.classList.toggle("on", b.dataset.r === x.key));
    x.mount({ host, slug: x.slug, alive: () => tok === RTOK.n && host.isConnected && cur && cur[0] === m && cur[1] === j });
  }
  pg.querySelectorAll("[data-r]").forEach(b => b.onclick = () => open(items.find(x => x.key === b.dataset.r)));
  if (sel) open(sel);
};
