/* Langkah 4g: Proses Akhir Bulan (pola W, wizard) dan Tutup Tahun Buku.
   Empat langkah mengikuti prototipe: Periksa, Proses otomatis, Tinjau, Kunci periode.
   Endpoint (semua membalas { data }): GET /fiscal-years, GET /periods/{id}/close-check, GET /periods/{id}/close-logs,
   POST /periods/{id}/close, GET /fiscal-years/{id}/close-preview, POST /fiscal-years/{id}/close,
   POST /periods/{id}/reopen, POST /fiscal-years/{id}/reopen.
   Proses otomatis: GET /stock/recalc/pending + POST /stock/recalc (satu barang+gudang per panggilan),
   GET /periods/{id}/depreciation-preview + POST /periods/{id}/depreciation.
   Hak: lihat = proses_akhir_bulan:view; tutup dan penyusutan = hak khusus tutup_periode; hitung ulang HPP = hak khusus jurnal_koreksi;
   buka kunci = hak khusus buka_kunci_periode. Hak khusus disimpan sebagai fitur "khusus" (can("khusus", "<aksi>")).
   Periode ke-12 hanya bisa ditutup lewat Tutup Tahun Buku (jurnal penutup); layar otomatis beralih ke alur itu.
   Dimuat setelah report.js (memakai RTOK, rPeriods-style pemuatan sendiri, rPer, rTgl, rAmt, rNum, invToday). */

const CLS_ST = { ok: ["ok", "✓", "Lolos"], warning: ["wn", "!", "Peringatan"], failed: ["ng", "✕", "Gagal"], na: ["gr", "–", "Belum tersedia"] };
const CLS_LOG = { closed: "Ditutup", warning: "Ditolak (butuh konfirmasi)", failed: "Ditolak (pemeriksaan gagal)" };
const clsSpecial = a => can("khusus", a);
const clsMsg = e => (e && e.first ? e.first() : (e && e.message) || "Terjadi kesalahan.");

/* Rincian item checklist: hanya kunci yang dikenal dari PeriodCloseService; sisanya diabaikan. */
function clsDetail(it) {
  const d = it.detail || {};
  if (it.kode === "draft" && Array.isArray(d.per_jenis)) return d.per_jenis.map(x => `${esc(x.label)}: ${esc(x.jumlah)}`).join(", ");
  if (it.kode === "urutan" && Array.isArray(d.periode_terbuka)) return "Masih terbuka: " + d.periode_terbuka.map(esc).join(", ");
  if (it.kode === "rekonsiliasi_bank" && Array.isArray(d.rekening_koran)) return d.rekening_koran.map(x => `${esc(x.nama_bank)} (${esc(x.periode)})`).join("; ");
  if (it.kode === "saldo_cocok" && d.jumlah) return `${esc(d.jumlah)} kombinasi akun-periode tidak cocok`;
  return "";
}
const clsItems = items => (items || []).map(it => {
  const s = CLS_ST[it.status] || CLS_ST.na, dt = clsDetail(it);
  return `<div class="chk" data-k="${esc(it.kode)}"><b class="${s[0]}" title="${s[2]}">${s[1]}</b><span><strong>${esc(it.label)}</strong> <small class="mut">${s[2]}</small><br>${esc(it.pesan)}${dt ? `<br><small class="mut">${dt}</small>` : ""}</span></div>`;
}).join("");

SCREENS["proses_akhir_bulan"] = function (f, m, j) {
  const tok = ++RTOK.n, alive = () => tok === RTOK.n && cur && cur[0] === m && cur[1] === j;
  const STEPS = (f.j || "Periksa/Proses otomatis/Tinjau/Kunci periode").split("/");
  /* S.res = hasil terakhir (check atau preview tahun); S.seq menolak balasan lama; S.busy mencegah klik ganda */
  const S = { years: null, list: [], pid: null, step: 0, res: null, logs: [], err: null, loading: false, busy: false, seq: 0, konf: false, auto: null, done: null };
  SCREENS.proses_akhir_bulan.last = S;   // dipakai pengujian

  const cur_ = () => S.list.find(p => String(p.id) === String(S.pid)) || null;
  const isYearEnd = p => !!p && p.bulan === 12;
  const fyOf = p => S.years.find(y => y.id === p.fy) || null;
  const canClose = () => clsSpecial("tutup_periode");

  async function loadYears() {
    const r = await API.get("/fiscal-years");
    S.years = r.data || [];
    S.list = [];
    S.years.forEach(y => (y.periode || []).forEach(p => S.list.push({ id: p.id, bulan: p.bulan, mulai: p.mulai, selesai: p.selesai, status: p.status, tahun: y.tahun, fy: y.id, fyStatus: y.status })));
    S.list.sort((a, b) => a.mulai < b.mulai ? -1 : a.mulai > b.mulai ? 1 : 0);
  }
  /* Periode default = yang terbuka paling awal (penutupan harus berurutan) */
  const defaultPid = () => { const o = S.list.find(p => p.status === "open"); return (o || S.list[S.list.length - 1] || {}).id || null; };

  async function runCheck() {
    const p = cur_(); if (!p) return;
    const seq = ++S.seq; S.loading = true; S.err = null; S.res = null; draw();
    try {
      const path = isYearEnd(p) ? `/fiscal-years/${p.fy}/close-preview` : `/periods/${p.id}/close-check`;
      const [r, lg] = await Promise.all([API.get(path), API.get(`/periods/${p.id}/close-logs`).catch(() => ({ data: [] }))]);
      if (!alive() || seq !== S.seq) return;
      S.res = r.data; S.logs = lg.data || [];
    } catch (e) { if (!alive() || seq !== S.seq) return; S.err = clsMsg(e); }
    S.loading = false; draw();
  }

  /* ---------- tampilan ---------- */
  function stepsBar() {
    const last = isYearEnd(cur_()) ? STEPS.slice(0, -1).concat(["Tutup tahun buku"]) : STEPS;
    return `<div class="steps">${last.map((x, i) => `<div class="${i === S.step ? "on" : ""}">${i + 1}. ${esc(x)}</div>`).join("")}</div>`;
  }
  const overall = r => !r ? "" : r.status === "passed"
    ? `<div class="warn i">ⓘ Semua pemeriksaan lolos. Periode siap dikunci.</div>`
    : r.status === "warning" ? `<div class="warn w">! Ada peringatan. Periode bisa dikunci setelah Anda mengonfirmasi.</div>`
      : `<div class="warn e">✕ Ada pemeriksaan yang gagal. Perbaiki dulu; penutupan diblokir.</div>`;

  function logsHtml() {
    if (!S.logs.length) return "";
    return `<h3 style="margin-top:14px">Riwayat penutupan</h3><div class="tw"><table><thead><tr><th>Waktu</th><th>Hasil</th><th>Oleh</th></tr></thead><tbody>${S.logs.map(l =>
      `<tr><td>${esc(l.waktu)}</td><td>${esc(CLS_LOG[l.status] || l.status)}</td><td>${esc(l.dijalankan_oleh || "-")}</td></tr>`).join("")}</tbody></table></div>`;
  }

  function body() {
    const p = cur_();
    if (!p) return `<div class="card"><div class="empty">Belum ada tahun buku.</div></div>`;
    if (S.done) return doneHtml();
    if (S.err) return `<div class="card"><div class="warn e">${esc(S.err)} <a class="lk" id="rt">Coba lagi</a></div></div>`;
    if (p.status === "closed") return closedHtml(p);
    if (S.loading || !S.res) return `<div class="card"><div class="empty">Memeriksa periode…</div></div>`;
    const r = S.res, yr = isYearEnd(p);
    if (S.step === 0) return `<div class="card">${clsItems(r.items)}${overall(r)}${logsHtml()}</div>`;
    if (S.step === 1) return `<div class="card">${autoHtml(r)}</div>`;
    if (S.step === 2) return `<div class="card">${clsItems(r.items)}${overall(r)}${yr && r.penutup ? yearSummary(r) : ""}${r.butuh_konfirmasi
      ? `<label class="ck" style="display:block;margin-top:10px"><input id="kf" type="checkbox" ${S.konf ? "checked" : ""}> Saya sudah membaca peringatan di atas dan tetap ingin ${yr ? "menutup tahun buku" : "mengunci periode"}.</label>` : ""}</div>`;
    return `<div class="card"><div class="warn w">! Langkah terakhir bersifat final dan dicatat di Log Aktivitas.${yr ? " Jurnal penutup diterbitkan dan tahun buku berikutnya dibuat bila belum ada." : ""}</div>
      <p style="margin:12px 0 0">${yr ? `Tahun buku <b>${esc(p.tahun)}</b>` : `Periode <b>${esc(rPer(p))}</b>`} akan ${yr ? "ditutup" : "dikunci"}.${canClose() ? "" : " Anda tidak punya hak untuk menutup periode."}</p></div>`;
  }

  const yearSummary = r => `<h3 style="margin-top:14px">Jurnal penutup tahun ${esc(r.tahun)}</h3>
    <p style="margin:6px 0 0">${esc(r.penutup.jumlah_akun)} akun laba rugi ditutup dalam ${esc(r.penutup.jumlah_baris_jurnal)} baris jurnal. ${rNum(r.penutup.laba_bersih) >= 0 ? "Laba" : "Rugi"} bersih tahun berjalan: <b>Rp ${rAmt(Math.abs(rNum(r.penutup.laba_bersih)))}</b>, dipindahkan ke Laba Ditahan.</p>`;

  function closedHtml(p) {
    const yr = isYearEnd(p), reopenable = clsSpecial("buka_kunci_periode");
    return `<div class="card"><div class="warn i">ⓘ ${yr ? `Tahun buku ${esc(p.tahun)}` : `Periode ${esc(rPer(p))}`} sudah ditutup.</div>${logsHtml()}
      ${reopenable ? `<p style="margin:12px 0 0"><button class="btn" id="ro">Buka kunci…</button></p>` : `<p class="mut" style="margin:12px 0 0">Hanya pemegang hak Buka Kunci Periode (Owner) yang dapat membuka kembali.</p>`}</div>`;
  }

  function doneHtml() {
    const d = S.done, p = cur_();
    return `<div class="card"><div class="warn i" id="ok">✓ ${esc(d.pesan)}</div>${d.jurnal ? `<p style="margin:10px 0 0">Jurnal penutup <b>${esc(d.jurnal.nomor)}</b>, ${rNum(d.jurnal.laba_bersih) >= 0 ? "laba" : "rugi"} bersih Rp ${rAmt(Math.abs(rNum(d.jurnal.laba_bersih)))}.${d.tahunBaru ? " Tahun buku berikutnya dibuat otomatis." : ""}</p>` : ""}
      <p style="margin:12px 0 0"><button class="btn p" id="nxp">Proses periode berikutnya</button></p></div>`;
  }

  /* ---------- langkah 2: proses otomatis ---------- */
  function autoHtml(r) {
    const by = k => (r.items || []).find(x => x.kode === k) || { status: "ok" };
    const hpp = by("hpp_tertunda"), dep = by("penyusutan_aset"), A = S.auto || {};
    const other = (r.items || []).filter(x => x.status === "failed" && x.kode !== "hpp_tertunda" && x.kode !== "penyusutan_aset");
    let h = "";
    /* Hasil proses tetap tampil walau item sudah lolos setelah diperiksa ulang */
    if (hpp.status === "failed" || A.rc) {
      h += `<h3>Hitung ulang HPP</h3>`;
      if (hpp.status === "failed") {
        h += `<p style="margin:4px 0 8px">${esc(hpp.pesan)}</p>`;
        if (!clsSpecial("jurnal_koreksi")) h += `<div class="warn i">ⓘ Menjalankan hitung ulang HPP butuh hak khusus Jurnal Koreksi (Owner, Akuntan).</div>`;
        else h += `<button class="btn p" id="rc" ${S.busy ? "disabled" : ""}>Hitung ulang semua</button>`;
      }
      if (A.rc) h += `<div class="warn ${A.rc.gagal.length ? "w" : "i"}" id="rcr">${esc(A.rc.selesai)} barang selesai dihitung ulang${A.rc.gagal.length ? `, ${esc(A.rc.gagal.length)} gagal: ${A.rc.gagal.map(x => esc(x)).join(", ")}` : ""}.</div>`;
    }
    if (dep.status === "failed" || A.dep) {
      h += `<h3 style="margin-top:14px">Penyusutan aset tetap</h3>`;
      if (dep.status === "failed") {
        h += `<p style="margin:4px 0 8px">${esc(dep.pesan)}</p>`;
        if (A.depPrev) h += `<p style="margin:0 0 8px">Akan disusutkan: ${esc(A.depPrev.jumlah_aset)} aset, total Rp ${rAmt(rNum(A.depPrev.total))}.</p>`;
        else if (A.depPrevErr) h += `<div class="warn i">ⓘ ${esc(A.depPrevErr)}</div>`;
        if (!canClose()) h += `<div class="warn i">ⓘ Menjalankan penyusutan butuh hak khusus Tutup Periode (Owner, Akuntan).</div>`;
        else h += `<button class="btn p" id="dp" ${S.busy ? "disabled" : ""}>Jalankan penyusutan periode ini</button>`;
      }
      if (A.dep) h += `<div class="warn i" id="dpr">✓ Penyusutan dijalankan: ${esc(A.dep.jumlah_aset)} aset, total Rp ${rAmt(rNum(A.dep.total))}${(A.dep.jurnal || []).length ? ", jurnal " + A.dep.jurnal.map(x => esc(x.nomor)).join(", ") : ""}.</div>`;
    }
    if (hpp.status !== "failed" && dep.status !== "failed") h += `<div class="empty">Tidak ada proses otomatis yang perlu dijalankan.</div>`;
    if (other.length) h += `<div class="warn e" style="margin-top:12px">Pemeriksaan berikut tidak bisa diperbaiki otomatis dan harus ditangani manual: ${other.map(x => esc(x.label)).join(", ")}.</div>`;
    return h;
  }

  async function prepAuto() {
    const p = cur_(); if (!p || !S.res) return;
    const dep = (S.res.items || []).find(x => x.kode === "penyusutan_aset");
    if (dep && dep.status === "failed" && !(S.auto && S.auto.depPrev) && can("aset_tetap")) {
      const seq = S.seq;
      try { const r = await API.get(`/periods/${p.id}/depreciation-preview`); if (!alive() || seq !== S.seq) return; S.auto = Object.assign(S.auto || {}, { depPrev: r.data }); }
      catch (e) { if (!alive() || seq !== S.seq) return; S.auto = Object.assign(S.auto || {}, { depPrevErr: clsMsg(e) }); }
      draw();
    }
  }

  async function doRecalc() {
    if (S.busy) return; S.busy = true; draw();
    const seq = S.seq; let selesai = 0; const gagal = [];
    try {
      const pend = (await API.get("/stock/recalc/pending")).data || [];
      for (const x of pend) {
        try { await API.post("/stock/recalc", { item_id: x.item_id, warehouse_id: x.warehouse_id }); selesai++; }
        catch (e) { gagal.push(`${x.item_kode}/${x.gudang_kode}`); }
        if (!alive()) return;
      }
    } catch (e) { gagal.push(clsMsg(e)); }
    if (!alive()) return;
    S.busy = false; S.auto = Object.assign(S.auto || {}, { rc: { selesai, gagal } });
    if (seq === S.seq) await refreshKeep();
  }

  async function doDep() {
    const p = cur_(); if (S.busy || !p) return; S.busy = true; draw();
    const seq = S.seq;
    try {
      const r = await API.post(`/periods/${p.id}/depreciation`);
      if (!alive()) return;
      S.auto = Object.assign(S.auto || {}, { dep: r.data });
    } catch (e) { if (!alive()) return; toast(clsMsg(e)); }
    S.busy = false;
    if (seq === S.seq) await refreshKeep();
  }

  /* periksa ulang tanpa meninggalkan langkah dan hasil proses otomatis */
  async function refreshKeep() {
    const p = cur_(), seq = ++S.seq;
    try {
      const path = isYearEnd(p) ? `/fiscal-years/${p.fy}/close-preview` : `/periods/${p.id}/close-check`;
      const r = await API.get(path);
      if (!alive() || seq !== S.seq) return;
      S.res = r.data;
    } catch (e) { if (!alive() || seq !== S.seq) return; toast(clsMsg(e)); }
    draw();
  }

  /* ---------- langkah 4: kunci ---------- */
  async function doClose() {
    const p = cur_(); if (S.busy || !p || !S.res) return;
    S.busy = true; draw();
    const yr = isYearEnd(p);
    try {
      const r = await API.post(yr ? `/fiscal-years/${p.fy}/close` : `/periods/${p.id}/close`, { konfirmasi: !!S.konf });
      if (!alive()) return;
      const d = r.data || {};
      S.done = yr ? { pesan: `Tahun buku ${p.tahun} ditutup.`, jurnal: d.jurnal_penutup, tahunBaru: d.tahun_berikutnya_dibuat }
        : { pesan: `Periode ${rPer(p)} dikunci.` };
      p.status = "closed"; if (yr) p.fyStatus = "closed";
      toast(S.done.pesan);
    } catch (e) {
      if (!alive()) return;
      /* 409 butuh konfirmasi atau 422 diblokir: server mengirim checklist terbaru; tampilkan di langkah Tinjau */
      if (e.body && e.body.hasil) { S.res = Object.assign({}, S.res, e.body.hasil); S.step = 2; S.konf = false; }
      toast(clsMsg(e));
    }
    S.busy = false; draw();
  }

  function reopenDlg() {
    const p = cur_(), yr = isYearEnd(p);
    dlg(`<h3>Buka kunci ${yr ? "tahun buku " + esc(p.tahun) : "periode " + esc(rPer(p))}?</h3><p style="color:var(--mut);margin:0 0 8px">${yr ? "Jurnal penutup dibalik dan periode ke-12 dibuka; periode 1 sampai 11 tetap terkunci." : "Periode dibuka lagi untuk posting."} Alasan wajib diisi dan dicatat.</p>
      <input id="rs" maxlength="400" placeholder="Alasan buka kunci" style="width:100%"><div id="de"></div>
      <div style="text-align:right;margin-top:10px"><button class="btn" id="x">Batal</button> <button class="btn d" id="okr" disabled>Buka kunci</button></div>`);
    $("#x").onclick = cl;
    $("#rs").oninput = e => { $("#okr").disabled = !e.target.value.trim(); };
    $("#okr").onclick = async () => {
      const b = $("#okr"); if (b.disabled) return; b.disabled = true;
      try {
        await API.post(yr ? `/fiscal-years/${p.fy}/reopen` : `/periods/${p.id}/reopen`, { alasan: $("#rs").value.trim() });
        cl(); toast("Periode dibuka kembali");
        await loadYears(); if (!alive()) return;
        S.pid = p.id; S.step = 0; S.done = null; S.konf = false; S.auto = null; await runCheck();
      } catch (e) { $("#de").innerHTML = `<div class="warn e">${esc(clsMsg(e))}</div>`; b.disabled = false; }
    };
  }

  /* ---------- gambar ---------- */
  function draw() {
    if (!alive()) return;
    const p = cur_(), open = p && p.status === "open" && !S.done;
    const last = STEPS.length - 1;
    const nextOk = S.res && !S.loading && !S.busy && (S.step < 2 || (S.step === 2 && S.res.bisa_ditutup && (!S.res.butuh_konfirmasi || S.konf)));
    pg.innerHTML = head(f, m) + `<div class="card noprint"><div class="fl rf"><label style="min-width:240px"><span>Periode</span><select id="pp" ${S.busy ? "disabled" : ""}>${S.list.map(x =>
      `<option value="${x.id}" ${String(x.id) === String(S.pid) ? "selected" : ""}>${esc(rPer(x))}${x.status === "closed" ? " (ditutup)" : ""}</option>`).join("")}</select></label>
      ${p ? `<span class="bd ${p.status === "closed" ? "gr" : "ok"}">${p.status === "closed" ? "Ditutup" : "Terbuka"}</span>` : ""}</div></div>`
      + (open ? stepsBar() : "") + `<div id="cb">${body()}</div>`
      + (open && S.res && !S.err ? `<p class="noprint"><button class="btn" id="bk" ${S.step && !S.busy ? "" : "disabled"}>Kembali</button> ${S.step === last
        ? `<button class="btn p" id="kc" ${canClose() && !S.busy && S.res.bisa_ditutup && (!S.res.butuh_konfirmasi || S.konf) ? "" : "disabled"}>${isYearEnd(p) ? "Tutup tahun buku" : "Kunci periode"}</button>`
        : `<button class="btn p" id="nx" ${nextOk ? "" : "disabled"}>Lanjut</button>`}</p>` : "");
    $("#pp").onchange = e => { S.pid = e.target.value; S.step = 0; S.res = null; S.konf = false; S.auto = null; S.done = null; runCheck(); };
    const on = (id, fn) => { const el = $(id); if (el) el.onclick = fn; };
    on("#rt", () => (S.years ? runCheck() : start()));
    on("#bk", () => { S.step--; draw(); });
    on("#nx", async () => {
      if (S.step === 0) { S.step = 1; draw(); prepAuto(); }
      else if (S.step === 1) { S.step = 2; S.konf = false; await runCheck(); }   // Tinjau selalu memeriksa ulang
      else if (S.step === 2) { S.step = 3; draw(); }
    });
    on("#kc", doClose); on("#rc", doRecalc); on("#dp", doDep); on("#ro", reopenDlg);
    on("#nxp", () => { S.done = null; S.step = 0; S.konf = false; S.auto = null; S.pid = defaultPid(); runCheck(); });
    const kf = $("#kf"); if (kf) kf.onchange = e => { S.konf = e.target.checked; draw(); };
  }

  async function start() {
    pg.innerHTML = head(f, m) + `<div class="card"><div class="empty">Memuat…</div></div>`;
    try { await loadYears(); } catch (e) { if (!alive()) return; S.err = clsMsg(e); pg.innerHTML = head(f, m) + `<div class="card"><div class="warn e">${esc(S.err)} <a class="lk" id="rt">Coba lagi</a></div></div>`; $("#rt").onclick = start; return; }
    if (!alive()) return;
    S.pid = defaultPid(); S.err = null;
    if (!S.list.length) { draw(); return; }
    runCheck();
  }
  start();
};
