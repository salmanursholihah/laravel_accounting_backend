/* Langkah 4a: Faktur Penjualan dan Faktur Pembelian (daftar, form, simpan draft, posting, void, hapus draft).
   Satu mesin dokumen, dua konfigurasi. Angka resmi (subtotal, DPP, pajak, total) selalu dari server;
   angka di form sebelum disimpan hanya perkiraan. Dimuat setelah shell.js dan master.js. */

const INV_TOK = { n: 0 };
const invKn = x => (x.kode ? x.kode + " - " : "") + x.nama;
const invNum = v => (v === "" || v == null) ? null : String(v).replace(",", ".");
const invToday = () => { const d = new Date(), z = n => String(n).padStart(2, "0"); return d.getFullYear() + "-" + z(d.getMonth() + 1) + "-" + z(d.getDate()); };  // tanggal lokal, bukan UTC
const invInt = v => (v === "" || v == null) ? null : parseInt(v, 10);
const STATUS = { draft: ["Draft", "gr"], pending_approval: ["Menunggu persetujuan", "wn"], posted: ["Diposting", "ok"], void: ["Void", "er"] };
const BAYAR = { belum: ["Belum dibayar", "wn"], sebagian: ["Sebagian", "wn"], lunas: ["Lunas", "ok"] };
const invBadge = (d) => { const s = STATUS[d.status] || [d.status, "gr"], b = d.status === "posted" ? BAYAR[d.status_pembayaran] : null;
  return `<span class="bd ${s[1]}">${esc(s[0])}</span>${b ? ` <span class="bd ${b[1]}">${esc(b[0])}</span>` : ""}`; };

function invBlank(cfg) {
  const F = { id: null, version: null, status: "draft", tanggal: invToday(), contact_id: "", payment_term_id: "", harga_termasuk_pajak: false, diskon: "", memo: "", lines: [invLine()], totals: null };
  cfg.hdr.forEach(h => { F[h.key] = h.def !== undefined ? h.def : ""; });
  return F;
}
const invLine = () => ({ item_id: "", account_id: "", deskripsi: "", qty: "1", unit_id: "", harga: "", diskon_persen: "", tax_code_id: "", warehouse_id: "" });

/* Dokumen dari server -> state form */
function invFromServer(cfg, d) {
  const F = invBlank(cfg), s = v => v == null ? "" : String(v);
  Object.assign(F, { id: d.id, version: d.version, status: d.status, status_pembayaran: d.status_pembayaran, nomor: d.nomor, tanggal: d.tanggal, contact_id: s(d.contact_id), payment_term_id: s(d.payment_term_id),
    harga_termasuk_pajak: !!d.harga_termasuk_pajak, diskon: +d.diskon ? s(d.diskon) : "", memo: s(d.memo), alasan_void: d.alasan_void, journal_id: d.journal_id,
    totals: { subtotal: d.subtotal, diskon: d.diskon, dpp: d.dpp, total_pajak: d.total_pajak, biaya_kirim: d.biaya_kirim, total: d.total, total_dibayar: d.total_dibayar, sisa_tagihan: d.sisa_tagihan } });
  cfg.hdr.forEach(h => { const v = d[h.key]; F[h.key] = h.type === "bool" ? !!v : (h.type === "num" && !(+v)) ? "" : s(v); });
  F.lines = (d.lines || []).map(l => ({ item_id: s(l.item_id), account_id: s(l.account_id), deskripsi: s(l.deskripsi), qty: s(l.qty), unit_id: s(l.unit_id), harga: s(l.harga),
    diskon_persen: +l.diskon_persen ? s(l.diskon_persen) : "", tax_code_id: s(l.tax_code_id), warehouse_id: s(l.warehouse_id), jumlah: l.jumlah }));
  if (!F.lines.length) F.lines = [invLine()];
  return F;
}

/* State form -> payload API (angka sebagai string, kosong -> null / dilewati) */
function invPayload(cfg, F) {
  const o = { tanggal: F.tanggal, contact_id: invInt(F.contact_id), payment_term_id: invInt(F.payment_term_id), harga_termasuk_pajak: !!F.harga_termasuk_pajak, memo: F.memo ? F.memo : null,
    lines: F.lines.map(l => ({ item_id: invInt(l.item_id), account_id: invInt(l.account_id), deskripsi: l.deskripsi || null, qty: invNum(l.qty), unit_id: invInt(l.unit_id), harga: invNum(l.harga),
      diskon_persen: invNum(l.diskon_persen), tax_code_id: invInt(l.tax_code_id), warehouse_id: invInt(l.warehouse_id) })) };
  const d = invNum(F.diskon); if (d !== null) o.diskon = d;
  cfg.hdr.forEach(h => {
    const v = F[h.key];
    if (h.type === "bool") o[h.key] = !!v;
    else if (h.type === "num") { const n = invNum(v); if (n !== null) o[h.key] = n; }
    else o[h.key] = h.req ? String(v || "").trim() : (String(v || "").trim() || null);
  });
  if (F.id) o.version = F.version;
  return o;
}

/* Perkiraan jumlah baris sebelum disimpan (qty x harga x (1 - diskon%)); BUKAN angka resmi. */
const invLineEst = l => (parseFloat(invNum(l.qty)) || 0) * (parseFloat(invNum(l.harga)) || 0) * (1 - (parseFloat(invNum(l.diskon_persen)) || 0) / 100);
const invEstimate = F => F.lines.reduce((a, l) => a + invLineEst(l), 0);

async function invRef(path) {
  try { const r = await API.get(path, { silent: true }); return { ok: true, list: r.data || [] }; } catch (e) { return { ok: false, list: [] }; }
}

function invScreen(cfg) {
  return async function (f, m, j) {
    const tok = ++INV_TOK.n, alive = () => tok === INV_TOK.n && cur && cur[0] === m && cur[1] === j;
    const S = { view: "list", rows: [], next: null, q: "", status: "", dari: "", sampai: "", loading: true, err: null, F: null, warn: [], R: null, busy: false };
    invScreen.last = S; S.openDoc = openDoc; S.openNew = openNew;  // dipakai pengujian
    const can_ = a => can(f.slug, a);

    async function refs() {
      if (S.R) return S.R;
      const [party, items, units, wh, tax, terms, acc] = await Promise.all([invRef(cfg.partyPath), invRef("/items?limit=100&is_aktif=1"), invRef("/units?limit=100"), invRef("/warehouses?limit=100"),
        invRef("/tax-codes?aktif=1"), invRef("/payment-terms?aktif=1"), invRef("/accounts?postable=1")]);
      return S.R = { party, items, units, wh, tax, terms, acc };
    }
    const partyName = id => { const p = ((S.R && S.R.party.list) || []).find(x => x.contact_id === id); return p ? p.nama : "#" + id; };

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
        <table><thead><tr><th>Nomor</th><th>Tanggal</th><th>${esc(cfg.pihak)}</th><th>Jatuh tempo</th><th class="num">Total</th><th class="num">Sisa</th><th>Status</th></tr></thead><tbody>
        ${S.rows.map(d => `<tr class="row" data-id="${d.id}" tabindex="0"><td><b>${esc(d.nomor || "(Draft)")}</b></td><td>${esc(d.tanggal)}</td><td>${esc(partyName(d.contact_id))}</td><td>${esc(d.jatuh_tempo || "-")}</td><td class="num">${rp(+d.total)}</td><td class="num">${d.status === "posted" ? rp(+d.sisa_tagihan) : ""}</td><td>${invBadge(d)}</td></tr>`).join("")}</tbody></table>
        ${!S.rows.length && !S.loading && !S.err ? `<div class="empty">Belum ada ${esc(cfg.noun)}${S.q || S.status || S.dari || S.sampai ? " yang cocok" : ""}.</div>` : ""}${S.loading ? `<div class="empty">Memuat…</div>` : ""}
        <div class="ft"><span>${S.rows.length} ${esc(cfg.noun)}</span>${S.next ? `<a class="lk" id="more">Muat lainnya</a>` : "<span></span>"}</div></div>`;
      const ad = $("#ad"); if (ad) ad.onclick = () => openNew();
      pg.querySelectorAll(".row").forEach(tr => { const o = () => openDoc(+tr.dataset.id); tr.onclick = o; tr.onkeydown = e => { if (e.key === "Enter") o(); }; });
      const re = () => loadList(false);
      $("#fs").onchange = e => { S.status = e.target.value; re(); }; $("#fd").onchange = e => { S.dari = e.target.value; re(); }; $("#fe").onchange = e => { S.sampai = e.target.value; re(); };
      $("#fq").oninput = e => { S.q = e.target.value; clearTimeout(invScreen.t); invScreen.t = setTimeout(() => re().then(() => { const q = $("#fq"); if (q) { q.focus(); q.setSelectionRange(S.q.length, S.q.length); } }), 300); };
      const rt = $("#rt"); if (rt) rt.onclick = re; const mo = $("#more"); if (mo) mo.onclick = () => loadList(true);
    }

    /* ---------- buka / baru ---------- */
    async function openNew() { await refs(); if (!alive()) return; S.view = "form"; S.F = invBlank(cfg); S.warn = []; drawForm(); }
    async function openDoc(id) {
      try {
        const [r] = await Promise.all([API.get(cfg.path + "/" + id), refs()]);
        if (!alive()) return;
        S.view = "form"; S.dirty = false; S.F = invFromServer(cfg, r.data); S.warn = r.peringatan || []; drawForm();
      } catch (e) { toast(e.message); }
    }

    /* ---------- form ---------- */
    const opt = (list, val, lb, ok) => {
      const l = list.map(x => [x.id, lb(x)]);
      if (val !== "" && val != null && !l.some(([k]) => String(k) === String(val))) l.unshift([val, "(#" + val + ")"]);
      return `<option value="">-</option>` + l.map(([k, n]) => `<option value="${esc(k)}" ${String(k) === String(val) ? "selected" : ""}>${esc(n)}</option>`).join("");
    };

    function drawForm() {
      if (!alive()) return;
      const F = S.F, R = S.R, ro = F.status !== "draft" || (F.id ? !can_("update") : !can_("create")), d = ro ? "disabled" : "";
      const lock = (r) => d || (r.ok ? "" : "disabled");
      const T = F.totals, est = invEstimate(F);
      const title = F.id ? (F.nomor || "Draft") : "Baru";
      pg.innerHTML = head(f, m, `<button class="btn" id="bk">Kembali</button> ${!ro ? `<button class="btn" id="sv">Simpan draft</button> ` : ""}${F.status === "draft" && F.id && can_("post") ? `<button class="btn p" id="po">Posting</button> ` : ""}${!ro && !F.id && can_("post") && can_("create") ? `<button class="btn p" id="po">Simpan &amp; posting</button> ` : ""}${F.status === "posted" && can_("void") ? `<button class="btn d" id="vd">Void</button> ` : ""}${F.status === "draft" && F.id && can_("delete") ? `<button class="btn d" id="dl">Hapus draft</button>` : ""}`) +
        `<div class="card"><h3 style="margin:0 0 10px">${esc(cfg.noun)} ${esc(title)} ${F.id ? invBadge(F) : ""}</h3>
        ${S.warn.map(w => `<div class="warn">${esc(w.pesan)}</div>`).join("")}
        ${F.status === "pending_approval" ? `<div class="warn">Diajukan dan menunggu persetujuan. Dokumen tidak bisa diubah sampai diputuskan.</div>` : ""}
        ${F.status === "void" && F.alasan_void ? `<div class="warn e">Dibatalkan: ${esc(F.alasan_void)}</div>` : ""}
        <div id="me"></div>
        <div class="gh" style="margin-top:10px">
        <label><span>${esc(cfg.pihak)} *</span><select data-f="contact_id" ${lock(R.party)}>${opt(R.party.list.map(x => ({ id: x.contact_id, kode: x.kode, nama: x.nama })), F.contact_id, invKn)}</select></label>
        <label><span>Tanggal *</span><input type="date" data-f="tanggal" value="${esc(F.tanggal)}" ${d}></label>
        <label><span>Syarat pembayaran</span><select data-f="payment_term_id" ${lock(R.terms)}>${opt(R.terms.list, F.payment_term_id, x => x.nama)}</select></label>
        <label><span>Harga sudah termasuk pajak</span><select data-f="harga_termasuk_pajak" ${d}><option value="0" ${F.harga_termasuk_pajak ? "" : "selected"}>Tidak</option><option value="1" ${F.harga_termasuk_pajak ? "selected" : ""}>Ya</option></select></label>
        ${cfg.hdr.map(h => h.type === "bool" ? `<label><span>${esc(h.label)}</span><select data-f="${h.key}" ${d}><option value="0" ${F[h.key] ? "" : "selected"}>Tidak</option><option value="1" ${F[h.key] ? "selected" : ""}>Ya</option></select></label>`
          : `<label><span>${esc(h.label)}${h.req ? " *" : ""}</span><input data-f="${h.key}" type="${h.type === "num" ? "number" : "text"}" ${h.type === "num" ? 'min="0" step="any"' : ""} value="${esc(F[h.key])}" ${d}></label>`).join("")}
        <label><span>Diskon faktur (Rp)</span><input data-f="diskon" type="number" min="0" step="any" value="${esc(F.diskon)}" ${d}></label>
        <label style="grid-column:1/-1"><span>Memo</span><input data-f="memo" value="${esc(F.memo)}" ${d}></label></div>
        ${[["Barang", R.items], ["Satuan", R.units], ["Gudang", R.wh], ["Pajak", R.tax], ["Akun", R.acc]].filter(x => !x[1].ok).map(x => `<div class="warn">Daftar ${x[0]} tidak bisa dimuat (tanpa hak lihat); isian itu dikunci.</div>`).join("")}</div>
        <div class="card"><h3 style="margin:0 0 8px">Rincian</h3><div class="tw"><table class="ln"><thead><tr><th>Barang/Jasa</th><th>Akun</th><th>Deskripsi</th><th>Qty</th><th>Satuan</th><th>Harga</th><th>Disk %</th><th>Pajak</th><th>Gudang</th><th class="num">Jumlah</th><th></th></tr></thead><tbody>
        ${F.lines.map((l, i) => `<tr>
          <td><select data-l="${i}:item_id" ${lock(R.items)}>${opt(R.items.list, l.item_id, invKn)}</select></td>
          <td><select data-l="${i}:account_id" ${lock(R.acc)}>${opt(R.acc.list, l.account_id, invKn)}</select></td>
          <td><input data-l="${i}:deskripsi" value="${esc(l.deskripsi)}" ${d}></td>
          <td><input data-l="${i}:qty" type="number" min="0" step="any" value="${esc(l.qty)}" style="width:80px" ${d}></td>
          <td><select data-l="${i}:unit_id" ${lock(R.units)}>${opt(R.units.list, l.unit_id, x => x.kode || x.nama)}</select></td>
          <td><input data-l="${i}:harga" type="number" min="0" step="any" value="${esc(l.harga)}" style="width:110px" ${d}></td>
          <td><input data-l="${i}:diskon_persen" type="number" min="0" max="100" step="any" value="${esc(l.diskon_persen)}" style="width:70px" ${d}></td>
          <td><select data-l="${i}:tax_code_id" ${lock(R.tax)}>${opt(R.tax.list, l.tax_code_id, invKn)}</select></td>
          <td><select data-l="${i}:warehouse_id" ${lock(R.wh)}>${opt(R.wh.list, l.warehouse_id, invKn)}</select></td>
          <td class="num" id="jm${i}">${rp(F.id && !S.dirty && l.jumlah != null ? +l.jumlah : invLineEst(l))}</td>
          <td>${ro || F.lines.length < 2 ? "" : `<button class="btn" data-rm="${i}" aria-label="Hapus baris">✕</button>`}</td></tr>`).join("")}</tbody></table></div>
        ${ro ? "" : `<p style="margin:10px 0 0"><button class="btn" id="al">+ Tambah baris</button></p>`}
        <div style="max-width:340px;margin-left:auto;margin-top:12px">${T && !S.dirty
          ? [["Subtotal", T.subtotal], ["Diskon", T.diskon], ["DPP", T.dpp], ["Pajak", T.total_pajak], ...(cfg.kirim ? [["Biaya kirim", T.biaya_kirim]] : [])].map(x => `<div style="display:flex;justify-content:space-between"><span>${x[0]}</span><span class="num">${rp(+x[1])}</span></div>`).join("") +
            `<div style="display:flex;justify-content:space-between;border-top:1px solid var(--ln);margin-top:6px;padding-top:6px;font-size:17px"><b>Total</b><b class="num">${rp(+T.total)}</b></div>` +
            (F.status === "posted" ? `<div style="display:flex;justify-content:space-between;color:var(--mut)"><span>Sisa tagihan</span><span class="num">${rp(+T.sisa_tagihan)}</span></div>` : "")
          : `<div style="display:flex;justify-content:space-between"><span>Perkiraan subtotal</span><b class="num" id="est">${rp(est)}</b></div><div class="warn i">Pajak dan total resmi dihitung server saat disimpan.</div>`}</div></div>`;
      bindForm();
    }

    /* tulis nilai input ke state tanpa menggambar ulang (kecuali pilihan barang) */
    function setField(el) {
      const F = S.F;
      if (el.dataset.f) {
        const k = el.dataset.f;
        F[k] = k === "harga_termasuk_pajak" || (cfg.hdr.find(h => h.key === k) || {}).type === "bool" ? el.value === "1" : el.value;
      } else if (el.dataset.l) {
        const [i, k] = el.dataset.l.split(":"), l = F.lines[+i]; l[k] = el.value;
        if (k === "item_id") {
          const it = ((S.R.items.list) || []).find(x => String(x.id) === el.value);
          if (it) { l.unit_id = String(it.unit_id || ""); l.harga = it[cfg.hargaItem] != null ? String(+it[cfg.hargaItem]) : ""; l.tax_code_id = it[cfg.pajakItem] ? String(it[cfg.pajakItem]) : l.tax_code_id; if (!l.deskripsi) l.deskripsi = it.nama; }
          S.dirty = true; return drawForm();
        }
      }
      S.dirty = true;
      F.lines.forEach((l, i) => { const e = $("#jm" + i); if (e) e.textContent = rp(invLineEst(l)); });
      const est = $("#est"); if (est) est.textContent = rp(invEstimate(F));
    }

    function bindForm() {
      pg.querySelectorAll("[data-f],[data-l]").forEach(el => { el[el.tagName === "SELECT" ? "onchange" : "oninput"] = () => setField(el); });
      pg.querySelectorAll("[data-rm]").forEach(b => b.onclick = () => { S.F.lines.splice(+b.dataset.rm, 1); S.dirty = true; drawForm(); });
      const al = $("#al"); if (al) al.onclick = () => { S.F.lines.push(invLine()); S.dirty = true; drawForm(); };
      $("#bk").onclick = () => { S.view = "list"; S.dirty = false; loadList(false); };
      const sv = $("#sv"); if (sv) sv.onclick = () => save(false);
      const po = $("#po"); if (po) po.onclick = () => save(true);
      const vd = $("#vd"); if (vd) vd.onclick = askVoid;
      const dl = $("#dl"); if (dl) dl.onclick = askDelete;
    }

    const showErr = e => { const el = $("#me"); const msg = e.first ? e.first() : e.message; el ? el.innerHTML = `<div class="warn e">${esc(msg)}</div>` : toast(msg); };

    async function save(thenPost) {
      if (S.busy) return; const F = S.F;
      if (!F.contact_id) return showErr(new Error(cfg.pihak + " wajib dipilih."));
      if (F.lines.some(l => !(parseFloat(invNum(l.qty)) > 0))) return showErr(new Error("Qty setiap baris harus lebih dari 0."));
      S.busy = true; let saved = false;
      try {
        let id = F.id, doc;
        if (!id || S.dirty) {
          const body = invPayload(cfg, F), r = id ? await API.put(cfg.path + "/" + id, body) : await API.post(cfg.path, body);
          doc = r.data; id = doc.id; S.warn = r.peringatan || [];
          S.dirty = false; S.F = invFromServer(cfg, doc); saved = true;
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
      dlg(`<h3>Batalkan ${esc(F.nomor)}?</h3><p style="color:var(--mut);margin:0 0 8px">Jurnal pembalik dibuat dan stok dikembalikan. Alasan wajib diisi.</p><input id="rs" placeholder="Alasan pembatalan" style="width:100%"><div id="de"></div><div style="text-align:right;margin-top:10px"><button class="btn" id="x">Batal</button> <button class="btn d" id="ok" disabled>Void</button></div>`);
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

const INVOICES = {
  faktur_penjualan: {
    path: "/sales-invoices", docType: "sales_invoice", noun: "faktur penjualan", baru: "Faktur baru", pihak: "Pelanggan", partyPath: "/customers?limit=100",
    hargaItem: "harga_jual", pajakItem: "tax_code_jual_id", kirim: true,
    hdr: [{ key: "biaya_kirim", label: "Biaya kirim (Rp)", type: "num", def: "" }],
  },
  faktur_pembelian: {
    path: "/purchase-invoices", docType: "purchase_invoice", noun: "faktur pembelian", baru: "Faktur baru", pihak: "Pemasok", partyPath: "/suppliers?limit=100",
    hargaItem: "harga_beli_standar", pajakItem: "tax_code_beli_id", kirim: false,
    hdr: [{ key: "no_faktur_pemasok", label: "No faktur pemasok", req: true, def: "" }, { key: "no_faktur_pajak", label: "No faktur pajak", def: "" },
      { key: "dapat_dikreditkan", label: "Pajak dapat dikreditkan", type: "bool", def: true }],
  },
};
Object.keys(INVOICES).forEach(slug => { SCREENS[slug] = invScreen(INVOICES[slug]); });
