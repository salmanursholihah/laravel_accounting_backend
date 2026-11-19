/* Langkah 4d-2: Penyesuaian Persediaan (stock-adjustments).
   Aturan backend yang diikuti UI: hanya barang (bukan jasa), qty selisih tidak boleh 0 (plus = tambah, minus = kurang),
   harga satuan hanya untuk selisih plus (selisih minus memakai HPP rata-rata), alasan wajib.
   Endpoint daftar hanya mendukung filter status, jadi hanya itu yang ditampilkan.
   Dimuat setelah invoice.js dan payment.js (memakai STATUS, invRef, invToday, invKn, u4, f4, payInt, payBadge). */

const ADJ_TOK = { n: 0 };
const adjLine = () => ({ item_id: "", deskripsi: "", qty_selisih: "", harga_satuan: "" });

function adjBlank() { return { id: null, version: null, status: "draft", nomor: null, tanggal: invToday(), warehouse_id: "", alasan: "", memo: "", lines: [adjLine()], alasan_void: null }; }
function adjFromServer(d) {
  const s = v => v == null ? "" : String(v);
  const F = adjBlank();
  Object.assign(F, { id: d.id, version: d.version, status: d.status, nomor: d.nomor, tanggal: d.tanggal, warehouse_id: s(d.warehouse_id), alasan: s(d.alasan), memo: s(d.memo), alasan_void: d.alasan_void, journal_id: d.journal_id });
  F.lines = (d.lines || []).map(l => ({ item_id: s(l.item_id), deskripsi: s(l.deskripsi), qty_selisih: f4(u4(l.qty_selisih)), harga_satuan: l.harga_satuan == null ? "" : f4(u4(l.harga_satuan)), nilai: l.nilai }));
  if (!F.lines.length) F.lines = [adjLine()];
  return F;
}
function adjPayload(F) {
  const o = { tanggal: F.tanggal, warehouse_id: payInt(F.warehouse_id), alasan: F.alasan.trim(), memo: F.memo.trim() || null,
    lines: F.lines.map(l => ({ item_id: payInt(l.item_id), deskripsi: l.deskripsi.trim() || null, qty_selisih: f4(u4(l.qty_selisih)), harga_satuan: (u4(l.qty_selisih) > 0 && l.harga_satuan !== "") ? f4(u4(l.harga_satuan)) : null })) };
  if (F.id) o.version = F.version;
  return o;
}

function adjScreen() {
  return async function (f, m, j) {
    const tok = ++ADJ_TOK.n, alive = () => tok === ADJ_TOK.n && cur && cur[0] === m && cur[1] === j;
    const S = { view: "list", rows: [], next: null, status: "", loading: true, err: null, F: null, R: null, busy: false, dirty: false };
    adjScreen.last = S; S.openDoc = openDoc; S.openNew = openNew;  // dipakai pengujian
    const can_ = a => can(f.slug, a), path = "/stock-adjustments";

    async function refs() {
      if (S.R) return S.R;
      const [items, wh, units] = await Promise.all([invRef("/items?limit=100&is_aktif=1&tipe=barang"), invRef("/warehouses?limit=100"), invRef("/units?limit=100")]);
      return S.R = { items, wh, units };
    }
    const whName = id => { const w = ((S.R && S.R.wh.list) || []).find(x => x.id === id); return w ? invKn(w) : "#" + id; };
    const unitOf = id => { const it = ((S.R && S.R.items.list) || []).find(x => String(x.id) === String(id)); if (!it) return ""; const u = S.R.units.list.find(x => x.id === it.unit_id); return u ? (u.kode || u.nama) : ""; };

    /* ---------- daftar ---------- */
    async function loadList(more) {
      S.loading = true; S.err = null;
      try {
        const qs = new URLSearchParams(); qs.set("limit", "50");
        if (S.status) qs.set("status", S.status);
        if (more && S.next) qs.set("before_id", S.next);
        const [r] = await Promise.all([API.get(path + "?" + qs), refs()]);
        if (!alive()) return;
        S.rows = more ? S.rows.concat(r.data) : r.data; S.next = r.next_before_id || null;
      } catch (e) { if (!alive()) return; S.err = e.message || "Gagal memuat data."; }
      S.loading = false; drawList();
    }

    function drawList() {
      if (!alive() || S.view !== "list") return;
      pg.innerHTML = head(f, m, can_("create") ? `<button class="btn p" id="ad">+ Penyesuaian baru</button>` : "") + `<div class="card tw"><div class="fl">
        <select id="fs" aria-label="Status"><option value="">Semua status</option>${Object.keys(STATUS).map(k => `<option value="${k}" ${S.status === k ? "selected" : ""}>${esc(STATUS[k][0])}</option>`).join("")}</select></div>
        ${S.err ? `<div class="warn e">${esc(S.err)} <a class="lk" id="rt">Coba lagi</a></div>` : ""}
        <table><thead><tr><th>Nomor</th><th>Tanggal</th><th>Gudang</th><th>Alasan</th><th class="num">Baris</th><th>Status</th></tr></thead><tbody>
        ${S.rows.map(d => `<tr class="row" data-id="${d.id}" tabindex="0"><td><b>${esc(d.nomor || "(Draft)")}</b></td><td>${esc(d.tanggal)}</td><td>${esc(whName(d.warehouse_id))}</td><td>${esc(d.alasan)}</td><td class="num">${d.jumlah_baris == null ? "" : d.jumlah_baris}</td><td>${payBadge(d)}</td></tr>`).join("")}</tbody></table>
        ${!S.rows.length && !S.loading && !S.err ? `<div class="empty">Belum ada penyesuaian persediaan${S.status ? " yang cocok" : ""}.</div>` : ""}${S.loading ? `<div class="empty">Memuat…</div>` : ""}
        <div class="ft"><span>${S.rows.length} penyesuaian</span>${S.next ? `<a class="lk" id="more">Muat lainnya</a>` : "<span></span>"}</div></div>`;
      const ad = $("#ad"); if (ad) ad.onclick = () => openNew();
      pg.querySelectorAll(".row").forEach(r => { const o = () => openDoc(+r.dataset.id); r.onclick = o; r.onkeydown = e => { if (e.key === "Enter") o(); }; });
      $("#fs").onchange = e => { S.status = e.target.value; loadList(false); };
      const rt = $("#rt"); if (rt) rt.onclick = () => loadList(false); const mo = $("#more"); if (mo) mo.onclick = () => loadList(true);
    }

    /* ---------- buka / baru ---------- */
    async function openNew() { await refs(); if (!alive()) return; S.view = "form"; S.F = adjBlank(); S.dirty = false; drawForm(); }
    async function openDoc(id) {
      try {
        const [r] = await Promise.all([API.get(path + "/" + id), refs()]);
        if (!alive()) return;
        S.view = "form"; S.dirty = false; S.F = adjFromServer(r.data); drawForm();
      } catch (e) { toast(e.message); }
    }

    /* ---------- form ---------- */
    const opt = (list, val, lb) => {
      const l = list.map(x => [x.id, lb(x)]);
      if (val !== "" && val != null && !l.some(([k]) => String(k) === String(val))) l.unshift([val, "(#" + val + ")"]);
      return `<option value="">-</option>` + l.map(([k, n]) => `<option value="${esc(k)}" ${String(k) === String(val) ? "selected" : ""}>${esc(n)}</option>`).join("");
    };

    function drawForm() {
      if (!alive()) return;
      const F = S.F, R = S.R, ro = F.status !== "draft" || (F.id ? !can_("update") : !can_("create")), d = ro ? "disabled" : "";
      const ig = d || (R.items.ok ? "" : "disabled"), wg = d || (R.wh.ok ? "" : "disabled"), title = F.id ? (F.nomor || "Draft") : "Baru";
      const nilaiTot = F.lines.reduce((a, l) => a + (l.nilai != null ? u4(l.nilai) : 0), 0), hasNilai = F.id && !S.dirty && F.lines.every(l => l.nilai != null);
      pg.innerHTML = head(f, m, `<button class="btn" id="bk">Kembali</button> ${!ro ? `<button class="btn" id="sv">Simpan draft</button> ` : ""}${F.status === "draft" && F.id && can_("post") ? `<button class="btn p" id="po">Posting</button> ` : ""}${!ro && !F.id && can_("post") && can_("create") ? `<button class="btn p" id="po">Simpan &amp; posting</button> ` : ""}${F.status === "posted" && can_("void") ? `<button class="btn d" id="vd">Void</button> ` : ""}${F.status === "draft" && F.id && can_("delete") ? `<button class="btn d" id="dl">Hapus draft</button>` : ""}`) +
        `<div class="card"><h3 style="margin:0 0 10px">Penyesuaian ${esc(title)} ${F.id ? payBadge(F) : ""}</h3>
        ${F.status === "pending_approval" ? `<div class="warn">Diajukan dan menunggu persetujuan. Dokumen tidak bisa diubah sampai diputuskan.</div>` : ""}
        ${F.status === "void" && F.alasan_void ? `<div class="warn e">Dibatalkan: ${esc(F.alasan_void)}</div>` : ""}
        ${f.j ? `<div class="warn i">Pola jurnal: ${esc(f.j)}</div>` : ""}
        ${[["Barang", R.items], ["Gudang", R.wh]].filter(x => !x[1].ok).map(x => `<div class="warn">Daftar ${x[0]} tidak bisa dimuat (tanpa hak lihat); isian itu dikunci.</div>`).join("")}<div id="me"></div>
        <div class="gh" style="margin-top:10px">
        <label><span>Gudang *</span><select data-f="warehouse_id" ${wg}>${opt(R.wh.list, F.warehouse_id, invKn)}</select></label>
        <label><span>Tanggal *</span><input type="date" data-f="tanggal" value="${esc(F.tanggal)}" ${d}></label>
        <label><span>Alasan *</span><input data-f="alasan" maxlength="500" value="${esc(F.alasan)}" ${d}></label>
        <label style="grid-column:1/-1"><span>Memo</span><input data-f="memo" value="${esc(F.memo)}" ${d}></label></div></div>
        <div class="card"><h3 style="margin:0 0 8px">Rincian</h3><div class="tw"><table class="ln"><thead><tr><th>Barang *</th><th>Deskripsi</th><th class="num">Qty selisih *</th><th>Satuan</th><th class="num">Harga satuan (hanya selisih plus)</th>${hasNilai ? `<th class="num">Nilai</th>` : ""}<th></th></tr></thead><tbody>
        ${F.lines.map((l, i) => { const neg = u4(l.qty_selisih) < 0; return `<tr>
          <td><select data-l="${i}:item_id" ${ig}>${opt(R.items.list, l.item_id, invKn)}</select></td>
          <td><input data-l="${i}:deskripsi" value="${esc(l.deskripsi)}" ${d}></td>
          <td><input data-l="${i}:qty_selisih" type="number" step="any" value="${esc(l.qty_selisih)}" style="width:110px;text-align:right" ${d}></td>
          <td id="un${i}">${esc(unitOf(l.item_id))}</td>
          <td><input data-l="${i}:harga_satuan" type="number" min="0" step="any" value="${esc(l.harga_satuan)}" style="width:130px;text-align:right" ${d || (neg ? "disabled" : "")}></td>
          ${hasNilai ? `<td class="num">${rp(u4(l.nilai) / 1e4)}</td>` : ""}
          <td>${ro || F.lines.length < 2 ? "" : `<button class="btn" data-rm="${i}" aria-label="Hapus baris">✕</button>`}</td></tr>`; }).join("")}${hasNilai ? `<tr><td colspan="5"><b>Total nilai selisih</b></td><td class="num"><b>${rp(nilaiTot / 1e4)}</b></td><td></td></tr>` : ""}</tbody></table></div>
        ${ro ? "" : `<p style="margin:10px 0 0"><button class="btn" id="al">+ Tambah baris</button></p>`}
        ${hasNilai ? "" : `<div class="warn i">Selisih plus tanpa harga memakai HPP rata-rata. Nilai resmi dihitung server saat disimpan.</div>`}</div>`;
      bindForm();
    }

    function bindForm() {
      pg.querySelectorAll("[data-f]").forEach(el => el[el.tagName === "SELECT" ? "onchange" : "oninput"] = () => { S.F[el.dataset.f] = el.value; S.dirty = true; });
      pg.querySelectorAll("[data-l]").forEach(el => el[el.tagName === "SELECT" ? "onchange" : "oninput"] = () => {
        const [i, k] = el.dataset.l.split(":"), l = S.F.lines[+i]; l[k] = el.value; S.dirty = true;
        if (k === "item_id") { const u = $("#un" + i); if (u) u.textContent = unitOf(l.item_id); }
        if (k === "qty_selisih") {   // selisih minus: harga satuan tidak berlaku (HPP rata-rata)
          const h = pg.querySelector(`[data-l="${i}:harga_satuan"]`), neg = u4(l.qty_selisih) < 0;
          if (neg) { l.harga_satuan = ""; if (h) h.value = ""; } if (h) h.disabled = neg;
        }
      });
      pg.querySelectorAll("[data-rm]").forEach(b => b.onclick = () => { S.F.lines.splice(+b.dataset.rm, 1); S.dirty = true; drawForm(); });
      const al = $("#al"); if (al) al.onclick = () => { S.F.lines.push(adjLine()); S.dirty = true; drawForm(); };
      $("#bk").onclick = () => { S.view = "list"; S.dirty = false; loadList(false); };
      const sv = $("#sv"); if (sv) sv.onclick = () => save(false);
      const po = $("#po"); if (po) po.onclick = () => save(true);
      const vd = $("#vd"); if (vd) vd.onclick = askVoid;
      const dl = $("#dl"); if (dl) dl.onclick = askDelete;
    }

    const showErr = e => { const el = $("#me"); const msg = e.first ? e.first() : e.message; el ? el.innerHTML = `<div class="warn e">${esc(msg)}</div>` : toast(msg); };

    function check() {
      const F = S.F;
      if (!F.warehouse_id) return "Gudang wajib dipilih.";
      if (!F.tanggal) return "Tanggal wajib diisi.";
      if (!F.alasan.trim()) return "Alasan wajib diisi.";
      for (let i = 0; i < F.lines.length; i++) {
        const l = F.lines[i], q = u4(l.qty_selisih);
        if (!l.item_id) return "Baris " + (i + 1) + ": barang wajib dipilih.";
        if (q === 0) return "Baris " + (i + 1) + ": qty selisih tidak boleh 0.";
      }
      return null;
    }

    async function save(thenPost) {
      if (S.busy) return; const F = S.F, msg = check();
      if (msg) return showErr(new Error(msg));
      S.busy = true; let saved = false;
      try {
        let id = F.id;
        if (!id || S.dirty) {
          const body = adjPayload(F), r = id ? await API.put(path + "/" + id, body) : await API.post(path, body);
          id = r.data.id; S.dirty = false; S.F = adjFromServer(r.data); saved = true;
        }
        if (thenPost) {
          const r = await API.post("/documents/stock_adjustment/" + id + "/post");
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
          const r = await API.post("/documents/stock_adjustment/" + F.id + "/void", { alasan: $("#rs").value.trim() });
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
        try { await API.del(path + "/" + F.id); cl(); toast("Draft dihapus"); S.view = "list"; S.dirty = false; loadList(false); }
        catch (e) { $("#ok").disabled = false; $("#de").innerHTML = `<div class="warn e">${esc(e.first ? e.first() : e.message)}</div>`; }
      };
    }

    pg.innerHTML = head(f, m, "") + `<div class="card"><div class="empty">Memuat…</div></div>`;
    loadList(false);
  };
}
SCREENS["penyesuaian_persediaan"] = adjScreen();
