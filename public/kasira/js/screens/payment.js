/* Langkah 4b: Pembayaran Pembelian (pola A: dokumen dengan alokasi ke faktur terbuka).
   Satu mesin, satu konfigurasi. Penerimaan Penjualan memakai mesin yang sama, tetapi backend-nya belum punya
   endpoint (lihat docs/KASIRA_UI.md), jadi baru didaftarkan setelah backend itu ada.
   Aturan backend yang diikuti UI:
   - Alokasi OTOMATIS = kirim allocations kosong; server menghitung final saat Posting (jatuh tempo terlama dulu).
     Angka di layar hanya saran dari GET open-invoices?nominal=.
   - Alokasi MANUAL = kirim baris alokasi; jumlahnya harus sama dengan nominal dan tiap baris <= sisa tagihan.
   - Nominal harus habis dialokasikan (kelebihan bayar / uang muka belum didukung).
   Dimuat setelah invoice.js (memakai STATUS dan invToday dari sana). */

const PAY_TOK = { n: 0 };
/* Uang dihitung dalam satuan 1/10.000 (integer) supaya penjumlahan tidak kena galat pecahan; server tetap yang resmi. */
const u4 = v => { const n = parseFloat(String(v == null ? "" : v).replace(",", ".")); return isFinite(n) ? Math.round(n * 1e4) : 0; };
const f4 = u => (u / 1e4).toFixed(4).replace(/\.?0+$/, "");
const payInt = v => (v === "" || v == null) ? null : parseInt(v, 10);
const payBadge = d => { const s = STATUS[d.status] || [d.status, "gr"]; return `<span class="bd ${s[1]}">${esc(s[0])}</span>`; };

function payBlank() { return { id: null, version: null, status: "draft", nomor: null, tanggal: invToday(), contact_id: "", rekening_id: "", nominal: "", memo: "", mode: "auto", man: {}, alokasi: [], alasan_void: null, journal_id: null }; }

function payFromServer(d) {
  const s = v => v == null ? "" : String(v), al = d.alokasi || [], man = {};
  al.forEach(a => { man[a.purchase_invoice_id] = f4(u4(a.nominal)); });
  return { id: d.id, version: d.version, status: d.status, nomor: d.nomor, tanggal: d.tanggal, contact_id: s(d.contact_id), rekening_id: s(d.rekening_id), nominal: f4(u4(d.nominal)),
    memo: s(d.memo), mode: al.length ? "man" : "auto", man, alokasi: al, alasan_void: d.alasan_void, journal_id: d.journal_id };
}

function payScreen(cfg) {
  return async function (f, m, j) {
    const tok = ++PAY_TOK.n, alive = () => tok === PAY_TOK.n && cur && cur[0] === m && cur[1] === j;
    const S = { view: "list", rows: [], next: null, q: "", status: "", dari: "", sampai: "", loading: true, err: null, F: null, R: null, open: null, openErr: null, openTok: 0, busy: false, dirty: false, names: {} };
    payScreen.last = S; S.openDoc = openDoc; S.openNew = openNew;  // dipakai pengujian
    const can_ = a => can(f.slug, a);

    async function refs() {
      if (S.R) return S.R;
      const party = await invRef(cfg.partyPath);
      let banks = { ok: false, list: [] };
      try { const r = await API.get("/bank-accounts", { silent: true }); banks = { ok: true, list: (r.data && r.data.rekening) || [] }; } catch (e) { /* tanpa hak: pilihan rekening dikunci */ }
      return S.R = { party, banks };
    }
    const partyName = id => { const p = ((S.R && S.R.party.list) || []).find(x => x.contact_id === id); return p ? p.nama : "#" + id; };
    const bankLabel = b => (b.kode_akun ? b.kode_akun + " - " : "") + (b.nama_akun || b.nama_bank || "Rekening") + (b.is_aktif === false ? " (nonaktif)" : "");
    const bankName = id => { const b = ((S.R && S.R.banks.list) || []).find(x => String(x.id) === String(id)); return b ? bankLabel(b) : "Rekening #" + id; };

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
      pg.innerHTML = head(f, m, can_("create") ? `<button class="btn p" id="ad">+ ${esc(cfg.baru)}</button>` : "") + `<div class="card tw"><div class="fl">
        <select id="fs" aria-label="Status"><option value="">Semua status</option>${Object.keys(STATUS).map(k => `<option value="${k}" ${S.status === k ? "selected" : ""}>${esc(STATUS[k][0])}</option>`).join("")}</select>
        <input id="fq" placeholder="Cari nomor…" value="${esc(S.q)}" style="flex:1;min-width:140px"><input id="fd" type="date" value="${esc(S.dari)}" aria-label="Dari"><input id="fe" type="date" value="${esc(S.sampai)}" aria-label="Sampai"></div>
        ${S.err ? `<div class="warn e">${esc(S.err)} <a class="lk" id="rt">Coba lagi</a></div>` : ""}
        <table><thead><tr><th>Nomor</th><th>Tanggal</th><th>${esc(cfg.pihak)}</th><th>${esc(cfg.rekLabel)}</th><th class="num">Nominal</th><th>Status</th></tr></thead><tbody>
        ${S.rows.map(d => `<tr class="row" data-id="${d.id}" tabindex="0"><td><b>${esc(d.nomor || "(Draft)")}</b></td><td>${esc(d.tanggal)}</td><td>${esc(partyName(d.contact_id))}</td><td>${esc(bankName(d.rekening_id))}</td><td class="num">${rp(u4(d.nominal) / 1e4)}</td><td>${payBadge(d)}</td></tr>`).join("")}</tbody></table>
        ${!S.rows.length && !S.loading && !S.err ? `<div class="empty">Belum ada ${esc(cfg.noun)}${S.q || S.status || S.dari || S.sampai ? " yang cocok" : ""}.</div>` : ""}${S.loading ? `<div class="empty">Memuat…</div>` : ""}
        <div class="ft"><span>${S.rows.length} ${esc(cfg.noun)}</span>${S.next ? `<a class="lk" id="more">Muat lainnya</a>` : "<span></span>"}</div></div>`;
      const ad = $("#ad"); if (ad) ad.onclick = () => openNew();
      pg.querySelectorAll(".row").forEach(tr => { const o = () => openDoc(+tr.dataset.id); tr.onclick = o; tr.onkeydown = e => { if (e.key === "Enter") o(); }; });
      const re = () => loadList(false);
      $("#fs").onchange = e => { S.status = e.target.value; re(); }; $("#fd").onchange = e => { S.dari = e.target.value; re(); }; $("#fe").onchange = e => { S.sampai = e.target.value; re(); };
      $("#fq").oninput = e => { S.q = e.target.value; clearTimeout(payScreen.t); payScreen.t = setTimeout(() => re().then(() => { const q = $("#fq"); if (q) { q.focus(); q.setSelectionRange(S.q.length, S.q.length); } }), 300); };
      const rt = $("#rt"); if (rt) rt.onclick = re; const mo = $("#more"); if (mo) mo.onclick = () => loadList(true);
    }

    /* ---------- buka / baru ---------- */
    async function openNew() { await refs(); if (!alive()) return; S.view = "form"; S.F = payBlank(); S.open = null; S.openErr = null; S.dirty = false; drawForm(); }
    async function openDoc(id) {
      try {
        const [r] = await Promise.all([API.get(cfg.path + "/" + id), refs()]);
        if (!alive()) return;
        S.view = "form"; S.dirty = false; S.F = payFromServer(r.data); S.open = null; S.openErr = null; S.names = {};
        drawForm();
        if (S.F.status === "draft") loadOpen();
        else await Promise.all((S.F.alokasi || []).map(a => API.get(cfg.invPath + "/" + a.purchase_invoice_id, { silent: true }).then(x => { S.names[a.purchase_invoice_id] = x.data.nomor; }).catch(() => { })));
        if (alive() && S.F.status !== "draft") drawPanel();
      } catch (e) { toast(e.message); }
    }

    /* faktur terbuka milik pemasok terpilih (+ saran alokasi otomatis dari server) */
    async function loadOpen() {
      const F = S.F;
      if (!F || F.status !== "draft" || !F.contact_id) { S.open = null; S.openErr = null; return drawPanel(); }
      const t = ++S.openTok, qs = new URLSearchParams({ contact_id: F.contact_id });
      if (F.tanggal) qs.set("tanggal", F.tanggal);
      if (F.mode === "auto" && u4(F.nominal) > 0) qs.set("nominal", f4(u4(F.nominal)));
      try {
        const r = await API.get(cfg.openPath + "?" + qs);
        if (!alive() || t !== S.openTok) return;
        S.open = r.data; S.openErr = null;
      } catch (e) { if (!alive() || t !== S.openTok) return; S.open = null; S.openErr = e.first ? e.first() : e.message; }
      drawPanel();
    }

    /* hitung alokasi yang ditampilkan: otomatis = saran server, manual = isian pengguna */
    function calc() {
      const F = S.F, o = S.open, rows = (o && o.faktur) || [], nom = u4(F.nominal), sar = {};
      if (F.mode === "auto" && o && o.saran_alokasi) o.saran_alokasi.forEach(a => { sar[a.invoice_id] = u4(a.nominal); });
      const A = rows.map(r => { const a = F.mode === "auto" ? (sar[r.id] || 0) : u4(F.man[r.id]), sisa = u4(r.sisa); return { r, a, sisa, over: a > sisa }; });
      const tot = A.reduce((s, x) => s + x.a, 0);
      const stray = F.mode === "man" ? Object.keys(F.man).filter(id => u4(F.man[id]) > 0 && !rows.some(r => String(r.id) === id)) : [];
      return { A, tot, nom, belum: nom - tot, stray, totalSisa: u4(o && o.total_sisa) };
    }

    /* ---------- form ---------- */
    const opt = (list, val, lb, keep) => {
      const l = list.filter(x => !keep || keep(x) || String(x.id) === String(val)).map(x => [x.id, lb(x)]);
      if (val !== "" && val != null && !l.some(([k]) => String(k) === String(val))) l.unshift([val, "(#" + val + ")"]);
      return `<option value="">-</option>` + l.map(([k, n]) => `<option value="${esc(k)}" ${String(k) === String(val) ? "selected" : ""}>${esc(n)}</option>`).join("");
    };

    function drawForm() {
      if (!alive()) return;
      const F = S.F, R = S.R, ro = F.status !== "draft" || (F.id ? !can_("update") : !can_("create")), d = ro ? "disabled" : "";
      const bankLock = d || (R.banks.ok ? "" : "disabled"), title = F.id ? (F.nomor || "Draft") : "Baru";
      pg.innerHTML = head(f, m, `<button class="btn" id="bk">Kembali</button> ${!ro ? `<button class="btn" id="sv">Simpan draft</button> ` : ""}${F.status === "draft" && F.id && can_("post") ? `<button class="btn p" id="po">Posting</button> ` : ""}${!ro && !F.id && can_("post") && can_("create") ? `<button class="btn p" id="po">Simpan &amp; posting</button> ` : ""}${F.status === "posted" && can_("void") ? `<button class="btn d" id="vd">Void</button> ` : ""}${F.status === "draft" && F.id && can_("delete") ? `<button class="btn d" id="dl">Hapus draft</button>` : ""}`) +
        `<div class="card"><h3 style="margin:0 0 10px">${esc(cfg.noun)} ${esc(title)} ${F.id ? payBadge(F) : ""}</h3>
        ${F.status === "pending_approval" ? `<div class="warn">Diajukan dan menunggu persetujuan. Dokumen tidak bisa diubah sampai diputuskan.</div>` : ""}
        ${F.status === "void" && F.alasan_void ? `<div class="warn e">Dibatalkan: ${esc(F.alasan_void)}</div>` : ""}
        ${!R.banks.ok ? `<div class="warn">Daftar rekening tidak bisa dimuat (tanpa hak lihat); pilihan rekening dikunci.</div>` : ""}
        <div id="me"></div>
        <div class="gh" style="margin-top:10px">
        <label><span>${esc(cfg.pihak)} *</span><select data-f="contact_id" ${d || (R.party.ok ? "" : "disabled")}>${opt(R.party.list.map(x => ({ id: x.contact_id, kode: x.kode, nama: x.nama })), F.contact_id, x => (x.kode ? x.kode + " - " : "") + x.nama)}</select></label>
        <label><span>Tanggal *</span><input type="date" data-f="tanggal" value="${esc(F.tanggal)}" ${d}></label>
        <label><span>${esc(cfg.rekLabel)} *</span><select data-f="rekening_id" ${bankLock}>${opt(R.banks.list, F.rekening_id, bankLabel, x => x.is_aktif !== false)}</select></label>
        <label><span>${esc(cfg.nomLabel)} *</span><input data-f="nominal" type="number" min="0" step="any" value="${esc(F.nominal)}" ${d}></label>
        <label><span>Alokasi</span><select data-f="mode" ${d}><option value="auto" ${F.mode === "auto" ? "selected" : ""}>Otomatis, jatuh tempo terlama</option><option value="man" ${F.mode === "man" ? "selected" : ""}>Manual</option></select></label>
        <label><span>Memo</span><input data-f="memo" value="${esc(F.memo)}" ${d}></label></div></div>
        <div id="pc"></div>`;
      bindForm(); drawPanel();
    }

    /* panel bawah: tabel faktur + ringkasan + pratinjau jurnal. Digambar terpisah agar ketikan di form tidak kehilangan fokus. */
    function drawPanel() {
      const box = $("#pc"); if (!alive() || !box || !S.F) return;
      const F = S.F;
      if (F.status !== "draft") {   // dokumen terkunci: tampilkan alokasi yang tersimpan
        const al = F.alokasi || [], tot = al.reduce((s, a) => s + u4(a.nominal), 0), nom = u4(F.nominal), rek = bankName(F.rekening_id);
        box.innerHTML = `<div class="cols"><div class="card"><h3>Dialokasikan ke faktur</h3>${al.length ? `<div class="tw"><table><thead><tr><th>Faktur</th><th class="num">Dialokasi</th></tr></thead><tbody>${al.map(a => `<tr><td>${esc(S.names[a.purchase_invoice_id] || "Faktur #" + a.purchase_invoice_id)}</td><td class="num">${rp(u4(a.nominal) / 1e4)}</td></tr>`).join("")}</tbody></table></div>` : `<div class="empty">${F.status === "posted" ? "Alokasi ditentukan otomatis saat posting; lihat faktur terkait untuk rinciannya." : "Belum ada alokasi tersimpan (otomatis saat posting)."}</div>`}
          ${F.status === "void" ? `<div class="warn i">Dokumen di-void; sisa tagihan faktur dikembalikan dan jurnal pembalik dibuat.</div>` : ""}</div>
          <div class="card"><h3>Ringkasan</h3><div class="sum"><div><span>${esc(cfg.nomLabel)}</span><b class="num">${rp(nom / 1e4)}</b></div>${al.length ? `<div><span>Dialokasikan</span><span class="num">${rp(tot / 1e4)}</span></div>` : ""}</div>
          <h3 style="margin-top:14px">Jurnal</h3>${jurnal(rek, nom)}</div></div>`;
        return;
      }
      const c = calc(), o = S.open;
      let body;
      if (!F.contact_id) body = `<div class="empty">Pilih ${esc(cfg.pihak.toLowerCase())} untuk melihat faktur yang terbuka.</div>`;
      else if (S.openErr) body = `<div class="warn e">${esc(S.openErr)} <a class="lk" id="ro">Coba lagi</a></div>`;
      else if (!o) body = `<div class="empty">Memuat faktur terbuka…</div>`;
      else if (!c.A.length) body = `<div class="empty">${esc(cfg.pihak)} ini tidak punya faktur terbuka. Pembayaran tidak bisa diposting karena kelebihan bayar (uang muka) belum didukung.</div>`;
      else body = `<div class="tw"><table><thead><tr><th>Faktur</th><th>Jatuh tempo</th><th class="num">Sisa tagihan</th><th class="num">Dialokasi</th><th class="num">Sisa setelah</th></tr></thead><tbody>
        ${c.A.map(x => `<tr><td>${esc(x.r.nomor || "#" + x.r.id)}${x.r.no_faktur_pemasok ? `<div style="color:var(--mut);font-size:12px">${esc(x.r.no_faktur_pemasok)}</div>` : ""}</td><td>${esc(x.r.jatuh_tempo)}</td><td class="num">${rp(x.sisa / 1e4)}</td>
          <td class="num">${F.mode === "auto" ? rp(x.a / 1e4) : `<input type="number" min="0" step="any" style="width:130px;text-align:right" data-n="${x.r.id}" value="${esc(F.man[x.r.id] == null ? "" : F.man[x.r.id])}" aria-label="Alokasi ${esc(x.r.nomor || x.r.id)}">`}</td>
          <td class="num" id="sa${x.r.id}" style="${x.over ? "color:var(--bad)" : ""}">${rp((x.sisa - x.a) / 1e4)}</td></tr>`).join("")}</tbody></table></div>`;
      box.innerHTML = `<div class="cols"><div class="card"><h3>Faktur terbuka</h3>${body}<div id="aw"></div></div>
        <div class="card"><h3>Ringkasan</h3><div id="sm"></div><h3 style="margin-top:14px">Pratinjau jurnal</h3><div id="jr"></div></div></div>`;
      const ro = $("#ro"); if (ro) ro.onclick = loadOpen;
      box.querySelectorAll("[data-n]").forEach(i => i.oninput = () => { S.F.man[i.dataset.n] = i.value; S.dirty = true; drawSide(); });
      drawSide();
    }

    function jurnal(rek, nom) { return `<div class="jp"><div><span>Dr Utang Usaha</span><b class="num">${rp(nom / 1e4)}</b></div><div class="k"><span>Cr ${esc(rek)}</span><span class="num">${rp(nom / 1e4)}</span></div></div>`; }

    /* bagian yang berubah tiap ketikan: peringatan, ringkasan, jurnal, kolom "sisa setelah" */
    function drawSide() {
      const sm = $("#sm"); if (!alive() || !sm || !S.F || S.F.status !== "draft") return;
      const F = S.F, c = calc(), w = [];
      c.A.forEach(x => { const e = $("#sa" + x.r.id); if (e) { e.textContent = rp((x.sisa - x.a) / 1e4); e.style.color = x.over ? "var(--bad)" : ""; } });
      if (c.A.some(x => x.over)) w.push(["e", "✕ Alokasi melebihi sisa tagihan sebuah faktur."]);
      if (F.mode === "man" && c.tot > c.nom) w.push(["e", "✕ Total alokasi melebihi nominal."]);
      if (c.stray.length) w.push(["e", "✕ Ada alokasi ke faktur yang tidak lagi terbuka (#" + c.stray.join(", #") + "). Kosongkan isiannya."]);
      if (F.contact_id && S.open && c.nom > 0 && c.belum > 0 && !(F.mode === "man" && c.tot > c.nom)) w.push(["e", `✕ Kelebihan ${rp(c.belum / 1e4)} belum bisa dicatat sebagai uang muka. ${F.mode === "auto" ? "Kurangi nominal." : "Kurangi nominal atau tambah alokasi."}`]);
      if (F.mode === "auto" && c.nom > 0) w.push(["i", "ⓘ Alokasi otomatis dihitung final oleh server saat posting; angka di atas adalah saran."]);
      $("#aw").innerHTML = w.map(x => `<div class="warn ${x[0]}">${esc(x[1])}</div>`).join("");
      sm.innerHTML = `<div class="sum"><div><span>${esc(cfg.nomLabel)}</span><b class="num">${rp(c.nom / 1e4)}</b></div><div><span>Dialokasikan</span><span class="num">${rp(c.tot / 1e4)}</span></div><div><span>Belum dialokasikan</span><span class="num">${rp(Math.max(c.belum, 0) / 1e4)}</span></div>${S.open ? `<div><span>Total sisa tagihan ${esc(cfg.pihak.toLowerCase())}</span><span class="num">${rp(c.totalSisa / 1e4)}</span></div>` : ""}</div>`;
      $("#jr").innerHTML = jurnal(F.rekening_id ? bankName(F.rekening_id) : "Kas/Bank", c.nom);
    }

    function bindForm() {
      let tm;
      pg.querySelectorAll("[data-f]").forEach(el => {
        el[el.tagName === "SELECT" ? "onchange" : "oninput"] = () => {
          const k = el.dataset.f, F = S.F; F[k] = el.value; S.dirty = true;
          if (k === "contact_id") { F.man = {}; S.open = null; S.openErr = null; drawPanel(); loadOpen(); }
          else if (k === "tanggal") { clearTimeout(tm); tm = setTimeout(loadOpen, 250); }
          else if (k === "rekening_id") drawSide();
          else if (k === "nominal") { drawSide(); clearTimeout(tm); tm = setTimeout(() => { if (F.mode === "auto") loadOpen(); }, 300); }
          else if (k === "mode") {
            if (F.mode === "man") { F.man = {}; const o = S.open; if (o && o.saran_alokasi) o.saran_alokasi.forEach(a => { F.man[a.invoice_id] = f4(u4(a.nominal)); }); drawPanel(); }  // mulai dari saran otomatis
            else { drawPanel(); loadOpen(); }
          }
        };
      });
      $("#bk").onclick = () => { S.view = "list"; S.dirty = false; loadList(false); };
      const sv = $("#sv"); if (sv) sv.onclick = () => save(false);
      const po = $("#po"); if (po) po.onclick = () => save(true);
      const vd = $("#vd"); if (vd) vd.onclick = askVoid;
      const dl = $("#dl"); if (dl) dl.onclick = askDelete;
    }

    const showErr = e => { const el = $("#me"); const msg = e.first ? e.first() : e.message; el ? el.innerHTML = `<div class="warn e">${esc(msg)}</div>` : toast(msg); };

    function payload() {
      const F = S.F, o = { tanggal: F.tanggal, contact_id: payInt(F.contact_id), rekening_id: payInt(F.rekening_id), nominal: f4(u4(F.nominal)), memo: F.memo ? F.memo : null, allocations: [] };
      if (F.mode === "man") o.allocations = Object.keys(F.man).filter(id => u4(F.man[id]) > 0).map(id => ({ purchase_invoice_id: +id, nominal: f4(u4(F.man[id])) }));
      if (F.id) o.version = F.version;
      return o;
    }

    async function save(thenPost) {
      if (S.busy) return; const F = S.F;
      if (!F.contact_id) return showErr(new Error(cfg.pihak + " wajib dipilih."));
      if (!F.rekening_id) return showErr(new Error(cfg.rekLabel + " wajib dipilih."));
      if (!(u4(F.nominal) > 0)) return showErr(new Error("Nominal harus lebih dari 0."));
      if (F.mode === "man") {
        const c = calc();
        if (c.A.some(x => x.over)) return showErr(new Error("Alokasi melebihi sisa tagihan sebuah faktur."));
        if (c.stray.length) return showErr(new Error("Ada alokasi ke faktur yang tidak lagi terbuka."));
        if (c.belum !== 0) return showErr(new Error("Jumlah alokasi harus sama dengan nominal (selisih " + rp(Math.abs(c.belum) / 1e4) + ")."));
      }
      S.busy = true; let saved = false;
      try {
        let id = F.id;
        if (!id || S.dirty) {
          const body = payload(), r = id ? await API.put(cfg.path + "/" + id, body) : await API.post(cfg.path, body);
          id = r.data.id; S.dirty = false; S.F = payFromServer(r.data); saved = true;
        }
        if (thenPost) {
          const r = await API.post("/documents/" + cfg.docType + "/" + id + "/post");
          toast(r.data.status === "pending_approval" ? "Diajukan untuk persetujuan" : "Diposting sebagai " + (r.data.nomor || ""));
          S.busy = false; return openDoc(id);
        }
        toast("Draft tersimpan"); S.busy = false; drawForm(); loadOpen();
      } catch (e) { S.busy = false; if (saved) { drawForm(); loadOpen(); } showErr(e); }  // draft sudah tersimpan: tampilkan tombol yang sesuai, lalu galatnya
    }

    function askVoid() {
      const F = S.F;
      dlg(`<h3>Batalkan ${esc(F.nomor)}?</h3><p style="color:var(--mut);margin:0 0 8px">Jurnal pembalik dibuat dan sisa tagihan faktur dikembalikan. Alasan wajib diisi.</p><input id="rs" placeholder="Alasan pembatalan" style="width:100%"><div id="de"></div><div style="text-align:right;margin-top:10px"><button class="btn" id="x">Batal</button> <button class="btn d" id="ok" disabled>Void</button></div>`);
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

const PAYMENTS = {
  pembayaran_pembelian: {
    path: "/purchase-payments", openPath: "/purchase-payments/open-invoices", invPath: "/purchase-invoices", docType: "purchase_payment",
    noun: "pembayaran pembelian", baru: "Pembayaran baru", pihak: "Pemasok", partyPath: "/suppliers?limit=100", rekLabel: "Rekening pembayaran", nomLabel: "Nominal dibayar",
  },
};
Object.keys(PAYMENTS).forEach(slug => { SCREENS[slug] = payScreen(PAYMENTS[slug]); });
