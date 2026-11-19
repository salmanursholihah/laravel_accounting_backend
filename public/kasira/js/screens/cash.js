/* Langkah 4c: dokumen Kas & Bank. Satu mesin, empat konfigurasi:
   Penerimaan (cash-receipts), Pembayaran (cash-payments), Pencatatan Beban (expenses), Transfer Bank (bank-transfers).
   Tiga yang pertama bergaya header + baris akun (kind "line"); Transfer Bank hanya header (kind "transfer").
   Nominal baris adalah harga SEBELUM pajak; pajak dan total resmi dihitung server saat disimpan.
   Dimuat setelah invoice.js dan payment.js (memakai STATUS, invRef, invToday, u4, f4, payInt, payBadge). */

const CASH_TOK = { n: 0 };

function cashBlank(cfg) {
  const F = { id: null, version: null, status: "draft", nomor: null, tanggal: invToday(), contact_id: "", rekening_id: "", memo: "", alasan_void: null, total: null };
  if (cfg.kind === "transfer") Object.assign(F, { rekening_asal_id: "", rekening_tujuan_id: "", nominal: "", biaya_admin: "" });
  else { F.lines = [cashLine()]; (cfg.extra || []).forEach(h => { F[h.key] = h.def; }); }
  return F;
}
const cashLine = () => ({ account_id: "", deskripsi: "", nominal: "", tax_code_id: "" });

function cashFromServer(cfg, d) {
  const F = cashBlank(cfg), s = v => v == null ? "" : String(v);
  Object.assign(F, { id: d.id, version: d.version, status: d.status, nomor: d.nomor, tanggal: d.tanggal, memo: s(d.memo), alasan_void: d.alasan_void, journal_id: d.journal_id });
  if (cfg.kind === "transfer") {
    Object.assign(F, { rekening_asal_id: s(d.rekening_asal_id), rekening_tujuan_id: s(d.rekening_tujuan_id), nominal: f4(u4(d.nominal)), biaya_admin: u4(d.biaya_admin) ? f4(u4(d.biaya_admin)) : "" });
    return F;
  }
  F.contact_id = s(d.contact_id); F.rekening_id = s(d.rekening_id); F.total = d.total;
  (cfg.extra || []).forEach(h => { F[h.key] = h.type === "bool" ? !!d[h.key] : s(d[h.key]); });
  F.lines = (d.lines || []).map(l => ({ account_id: s(l.account_id), deskripsi: s(l.deskripsi), nominal: f4(u4(l.nominal)), tax_code_id: s(l.tax_code_id) }));
  if (!F.lines.length) F.lines = [cashLine()];
  return F;
}

function cashPayload(cfg, F) {
  const o = { tanggal: F.tanggal, memo: F.memo ? F.memo : null };
  if (cfg.kind === "transfer") {
    o.rekening_asal_id = payInt(F.rekening_asal_id); o.rekening_tujuan_id = payInt(F.rekening_tujuan_id); o.nominal = f4(u4(F.nominal));
    if (u4(F.biaya_admin) > 0) o.biaya_admin = f4(u4(F.biaya_admin));
  } else {
    o.contact_id = payInt(F.contact_id);
    o.rekening_id = cashUtang(cfg, F) ? null : payInt(F.rekening_id);
    o.lines = F.lines.map(l => ({ account_id: payInt(l.account_id), deskripsi: l.deskripsi || null, nominal: f4(u4(l.nominal)), tax_code_id: payInt(l.tax_code_id) }));
    (cfg.extra || []).forEach(h => { const v = F[h.key]; o[h.key] = h.type === "bool" ? !!v : (h.type === "text" ? (String(v || "").trim() || null) : v); });
  }
  if (F.id) o.version = F.version;
  return o;
}
const cashUtang = (cfg, F) => cfg.kind === "line" && F.jenis_pembayaran === "utang";   // Pencatatan Beban lewat utang: tanpa rekening
const cashSum = F => F.lines.reduce((a, l) => a + u4(l.nominal), 0);

function cashScreen(cfg) {
  return async function (f, m, j) {
    const tok = ++CASH_TOK.n, alive = () => tok === CASH_TOK.n && cur && cur[0] === m && cur[1] === j;
    const S = { view: "list", rows: [], next: null, q: "", status: "", dari: "", sampai: "", loading: true, err: null, F: null, R: null, busy: false, dirty: false };
    cashScreen.last = S; S.openDoc = openDoc; S.openNew = openNew;  // dipakai pengujian
    const can_ = a => can(f.slug, a), isT = cfg.kind === "transfer";

    async function refs() {
      if (S.R) return S.R;
      let banks = { ok: false, list: [] };
      try { const r = await API.get("/bank-accounts", { silent: true }); banks = { ok: true, list: (r.data && r.data.rekening) || [] }; } catch (e) { /* tanpa hak: dikunci */ }
      if (isT) return S.R = { banks };
      const [party, acc, tax] = await Promise.all([invRef(cfg.partyPath), invRef("/accounts?postable=1"), invRef("/tax-codes?aktif=1")]);
      tax.list = tax.list.filter(t => cfg.taxKinds.includes(t.jenis));
      return S.R = { banks, party, acc, tax };
    }
    const bankLabel = b => (b.kode_akun ? b.kode_akun + " - " : "") + (b.nama_akun || b.nama_bank || "Rekening") + (b.is_aktif === false ? " (nonaktif)" : "");
    const bankName = id => { if (id == null || id === "") return "-"; const b = ((S.R && S.R.banks.list) || []).find(x => String(x.id) === String(id)); return b ? bankLabel(b) : "Rekening #" + id; };
    const partyName = id => { if (id == null) return "-"; const p = ((S.R && S.R.party && S.R.party.list) || []).find(x => x.contact_id === id); return p ? p.nama : "#" + id; };
    const kn = x => (x.kode ? x.kode + " - " : "") + x.nama;

    /* ---------- daftar ---------- */
    async function loadList(more) {
      S.loading = true; S.err = null;
      try {
        const qs = new URLSearchParams(); qs.set("limit", "50");
        if (S.q) qs.set("q", S.q); if (S.status) qs.set("status", S.status); if (S.dari) qs.set("dari", S.dari); if (S.sampai) qs.set("sampai", S.sampai);
        if (more && S.next) qs.set("before_id", S.next);
        const [r] = await Promise.all([API.get(cfg.path + "?" + qs), refs()]);
        if (!alive()) return;
        S.rows = more ? S.rows.concat(r.data) : r.data; S.next = r.next_before_id || null;
      } catch (e) { if (!alive()) return; S.err = e.message || "Gagal memuat data."; }
      S.loading = false; drawList();
    }

    function drawList() {
      if (!alive() || S.view !== "list") return;
      const th = isT ? `<th>Dari</th><th>Ke</th><th class="num">Nominal</th>` : `<th>${esc(cfg.pihak)}</th><th>${esc(cfg.rekLabel)}</th><th class="num">Total</th>`;
      const tr = d => isT ? `<td>${esc(bankName(d.rekening_asal_id))}</td><td>${esc(bankName(d.rekening_tujuan_id))}</td><td class="num">${rp(u4(d.nominal) / 1e4)}</td>`
        : `<td>${esc(partyName(d.contact_id))}</td><td>${esc(d.jenis_pembayaran === "utang" ? "Utang usaha" : bankName(d.rekening_id))}</td><td class="num">${rp(u4(d.total) / 1e4)}</td>`;
      pg.innerHTML = head(f, m, can_("create") ? `<button class="btn p" id="ad">+ ${esc(cfg.baru)}</button>` : "") + `<div class="card tw"><div class="fl">
        <select id="fs" aria-label="Status"><option value="">Semua status</option>${Object.keys(STATUS).map(k => `<option value="${k}" ${S.status === k ? "selected" : ""}>${esc(STATUS[k][0])}</option>`).join("")}</select>
        <input id="fq" placeholder="Cari nomor…" value="${esc(S.q)}" style="flex:1;min-width:140px"><input id="fd" type="date" value="${esc(S.dari)}" aria-label="Dari"><input id="fe" type="date" value="${esc(S.sampai)}" aria-label="Sampai"></div>
        ${S.err ? `<div class="warn e">${esc(S.err)} <a class="lk" id="rt">Coba lagi</a></div>` : ""}
        <table><thead><tr><th>Nomor</th><th>Tanggal</th>${th}<th>Status</th></tr></thead><tbody>
        ${S.rows.map(d => `<tr class="row" data-id="${d.id}" tabindex="0"><td><b>${esc(d.nomor || "(Draft)")}</b></td><td>${esc(d.tanggal)}</td>${tr(d)}<td>${payBadge(d)}</td></tr>`).join("")}</tbody></table>
        ${!S.rows.length && !S.loading && !S.err ? `<div class="empty">Belum ada ${esc(cfg.noun)}${S.q || S.status || S.dari || S.sampai ? " yang cocok" : ""}.</div>` : ""}${S.loading ? `<div class="empty">Memuat…</div>` : ""}
        <div class="ft"><span>${S.rows.length} ${esc(cfg.noun)}</span>${S.next ? `<a class="lk" id="more">Muat lainnya</a>` : "<span></span>"}</div></div>`;
      const ad = $("#ad"); if (ad) ad.onclick = () => openNew();
      pg.querySelectorAll(".row").forEach(r => { const o = () => openDoc(+r.dataset.id); r.onclick = o; r.onkeydown = e => { if (e.key === "Enter") o(); }; });
      const re = () => loadList(false);
      $("#fs").onchange = e => { S.status = e.target.value; re(); }; $("#fd").onchange = e => { S.dari = e.target.value; re(); }; $("#fe").onchange = e => { S.sampai = e.target.value; re(); };
      $("#fq").oninput = e => { S.q = e.target.value; clearTimeout(cashScreen.t); cashScreen.t = setTimeout(() => re().then(() => { const q = $("#fq"); if (q) { q.focus(); q.setSelectionRange(S.q.length, S.q.length); } }), 300); };
      const rt = $("#rt"); if (rt) rt.onclick = re; const mo = $("#more"); if (mo) mo.onclick = () => loadList(true);
    }

    /* ---------- buka / baru ---------- */
    async function openNew() { await refs(); if (!alive()) return; S.view = "form"; S.F = cashBlank(cfg); S.dirty = false; drawForm(); }
    async function openDoc(id) {
      try {
        const [r] = await Promise.all([API.get(cfg.path + "/" + id), refs()]);
        if (!alive()) return;
        S.view = "form"; S.dirty = false; S.F = cashFromServer(cfg, r.data); drawForm();
      } catch (e) { toast(e.message); }
    }

    /* ---------- form ---------- */
    const opt = (list, val, lb, keep) => {
      const l = list.filter(x => !keep || keep(x) || String(x.id) === String(val)).map(x => [x.id, lb(x)]);
      if (val !== "" && val != null && !l.some(([k]) => String(k) === String(val))) l.unshift([val, "(#" + val + ")"]);
      return `<option value="">-</option>` + l.map(([k, n]) => `<option value="${esc(k)}" ${String(k) === String(val) ? "selected" : ""}>${esc(n)}</option>`).join("");
    };
    const bankOpt = val => opt(S.R.banks.list, val, bankLabel, x => x.is_aktif !== false);

    function actions(F, ro) {
      return `<button class="btn" id="bk">Kembali</button> ${!ro ? `<button class="btn" id="sv">Simpan draft</button> ` : ""}${F.status === "draft" && F.id && can_("post") ? `<button class="btn p" id="po">Posting</button> ` : ""}${!ro && !F.id && can_("post") && can_("create") ? `<button class="btn p" id="po">Simpan &amp; posting</button> ` : ""}${F.status === "posted" && can_("void") ? `<button class="btn d" id="vd">Void</button> ` : ""}${F.status === "draft" && F.id && can_("delete") ? `<button class="btn d" id="dl">Hapus draft</button>` : ""}`;
    }
    const banner = (F, R) => `${F.status === "pending_approval" ? `<div class="warn">Diajukan dan menunggu persetujuan. Dokumen tidak bisa diubah sampai diputuskan.</div>` : ""}${F.status === "void" && F.alasan_void ? `<div class="warn e">Dibatalkan: ${esc(F.alasan_void)}</div>` : ""}${!R.banks.ok ? `<div class="warn">Daftar rekening tidak bisa dimuat (tanpa hak lihat); pilihan rekening dikunci.</div>` : ""}${f.j && !/^Tanpa|^-$/.test(f.j) ? `<div class="warn i">Pola jurnal: ${esc(f.j)}</div>` : ""}`;

    function drawForm() {
      if (!alive()) return;
      const F = S.F, R = S.R, ro = F.status !== "draft" || (F.id ? !can_("update") : !can_("create")), d = ro ? "disabled" : "", bl = d || (R.banks.ok ? "" : "disabled");
      const title = F.id ? (F.nomor || "Draft") : "Baru";
      if (isT) {
        pg.innerHTML = head(f, m, actions(F, ro)) + `<div class="card"><h3 style="margin:0 0 10px">${esc(cfg.noun)} ${esc(title)} ${F.id ? payBadge(F) : ""}</h3>${banner(F, R)}<div id="me"></div>
          <div class="gh" style="margin-top:10px">
          <label><span>Tanggal *</span><input type="date" data-f="tanggal" value="${esc(F.tanggal)}" ${d}></label>
          <label><span>Rekening asal *</span><select data-f="rekening_asal_id" ${bl}>${bankOpt(F.rekening_asal_id)}</select></label>
          <label><span>Rekening tujuan *</span><select data-f="rekening_tujuan_id" ${bl}>${bankOpt(F.rekening_tujuan_id)}</select></label>
          <label><span>Nominal *</span><input data-f="nominal" type="number" min="0" step="any" value="${esc(F.nominal)}" ${d}></label>
          <label><span>Biaya admin (Rp)</span><input data-f="biaya_admin" type="number" min="0" step="any" value="${esc(F.biaya_admin)}" ${d}></label>
          <label style="grid-column:1/-1"><span>Memo</span><input data-f="memo" value="${esc(F.memo)}" ${d}></label></div></div>`;
        return bindForm();
      }
      const utang = cashUtang(cfg, F), tg = d || (R.tax.ok ? "" : "disabled"), ag = d || (R.acc.ok ? "" : "disabled"), est = cashSum(F);
      const ex = h => h.type === "bool" ? `<label><span>${esc(h.label)}</span><select data-f="${h.key}" ${d}><option value="0" ${F[h.key] ? "" : "selected"}>Tidak</option><option value="1" ${F[h.key] ? "selected" : ""}>Ya</option></select></label>`
        : h.type === "sel" ? `<label><span>${esc(h.label)}</span><select data-f="${h.key}" ${d}>${h.opts.map(([k, n]) => `<option value="${k}" ${F[h.key] === k ? "selected" : ""}>${esc(n)}</option>`).join("")}</select></label>`
        : `<label><span>${esc(h.label)}</span><input data-f="${h.key}" value="${esc(F[h.key])}" ${d}></label>`;
      const jenisHtml = (cfg.extra || []).filter(h => h.key === "jenis_pembayaran").map(ex).join(""), restHtml = (cfg.extra || []).filter(h => h.key !== "jenis_pembayaran").map(ex).join("");
      pg.innerHTML = head(f, m, actions(F, ro)) + `<div class="card"><h3 style="margin:0 0 10px">${esc(cfg.noun)} ${esc(title)} ${F.id ? payBadge(F) : ""}</h3>${banner(F, R)}<div id="me"></div>
        <div class="gh" style="margin-top:10px">
        ${jenisHtml}
        <label><span>${esc(cfg.pihak)}${utang ? " *" : ""}</span><select data-f="contact_id" ${d || (R.party.ok ? "" : "disabled")}>${opt(R.party.list.map(x => ({ id: x.contact_id, kode: x.kode, nama: x.nama })), F.contact_id, kn)}</select></label>
        <label><span>Tanggal *</span><input type="date" data-f="tanggal" value="${esc(F.tanggal)}" ${d}></label>
        ${utang ? "" : `<label><span>${esc(cfg.rekLabel)} *</span><select data-f="rekening_id" ${bl}>${bankOpt(F.rekening_id)}</select></label>`}
        ${restHtml}
        <label style="grid-column:1/-1"><span>Memo</span><input data-f="memo" value="${esc(F.memo)}" ${d}></label></div>
        ${[["Akun", R.acc], ["Pajak", R.tax], [cfg.pihak, R.party]].filter(x => !x[1].ok).map(x => `<div class="warn">Daftar ${esc(x[0])} tidak bisa dimuat (tanpa hak lihat); isian itu dikunci.</div>`).join("")}</div>
        <div class="card"><h3 style="margin:0 0 8px">Rincian</h3><div class="tw"><table class="ln"><thead><tr><th>Akun *</th><th>Deskripsi</th><th class="num">Nominal (sebelum pajak) *</th><th>Pajak</th><th></th></tr></thead><tbody>
        ${F.lines.map((l, i) => `<tr>
          <td><select data-l="${i}:account_id" ${ag}>${opt(R.acc.list, l.account_id, kn)}</select></td>
          <td><input data-l="${i}:deskripsi" value="${esc(l.deskripsi)}" ${d}></td>
          <td><input data-l="${i}:nominal" type="number" min="0" step="any" value="${esc(l.nominal)}" style="width:130px;text-align:right" ${d}></td>
          <td><select data-l="${i}:tax_code_id" ${tg}>${opt(R.tax.list, l.tax_code_id, kn)}</select></td>
          <td>${ro || F.lines.length < 2 ? "" : `<button class="btn" data-rm="${i}" aria-label="Hapus baris">✕</button>`}</td></tr>`).join("")}</tbody></table></div>
        ${ro ? "" : `<p style="margin:10px 0 0"><button class="btn" id="al">+ Tambah baris</button></p>`}
        <div style="max-width:340px;margin-left:auto;margin-top:12px">${F.total != null && !S.dirty
          ? `<div style="display:flex;justify-content:space-between;border-top:1px solid var(--ln);padding-top:6px;font-size:17px"><b>Total</b><b class="num">${rp(u4(F.total) / 1e4)}</b></div><div class="warn i">Total sudah termasuk pajak, dihitung server.</div>`
          : `<div style="display:flex;justify-content:space-between"><span>Perkiraan subtotal</span><b class="num" id="est">${rp(est / 1e4)}</b></div><div class="warn i">Pajak dan total resmi dihitung server saat disimpan.</div>`}</div></div>`;
      bindForm();
    }

    function setField(el) {
      const F = S.F; S.dirty = true;
      if (el.dataset.f) {
        const k = el.dataset.f, h = (cfg.extra || []).find(x => x.key === k);
        F[k] = h && h.type === "bool" ? el.value === "1" : el.value;
        if (k === "jenis_pembayaran") { if (cashUtang(cfg, F)) F.rekening_id = ""; return drawForm(); }   // utang: rekening disembunyikan
      } else if (el.dataset.l) { const [i, k] = el.dataset.l.split(":"); F.lines[+i][k] = el.value; }
      const est = $("#est"); if (est && F.lines) est.textContent = rp(cashSum(F) / 1e4);
    }

    function bindForm() {
      pg.querySelectorAll("[data-f],[data-l]").forEach(el => { el[el.tagName === "SELECT" ? "onchange" : "oninput"] = () => setField(el); });
      pg.querySelectorAll("[data-rm]").forEach(b => b.onclick = () => { S.F.lines.splice(+b.dataset.rm, 1); S.dirty = true; drawForm(); });
      const al = $("#al"); if (al) al.onclick = () => { S.F.lines.push(cashLine()); S.dirty = true; drawForm(); };
      $("#bk").onclick = () => { S.view = "list"; S.dirty = false; loadList(false); };
      const sv = $("#sv"); if (sv) sv.onclick = () => save(false);
      const po = $("#po"); if (po) po.onclick = () => save(true);
      const vd = $("#vd"); if (vd) vd.onclick = askVoid;
      const dl = $("#dl"); if (dl) dl.onclick = askDelete;
    }

    const showErr = e => { const el = $("#me"); const msg = e.first ? e.first() : e.message; el ? el.innerHTML = `<div class="warn e">${esc(msg)}</div>` : toast(msg); };

    /* pemeriksaan sisi klien; aturan resmi tetap di server */
    function check() {
      const F = S.F;
      if (isT) {
        if (!F.rekening_asal_id) return "Rekening asal wajib dipilih.";
        if (!F.rekening_tujuan_id) return "Rekening tujuan wajib dipilih.";
        if (F.rekening_asal_id === F.rekening_tujuan_id) return "Rekening asal dan tujuan tidak boleh sama.";
        if (!(u4(F.nominal) > 0)) return "Nominal transfer harus lebih dari 0.";
        return null;
      }
      if (cashUtang(cfg, F)) { if (!F.contact_id) return "Beban yang dicatat sebagai utang wajib punya " + cfg.pihak.toLowerCase() + "."; }
      else if (!F.rekening_id) return cfg.rekLabel + " wajib dipilih.";
      for (let i = 0; i < F.lines.length; i++) {
        const l = F.lines[i];
        if (!l.account_id) return "Baris " + (i + 1) + ": akun wajib dipilih.";
        if (!(u4(l.nominal) > 0)) return "Baris " + (i + 1) + ": nominal harus lebih dari 0.";
      }
      if (F.dapat_dikreditkan === true && F.lines.some(l => l.tax_code_id) && !String(F.no_faktur_pajak || "").trim()) return "PPN Masukan hanya bisa dikreditkan dengan nomor faktur pajak. Isi nomornya atau ubah \"Pajak dapat dikreditkan\" menjadi Tidak.";
      return null;
    }

    async function save(thenPost) {
      if (S.busy) return; const F = S.F, msg = check();
      if (msg) return showErr(new Error(msg));
      S.busy = true; let saved = false;
      try {
        let id = F.id;
        if (!id || S.dirty) {
          const body = cashPayload(cfg, F), r = id ? await API.put(cfg.path + "/" + id, body) : await API.post(cfg.path, body);
          id = r.data.id; S.dirty = false; S.F = cashFromServer(cfg, r.data); saved = true;
        }
        if (thenPost) {
          const r = await API.post("/documents/" + cfg.docType + "/" + id + "/post");
          toast(r.data.status === "pending_approval" ? "Diajukan untuk persetujuan" : "Diposting sebagai " + (r.data.nomor || ""));
          S.busy = false; return openDoc(id);
        }
        toast("Draft tersimpan"); S.busy = false; drawForm();
      } catch (e) { S.busy = false; if (saved) drawForm(); showErr(e); }  // draft sudah tersimpan: tampilkan tombol yang sesuai, lalu galatnya
    }

    function askVoid() {
      const F = S.F;
      dlg(`<h3>Batalkan ${esc(F.nomor)}?</h3><p style="color:var(--mut);margin:0 0 8px">Jurnal pembalik dibuat. Alasan wajib diisi.</p><input id="rs" placeholder="Alasan pembatalan" style="width:100%"><div id="de"></div><div style="text-align:right;margin-top:10px"><button class="btn" id="x">Batal</button> <button class="btn d" id="ok" disabled>Void</button></div>`);
      $("#x").onclick = cl; $("#rs").oninput = e => { $("#ok").disabled = !e.target.value.trim(); };
      $("#ok").onclick = async () => {
        $("#ok").disabled = true;
        try {
          const r = await API.post("/documents/" + cfg.docType + "/" + F.id + "/void", { alasan: $("#rs").value.trim() });
          cl(); toast(r.data.status === "pending_approval" ? "Pembatalan diajukan untuk persetujuan" : "Dibatalkan, jurnal pembalik dibuat"); openDoc(F.id);
        } catch (e) { $("#ok").disabled = false; $("#de").innerHTML = `<div class="warn e">${esc(e.first ? e.first() : e.message)}</div>`; }
      };
    }
    function askDelete() {
      const F = S.F;
      dlg(`<h3>Hapus draft ini?</h3><p style="color:var(--mut);margin:0 0 8px">Draft yang dihapus tidak bisa dikembalikan.</p><div id="de"></div><div style="text-align:right"><button class="btn" id="x">Batal</button> <button class="btn d" id="ok">Hapus</button></div>`);
      $("#x").onclick = cl;
      $("#ok").onclick = async () => {
        $("#ok").disabled = true;
        try { await API.del(cfg.path + "/" + F.id); cl(); toast("Draft dihapus"); S.view = "list"; S.dirty = false; loadList(false); }
        catch (e) { $("#ok").disabled = false; $("#de").innerHTML = `<div class="warn e">${esc(e.first ? e.first() : e.message)}</div>`; }
      };
    }

    pg.innerHTML = head(f, m, "") + `<div class="card"><div class="empty">Memuat…</div></div>`;
    loadList(false);
  };
}

const CASHDOCS = {
  penerimaan: { kind: "line", path: "/cash-receipts", docType: "cash_receipt", noun: "penerimaan", baru: "Penerimaan baru", pihak: "Sumber dana", partyPath: "/customers?limit=100", rekLabel: "Rekening tujuan", taxKinds: ["ppn", "ppnbm"], extra: [] },
  pembayaran: { kind: "line", path: "/cash-payments", docType: "cash_payment", noun: "pembayaran", baru: "Pembayaran baru", pihak: "Penerima", partyPath: "/suppliers?limit=100", rekLabel: "Rekening sumber", taxKinds: ["ppn"],
    extra: [{ key: "dapat_dikreditkan", label: "Pajak dapat dikreditkan", type: "bool", def: true }, { key: "no_faktur_pajak", label: "No faktur pajak", type: "text", def: "" }] },
  pencatatan_beban: { kind: "line", path: "/expenses", docType: "expense", noun: "beban", baru: "Beban baru", pihak: "Pemasok / penerima", partyPath: "/suppliers?limit=100", rekLabel: "Rekening pembayaran", taxKinds: ["ppn"],
    extra: [{ key: "jenis_pembayaran", label: "Dibayar lewat", type: "sel", opts: [["kas_bank", "Kas/Bank"], ["utang", "Utang usaha"]], def: "kas_bank" }, { key: "dapat_dikreditkan", label: "Pajak dapat dikreditkan", type: "bool", def: true }, { key: "no_faktur_pajak", label: "No faktur pajak", type: "text", def: "" }] },
  transfer_bank: { kind: "transfer", path: "/bank-transfers", docType: "bank_transfer", noun: "transfer bank", baru: "Transfer baru" },
};
Object.keys(CASHDOCS).forEach(slug => { SCREENS[slug] = cashScreen(CASHDOCS[slug]); });
