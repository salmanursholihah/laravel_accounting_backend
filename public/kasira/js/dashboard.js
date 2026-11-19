/* Beranda: GET /api/v1/dashboard. Widget yang datang ditentukan IZIN user di backend (bukan nama role),
   jadi UI hanya menggambar widget yang ada di respons. Uang berupa string 4 desimal. */
const DASH = { run: 0, data: null, aging: "piutang" };

const num = x => Number(x) || 0;
const rps = n => (n < 0 ? "-" : "") + rp(Math.abs(n));
const rpc = n => { const a = Math.abs(n), s = n < 0 ? "-" : ""; return a >= 1e9 ? s + "Rp " + (a / 1e9).toFixed(1).replace(".", ",") + " M" : a >= 1e6 ? s + "Rp " + (a / 1e6).toFixed(1).replace(".", ",") + " jt" : rps(n); };
const BLN = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];
const BUCKET = [["belum_jatuh_tempo", "Belum jatuh tempo", "var(--t)"], ["d1_30", "1–30 hari", "var(--ac)"], ["d31_60", "31–60 hari", "var(--k)"], ["d61_90", "61–90 hari", "var(--bad)"], ["d_lebih_90", "Lebih dari 90 hari", "var(--l)"]];

function renderDashboard() {
  const run = ++DASH.run;
  pg.innerHTML = `<div id="dash"><div class="hd"><div><h1>Beranda</h1><div class="crumb">Memuat ringkasan…</div></div></div></div>`;
  API.get("/dashboard").then(r => {
    if (run != DASH.run || cur !== 0) return;            // pengguna sudah pindah layar atau ganti perusahaan
    DASH.data = r.data; paintDashboard();
  }).catch(err => {
    if (run != DASH.run || cur !== 0 || err.status == 401) return;
    pg.innerHTML = `<div id="dash"><div class="hd"><div><h1>Beranda</h1></div></div><div class="card"><span class="bd er">Gagal memuat</span><p style="margin:10px 0">${esc(err.first ? err.first() : "Tidak dapat menghubungi server.")}</p><button class="btn" id="dr">Coba lagi</button></div></div>`;
    $("#dr").onclick = renderDashboard;
  });
}

function dashAttention(w) {
  const a = [], add = (n, t, tag, slug) => a.push({ t, tag, slug, c: a.length % 2 ? "var(--ac)" : "var(--bad)" });
  const fb = w.faktur_belum_dibayar, td = w.tagihan_utang, sm = w.stok_menipis, pp = w.periode_belum_ditutup, dr = w.draft_tersisa, mp = w.menunggu_persetujuan;
  if (fb && fb.jumlah_lewat_jatuh_tempo > 0) add(0, `${fb.jumlah_lewat_jatuh_tempo} faktur penjualan lewat jatuh tempo`, "Piutang", "faktur_penjualan");
  if (td && td.lewat_jatuh_tempo.jumlah_faktur > 0) add(0, `${td.lewat_jatuh_tempo.jumlah_faktur} tagihan pemasok lewat jatuh tempo (${rpc(num(td.lewat_jatuh_tempo.sisa))})`, "Utang", "faktur_pembelian");
  if (td && td.jatuh_tempo_7_hari.jumlah_faktur > 0) add(0, `${td.jatuh_tempo_7_hari.jumlah_faktur} tagihan jatuh tempo dalam 7 hari (${rpc(num(td.jatuh_tempo_7_hari.sisa))})`, "Utang", "faktur_pembelian");
  if (sm && sm.jumlah > 0) add(0, `${sm.jumlah} barang di bawah stok minimum`, "Persediaan", "barang_stok_minimum");
  if (mp && mp.jumlah > 0) add(0, `${mp.jumlah >= mp.batas ? mp.batas + "+" : mp.jumlah} menunggu persetujuan`, "Kotak persetujuan", null);
  if (pp && pp.jumlah > 0) add(0, `${pp.jumlah} periode sudah lewat tetapi belum ditutup`, "Akhir bulan", "proses_akhir_bulan");
  if (dr && dr.jumlah > 0) add(0, `${dr.jumlah} dokumen draft belum diposting`, "Draft", null);
  return a;
}

function paintDashboard() {
  const d = DASH.data, w = d.widgets || {}, tgl = new Date(d.tanggal + "T00:00:00");
  const K = [];
  if (w.kas_bank) K.push(["Kas dan bank", rpc(num(w.kas_bank.saldo)), w.kas_bank.jumlah_rekening + " rekening", "var(--pri)"]);
  if (w.piutang) K.push(["Piutang", rpc(num(w.piutang.total)), w.piutang.jumlah_faktur + " faktur terbuka", "var(--ac)"]);
  if (w.utang) K.push(["Utang", rpc(num(w.utang.total)), w.utang.jumlah_faktur + " faktur terbuka", "var(--m)"]);
  if ("laba_bulan_ini" in w) K.push(["Laba bulan ini", w.laba_bulan_ini ? rpc(num(w.laba_bulan_ini.laba_bersih)) : "-", w.laba_bulan_ini ? "Bulan berjalan" : "Periode belum tersedia", "var(--t)"]);
  if (w.penjualan_hari_ini) K.push(["Penjualan hari ini", rpc(num(w.penjualan_hari_ini.total)), w.penjualan_hari_ini.jumlah_faktur + " faktur", "var(--pri)"]);
  if (w.faktur_belum_dibayar) K.push(["Faktur belum dibayar", String(w.faktur_belum_dibayar.jumlah_faktur), rpc(num(w.faktur_belum_dibayar.sisa)), "var(--ac)"]);
  if (w.stok_menipis) K.push(["Stok menipis", String(w.stok_menipis.jumlah), "Di bawah minimum", "var(--bad)"]);

  const att = dashAttention(w);
  const cards = [];
  if (w.penjualan_vs_pembelian) {
    const M = w.penjualan_vs_pembelian, S = M.map(x => num(x.penjualan)), P = M.map(x => num(x.pembelian)), mx = Math.max(1, ...S, ...P);
    const empty = !S.some(Boolean) && !P.some(Boolean);
    cards.push(`<section class="card c7"><h3>Penjualan dan pembelian, 12 bulan terakhir</h3><div class="leg"><span><i style="background:var(--pri)"></i>Penjualan</span> <span><i style="background:var(--ac)"></i>Pembelian</span></div>
      ${empty ? '<div class="empty">Belum ada faktur yang diposting</div>' : `<svg viewBox="0 0 560 190" width="100%" role="img" aria-label="Grafik batang penjualan dan pembelian">${[0, 1, 2, 3].map(i => `<line x1="30" x2="560" y1="${20 + i * 45}" y2="${20 + i * 45}" stroke="var(--ln)"/>`).join("")}${M.map((x, i) => { const px = 36 + i * 44, hs = S[i] / mx * 120, hp = P[i] / mx * 120, m = BLN[+x.bulan.slice(5, 7) - 1]; return `<rect x="${px}" y="${155 - hs}" width="16" height="${hs}" rx="3" fill="var(--pri)"><title>${m} penjualan ${esc(rps(S[i]))}</title></rect><rect x="${px + 18}" y="${155 - hp}" width="16" height="${hp}" rx="3" fill="var(--ac)"><title>${m} pembelian ${esc(rps(P[i]))}</title></rect><text x="${px + 17}" y="176" font-size="10.5" text-anchor="middle" fill="var(--mut)">${m}</text>`; }).join("")}</svg>`}</section>`);
  }
  cards.push(`<section class="card c5"><h3>Perlu perhatian</h3><ul class="att">${att.map((x, i) => `<li style="--c:${x.c};cursor:pointer" data-a="${i}">${esc(x.t)}<b>${esc(x.tag)}</b></li>`).join("") || '<li style="--c:var(--ok)">Tidak ada yang perlu perhatian hari ini<b></b></li>'}</ul></section>`);

  const sides = ["piutang", "utang"].filter(s => w[s]);
  if (sides.length) {
    if (!w[DASH.aging]) DASH.aging = sides[0];
    const A = w[DASH.aging], tot = BUCKET.reduce((s, b) => s + num(A.buckets[b[0]]), 0);
    cards.push(`<section class="card c4"><h3>Umur ${DASH.aging}</h3>${sides.length > 1 ? `<div class="roles" role="group" aria-label="Pilih piutang atau utang" style="margin-bottom:8px">${sides.map(s => `<button aria-pressed="${s == DASH.aging}" data-s="${s}">${s[0].toUpperCase() + s.slice(1)}</button>`).join("")}</div>` : ""}
      ${tot ? `<div class="stack">${BUCKET.map(b => `<div style="flex:${num(A.buckets[b[0]])};background:${b[2]}"></div>`).join("")}</div>` : '<div class="empty">Tidak ada saldo terbuka</div>'}
      <div class="rows">${BUCKET.map(b => `<div><span><i style="background:${b[2]}"></i>${b[1]}</span><b>${rpc(num(A.buckets[b[0]]))}</b></div>`).join("")}</div></section>`);
  }
  if (w.stok_menipis) {
    const T = w.stok_menipis.teratas;
    cards.push(`<section class="card c8"><h3>Stok menipis</h3>${T.length ? `<ul class="top">${T.map((t, i) => { const q = num(t.qty_saldo), mn = Math.max(num(t.stok_minimum), 1); return `<li><b>${i + 1}</b><span>${esc(t.nama)} <small style="color:var(--mut)">${esc(t.gudang)}</small></span><span>${q} / min ${num(t.stok_minimum)}</span><div class="bar2"><i style="width:${Math.max(0, Math.min(100, q / mn * 100))}%;background:var(--bad)"></i></div></li>`; }).join("")}</ul>` : '<div class="empty">Semua barang di atas stok minimum</div>'}</section>`);
  }

  pg.innerHTML = `<div id="dash"><div class="hd"><div><h1>Ringkasan ${tgl.toLocaleDateString("id-ID", { month: "long", year: "numeric" })}</h1><div class="crumb">${esc(APP.company.nama)} · ${tgl.toLocaleDateString("id-ID", { weekday: "long", day: "numeric", month: "long", year: "numeric" })}</div></div><button class="btn" id="dr">Muat ulang</button></div>
    ${K.length ? `<div class="kpis">${K.map(x => `<div class="card kpi" style="--c:${x[3]}"><small>${x[0]}</small><div>${esc(x[1])}</div><span style="color:var(--mut)">${esc(x[2])}</span></div>`).join("")}</div>` : ""}
    <div class="grid">${cards.join("")}</div></div>`;

  $("#dr").onclick = renderDashboard;
  pg.querySelectorAll("[data-a]").forEach(li => li.onclick = () => { const a = att[+li.dataset.a], hit = a.slug && can(a.slug) && findFeature(a.slug); hit ? show(hit[0], hit[1]) : toast(a.slug ? "Anda tidak punya akses ke fitur ini" : a.tag + " disambungkan pada langkah berikutnya"); });
  pg.querySelectorAll("[data-s]").forEach(b => b.onclick = () => { DASH.aging = b.dataset.s; paintDashboard(); });

  const mp = w.menunggu_persetujuan, bn = $("#bl .n");                // lonceng: jumlah menunggu persetujuan
  if (bn) { bn.textContent = mp && mp.jumlah ? (mp.jumlah >= mp.batas ? mp.batas + "+" : mp.jumlah) : ""; bn.style.display = mp && mp.jumlah ? "" : "none"; }
}
