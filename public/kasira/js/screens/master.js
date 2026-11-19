/* Langkah 3: pola master (M) berbasis API asli. Satu mesin generik: tabel + panel samping + simpan.
   Setiap fitur hanya perlu satu konfigurasi (lihat MASTERS di bawah). Dimuat setelah shell.js. */

const MASTER_TOK = { n: 0 };

/* Ubah isi form menjadi payload API menurut tipe kolom. Kosong -> null (kolom nullable) atau dilewati. */
function masterPayload(cfg, vals, creating) {
  const out = {};
  cfg.fields.forEach(fd => {
    if (fd.createOnly && !creating) return;
    let v = vals[fd.key];
    if (v === undefined) return;
    v = typeof v === "string" ? v.trim() : v;
    if (v === "") { if (fd.nullable) out[fd.key] = null; else if (fd.req) out[fd.key] = ""; return; }
    out[fd.key] = fd.type === "int" ? parseInt(v, 10) : fd.type === "num" ? String(v).replace(",", ".")
      : fd.type === "bool" ? String(v) === "1" : fd.type === "pct" ? String(+(parseFloat(String(v).replace(",", ".")) / 100).toFixed(6)) : v;
  });
  return out;
}

/* Payload dari baris tersimpan (untuk API yang memvalidasi seluruh isian pada setiap PUT, mis. master stok). */
function masterRowPayload(cfg, row) {
  const vals = {};
  cfg.fields.forEach(fd => { const v = row[fd.key]; vals[fd.key] = v == null ? "" : fd.type === "bool" ? (v ? "1" : "0") : fd.type === "pct" ? String(+(parseFloat(v) * 100).toFixed(4)) : String(v); });
  return masterPayload(cfg, vals, false);
}

/* Muat daftar pilihan (mis. syarat pembayaran). Gagal/403 -> daftar kosong, form tetap bisa dipakai. */
async function masterLoadRefs(cfg) {
  const refs = {};
  await Promise.all(cfg.fields.filter(fd => fd.ref).map(async fd => {
    try {
      const r = await API.get(fd.ref.path, { silent: true }), lb = fd.ref.label || (x => x.nama);
      refs[fd.key] = { ok: true, list: (r.data || []).filter(fd.ref.filter || (() => true)).map(x => [x.id, lb(x)]) };
    } catch (e) { refs[fd.key] = { ok: false, list: [] }; }  // tanpa hak lihat / gagal: isian dikunci dan tidak ikut dikirim
  }));
  return refs;
}

function masterField(fd, val, refs, ro, creating) {
  const id = "mf_" + fd.key, v = val == null ? "" : fd.type === "bool" ? (val ? "1" : "0") : fd.type === "pct" ? String(+(parseFloat(val) * 100).toFixed(4)) : val;
  let inp, lock = ro || (fd.createOnly && !creating);
  if (fd.ref) {
    const R = refs[fd.key] || { ok: false, list: [] }, list = R.list.slice();
    if (v !== "" && !list.some(([k]) => String(k) === String(v))) list.unshift([v, "(#" + v + ")"]);  // nilai tersimpan yang tidak ada di daftar tetap utuh
    if (!R.ok) lock = true;
    inp = `<select id="${id}" ${lock ? "disabled" : ""}><option value="">- Tidak ada -</option>${list.map(([k, n]) => `<option value="${esc(k)}" ${String(k) === String(v) ? "selected" : ""}>${esc(n)}</option>`).join("")}</select>`;
  } else if (fd.opts || fd.type === "bool") {
    const o = fd.opts || [["1", "Ya"], ["0", "Tidak"]];
    inp = `<select id="${id}" ${lock ? "disabled" : ""}>${fd.req || fd.type === "bool" ? "" : '<option value="">-</option>'}${o.map(([k, n]) => `<option value="${esc(k)}" ${String(k) === String(v) ? "selected" : ""}>${esc(n)}</option>`).join("")}</select>`;
  } else if (fd.type === "area") {
    inp = `<textarea id="${id}" rows="2" ${lock ? "disabled" : ""} style="width:100%">${esc(v)}</textarea>`;
  } else {
    const t = ["int", "num", "pct"].includes(fd.type) ? "number" : fd.type === "email" ? "email" : fd.type === "date" ? "date" : "text";
    inp = `<input id="${id}" type="${t}" ${fd.type === "num" || fd.type === "pct" ? 'step="any"' : ""} ${fd.min != null ? `min="${fd.min}"` : ""} ${fd.max != null ? `max="${fd.max}"` : ""} value="${esc(v)}" ${lock ? "disabled" : ""}>`;
  }
  return `<label><span>${esc(fd.label)}${fd.req ? " *" : ""}</span>${inp}</label>`;
}

function masterScreen(cfg) {
  return async function (f, m, j) {
    const tok = ++MASTER_TOK.n, alive = () => tok === MASTER_TOK.n && cur && cur[0] === m && cur[1] === j;
    const S = { rows: [], next: null, sel: null, q: "", aktif: "", loading: true, err: null, refs: {} };
    const canC = can(f.slug, "create"), canU = can(f.slug, "update"), canD = cfg.deletable && can(f.slug, "delete");

    pg.innerHTML = head(f, m, canC ? `<button class="btn p" id="ad">+ Baru</button>` : "") + `<div class="card"><div class="empty">Memuat…</div></div>`;

    async function load(more) {
      S.loading = true; S.err = null;
      try {
        const qs = new URLSearchParams();
        if (cfg.paged) {
          qs.set("limit", "50");
          if (S.q) qs.set("q", S.q);
          if (S.aktif !== "") qs.set(cfg.aktifParam || "aktif", S.aktif === "1" ? "1" : "0");
          if (more && S.next) qs.set("after_id", S.next);
        }
        const r = await API.get(cfg.path + (qs.toString() ? "?" + qs : ""));
        if (!alive()) return;
        S.rows = more ? S.rows.concat(r.data) : r.data; S.next = r.next_after_id || null;
      } catch (e) { if (!alive()) return; S.err = e.message || "Gagal memuat data."; }
      S.loading = false; draw();
    }

    const visible = () => cfg.paged ? S.rows : S.rows.filter(r => (!S.q || (String(r.nama) + " " + String(r.kode || "")).toLowerCase().includes(S.q.toLowerCase())) && (S.aktif === "" || (S.aktif === "1") === !!r.is_aktif));
    const cur_ = () => S.sel === "new" ? null : S.rows.find(r => r.id === S.sel) || null;

    function draw() {
      if (!alive()) return;
      const rows = visible(), r = cur_(), creating = S.sel === "new", panel = creating || r;
      const ro = creating ? !canC : !canU;
      pg.innerHTML = head(f, m, canC ? `<button class="btn p" id="ad">+ Baru</button>` : "") +
        `<div class="cols" style="grid-template-columns:1fr ${panel ? "360px" : "0"}"><div class="card tw">
        <div class="fl"><input id="mq" placeholder="Cari kode atau nama…" value="${esc(S.q)}" style="flex:1;min-width:160px"><select id="ma" aria-label="Status"><option value="">Semua status</option><option value="1" ${S.aktif === "1" ? "selected" : ""}>Aktif</option><option value="0" ${S.aktif === "0" ? "selected" : ""}>Nonaktif</option></select></div>
        ${S.err ? `<div class="warn e">${esc(S.err)} <a class="lk" id="rt">Coba lagi</a></div>` : ""}
        <table><thead><tr>${cfg.cols.map(c => `<th class="${c.num ? "r" : ""}">${esc(c.label)}</th>`).join("")}<th>Status</th></tr></thead><tbody>
        ${rows.map(x => `<tr class="row ${x.id === S.sel ? "sel" : ""}" data-id="${x.id}" tabindex="0">${cfg.cols.map(c => `<td class="${c.num ? "num" : ""}">${c.fmt ? c.fmt(x[c.key], x) : esc(x[c.key] == null ? "" : x[c.key])}</td>`).join("")}<td>${x.is_aktif ? '<span class="bd ok">Aktif</span>' : '<span class="bd gr">Nonaktif</span>'}</td></tr>`).join("")}</tbody></table>
        ${!rows.length && !S.loading && !S.err ? `<div class="empty">Belum ada data${S.q || S.aktif !== "" ? " yang cocok" : ""}.${canC ? ` <a class="lk" id="ad2">Tambah ${esc(cfg.noun)}</a>` : ""}</div>` : ""}
        ${S.loading ? `<div class="empty">Memuat…</div>` : ""}
        <div class="ft"><span>${rows.length} ${esc(cfg.noun)}</span>${S.next ? `<a class="lk" id="more">Muat lainnya</a>` : "<span></span>"}</div></div>
        ${panel ? `<div class="card"><h3>${creating ? "Baru: " + esc(cfg.noun) : esc(r.nama)}</h3>
        <div class="gh" style="grid-template-columns:1fr">${cfg.fields.map(fd => masterField(fd, creating ? fd.def : r[fd.key], S.refs, ro, creating)).join("")}</div>
        ${cfg.info && r ? cfg.info(r) : ""}
        <div id="me"></div>
        <p style="margin:14px 0 0">${ro ? "" : `<button class="btn p" id="sv">Simpan</button> `}${!creating && canU && cfg.toggle ? `<button class="btn" id="nf">${r.is_aktif ? "Nonaktifkan" : "Aktifkan"}</button> ` : ""}${!creating && canD ? `<button class="btn d" id="dl">Hapus</button> ` : ""}<button class="btn" id="cx">Tutup</button></p>
        <div class="warn">ⓘ ${esc(cfg.note)}</div></div>` : ""}</div>`;
      bind();
    }

    function showErr(e) {
      const el = $("#me"); if (!el) return toast(e.message);
      el.innerHTML = `<div class="warn e">${esc(e.first ? e.first() : e.message)}</div>`;
    }

    function bind() {
      const ad = $("#ad"), ad2 = $("#ad2"); [ad, ad2].forEach(b => b && (b.onclick = () => { S.sel = "new"; draw(); }));
      pg.querySelectorAll(".row").forEach(tr => { const o = () => { S.sel = +tr.dataset.id; draw(); }; tr.onclick = o; tr.onkeydown = e => { if (e.key === "Enter") o(); }; });
      const mq = $("#mq"); if (mq) mq.oninput = e => {
        S.q = e.target.value; const p = e.target.selectionStart;
        clearTimeout(masterScreen.t);
        const run = () => { draw(); const q = $("#mq"); if (q) { q.focus(); q.setSelectionRange(p, p); } };
        if (cfg.paged) masterScreen.t = setTimeout(() => load(false).then(() => { const q = $("#mq"); if (q) { q.focus(); q.setSelectionRange(p, p); } }), 300); else run();
      };
      const ma = $("#ma"); if (ma) ma.onchange = e => { S.aktif = e.target.value; cfg.paged ? load(false) : draw(); };
      const rt = $("#rt"); if (rt) rt.onclick = () => load(false);
      const mo = $("#more"); if (mo) mo.onclick = () => load(true);
      const cx = $("#cx"); if (cx) cx.onclick = () => { S.sel = null; draw(); };

      const sv = $("#sv"); if (sv) sv.onclick = async () => {
        const creating = S.sel === "new", vals = {};
        cfg.fields.forEach(fd => { const e = $("#mf_" + fd.key); if (e && !e.disabled) vals[fd.key] = e.value; });
        const miss = cfg.fields.find(fd => fd.req && (creating || !fd.createOnly) && vals[fd.key] !== undefined && !String(vals[fd.key]).trim());
        if (miss) return showErr(new Error(miss.label + " wajib diisi."));
        sv.disabled = true;
        try {
          const body = masterPayload(cfg, vals, creating);
          const res = creating ? await API.post(cfg.path, body) : await API.put(cfg.path + "/" + S.sel, body);
          toast(creating ? cfg.noun + " dibuat" : "Perubahan disimpan");
          const d = res.data;
          if (creating) S.rows.push(d); else S.rows = S.rows.map(x => x.id === d.id ? d : x);
          S.sel = d.id; draw();
        } catch (e) { sv.disabled = false; showErr(e); }
      };

      const nf = $("#nf"); if (nf) nf.onclick = async () => {
        const r = cur_(); nf.disabled = true;
        try {
          const body = cfg.fullUpdate ? masterRowPayload(cfg, r) : {};
          body.is_aktif = !r.is_aktif;
          const res = await API.put(cfg.path + "/" + r.id, body);
          S.rows = S.rows.map(x => x.id === r.id ? res.data : x); toast(res.data.is_aktif ? "Diaktifkan" : "Dinonaktifkan"); draw();
        } catch (e) { nf.disabled = false; showErr(e); }
      };

      const dl = $("#dl"); if (dl) dl.onclick = () => {
        const r = cur_();
        dlg(`<h3>Hapus ${esc(r.nama)}?</h3><p style="color:var(--mut);margin:0 0 10px">Data yang sudah dipakai transaksi tidak bisa dihapus; nonaktifkan saja.</p><div id="de"></div><div style="text-align:right"><button class="btn" id="x">Batal</button> <button class="btn d" id="ok">Hapus</button></div>`);
        $("#x").onclick = cl;
        $("#ok").onclick = async () => {
          $("#ok").disabled = true;
          try { await API.del(cfg.path + "/" + r.id); cl(); S.rows = S.rows.filter(x => x.id !== r.id); S.sel = null; toast("Dihapus"); draw(); }
          catch (e) { $("#ok").disabled = false; $("#de").innerHTML = `<div class="warn e">${esc(e.first ? e.first() : e.message)}</div>`; }
        };
      };
    }

    S.refs = await masterLoadRefs(cfg);
    if (!alive()) return;
    load(false);
  };
}

const KONTAK = [
  { key: "kode", label: "Kode", req: true, createOnly: false },
  { key: "nama", label: "Nama", req: true },
  { key: "alamat", label: "Alamat", type: "area", nullable: true },
  { key: "kota", label: "Kota", nullable: true },
  { key: "telepon", label: "Telepon", nullable: true },
  { key: "email", label: "Email", type: "email", nullable: true },
  { key: "npwp", label: "NPWP", nullable: true },
];
const termRef = { path: "/payment-terms?aktif=1" };

const MASTERS = {
  pelanggan: {
    path: "/customers", noun: "pelanggan", paged: true, toggle: true, deletable: false,
    cols: [{ key: "kode", label: "Kode" }, { key: "nama", label: "Nama" }, { key: "kota", label: "Kota" },
      { key: "piutang_berjalan", label: "Piutang", num: true, fmt: v => rp(+v || 0) }],
    fields: KONTAK.concat([
      { key: "limit_kredit", label: "Limit kredit (Rp)", type: "num", min: 0, def: "0" },
      { key: "payment_term_id", label: "Syarat pembayaran", type: "int", ref: termRef, nullable: true },
      { key: "level_harga", label: "Level harga (1-10)", type: "int", min: 1, max: 10, def: 1 },
    ]),
    info: r => (+r.limit_kredit > 0 && +r.piutang_berjalan > +r.limit_kredit) ? `<div class="warn">Piutang berjalan melebihi limit kredit.</div>` : "",
    note: "Pelanggan dan pemasok berbagi satu data kontak. Pelanggan yang sudah dipakai transaksi hanya bisa dinonaktifkan.",
  },
  pemasok: {
    path: "/suppliers", noun: "pemasok", paged: true, toggle: true, deletable: false,
    cols: [{ key: "kode", label: "Kode" }, { key: "nama", label: "Nama" }, { key: "kota", label: "Kota" },
      { key: "utang_berjalan", label: "Utang", num: true, fmt: v => rp(+v || 0) }],
    fields: KONTAK.concat([
      { key: "payment_term_id", label: "Syarat pembayaran", type: "int", ref: termRef, nullable: true },
      { key: "no_rekening", label: "No rekening", nullable: true },
    ]),
    note: "Pelanggan dan pemasok berbagi satu data kontak. Pemasok yang sudah dipakai transaksi hanya bisa dinonaktifkan.",
  },
  syarat_pembayaran: {
    path: "/payment-terms", noun: "syarat pembayaran", paged: false, toggle: true, deletable: true,
    cols: [{ key: "nama", label: "Nama" }, { key: "jumlah_hari", label: "Hari", num: true },
      { key: "diskon_persen", label: "Diskon %", num: true, fmt: v => esc(+v || 0) }, { key: "diskon_hari", label: "Diskon hari", num: true }],
    fields: [
      { key: "nama", label: "Nama", req: true },
      { key: "jumlah_hari", label: "Jumlah hari", type: "int", req: true, min: 0, max: 3650, def: 0 },
      { key: "diskon_persen", label: "Diskon pelunasan cepat %", type: "num", min: 0, max: 100, def: "0" },
      { key: "diskon_hari", label: "Berlaku (hari)", type: "int", min: 0, max: 3650, def: 0 },
    ],
    note: "Syarat yang sudah dipakai dokumen sebaiknya dinonaktifkan, bukan dihapus.",
  },
};

/* ---- Langkah 3b: master stok, akun perkiraan, pajak ---- */
const kn = x => x.kode + " - " + x.nama;
const refAkun = { path: "/accounts?postable=1", label: kn };
const refPajak = { path: "/tax-codes?aktif=1", label: kn };
const KODE_NAMA = [{ key: "kode", label: "Kode", req: true }, { key: "nama", label: "Nama", req: true }];
const STOCK_NOTE = "Data yang sudah dipakai transaksi tidak bisa dihapus, hanya dinonaktifkan.";
const stockBase = { paged: true, toggle: true, deletable: true, fullUpdate: true, aktifParam: "is_aktif", cols: [{ key: "kode", label: "Kode" }, { key: "nama", label: "Nama" }], note: STOCK_NOTE };
const TIPE_AKUN = [["asset", "Aset"], ["liability", "Kewajiban"], ["equity", "Ekuitas"], ["revenue", "Pendapatan"], ["cogs", "HPP"], ["expense", "Beban"], ["other_income", "Pendapatan lain"], ["other_expense", "Beban lain"]];
const labelTipe = v => esc((TIPE_AKUN.find(t => t[0] === v) || [0, v])[1]);

Object.assign(MASTERS, {
  satuan_barang: { ...stockBase, path: "/units", noun: "satuan", fields: KODE_NAMA },
  merek_barang: { ...stockBase, path: "/brands", noun: "merek", fields: KODE_NAMA },
  gudang: {
    ...stockBase, path: "/warehouses", noun: "gudang",
    cols: [{ key: "kode", label: "Kode" }, { key: "nama", label: "Nama" }, { key: "is_default", label: "Utama", fmt: v => v ? "✓" : "" }],
    fields: KODE_NAMA.concat([{ key: "alamat", label: "Alamat", type: "area", nullable: true }, { key: "is_default", label: "Gudang utama", type: "bool", def: false }]),
  },
  barang_jasa: {
    ...stockBase, path: "/items", noun: "barang/jasa",
    cols: [{ key: "kode", label: "Kode" }, { key: "nama", label: "Nama" }, { key: "tipe", label: "Jenis", fmt: v => v === "jasa" ? "Jasa" : "Barang" },
      { key: "harga_jual", label: "Harga jual", num: true, fmt: v => v == null ? "" : rp(+v) }],
    fields: KODE_NAMA.concat([
      { key: "tipe", label: "Jenis", req: true, opts: [["barang", "Barang"], ["jasa", "Jasa"]], def: "barang" },
      { key: "unit_id", label: "Satuan dasar", type: "int", req: true, ref: { path: "/units?limit=100", label: kn } },
      { key: "category_id", label: "Kategori", type: "int", nullable: true, ref: { path: "/item-categories?limit=100", label: kn } },
      { key: "brand_id", label: "Merek", type: "int", nullable: true, ref: { path: "/brands?limit=100", label: kn } },
      { key: "harga_jual", label: "Harga jual (Rp)", type: "num", min: 0, nullable: true },
      { key: "harga_beli_standar", label: "Harga beli standar (Rp)", type: "num", min: 0, nullable: true },
      { key: "barcode", label: "Barcode", nullable: true },
      { key: "tax_code_jual_id", label: "Pajak penjualan", type: "int", nullable: true, ref: refPajak },
      { key: "tax_code_beli_id", label: "Pajak pembelian", type: "int", nullable: true, ref: refPajak },
      { key: "akun_persediaan_id", label: "Akun persediaan", type: "int", nullable: true, ref: refAkun },
      { key: "akun_penjualan_id", label: "Akun penjualan", type: "int", nullable: true, ref: refAkun },
      { key: "akun_hpp_id", label: "Akun HPP", type: "int", nullable: true, ref: refAkun },
    ]),
  },
  akun_perkiraan: {
    path: "/accounts", noun: "akun", paged: false, toggle: true, deletable: true,
    cols: [{ key: "kode", label: "Kode" }, { key: "nama", label: "Nama" }, { key: "tipe", label: "Tipe", fmt: labelTipe },
      { key: "is_header", label: "", fmt: v => v ? '<span class="chip kk">Induk</span>' : "" }],
    fields: [
      { key: "kode", label: "Kode", req: true },
      { key: "nama", label: "Nama", req: true },
      { key: "tipe", label: "Tipe", req: true, opts: TIPE_AKUN, def: "asset" },
      { key: "parent_id", label: "Akun induk", type: "int", nullable: true, ref: { path: "/accounts", label: kn, filter: x => x.is_header } },
      { key: "is_header", label: "Akun induk (tidak bisa dijurnal)", type: "bool", def: false },
      { key: "saldo_normal", label: "Saldo normal", opts: [["debit", "Debit"], ["credit", "Kredit"]] },
    ],
    note: "Akun yang sudah dipakai jurnal hanya bisa dinonaktifkan. Saldo awal belum diatur di layar ini.",
  },
  pajak: {
    path: "/tax-codes", noun: "kode pajak", paged: false, toggle: true, deletable: true,
    cols: [{ key: "kode", label: "Kode" }, { key: "nama", label: "Nama" }, { key: "jenis", label: "Jenis", fmt: v => esc({ ppn: "PPN", ppnbm: "PPnBM", pph: "PPh" }[v] || v) },
      { key: "tarif", label: "Tarif", num: true, fmt: v => esc(+(parseFloat(v) * 100).toFixed(4)) + "%" }, { key: "berlaku_dari", label: "Berlaku dari" }],
    fields: [
      { key: "kode", label: "Kode", req: true, createOnly: true },
      { key: "nama", label: "Nama", req: true },
      { key: "jenis", label: "Jenis", req: true, createOnly: true, opts: [["ppn", "PPN"], ["ppnbm", "PPnBM"], ["pph", "PPh"]], def: "ppn" },
      { key: "tarif", label: "Tarif (%)", type: "pct", req: true, min: 0, max: 100, def: "" },
      { key: "dpp_pembilang", label: "DPP pembilang", type: "int", min: 1, max: 65535, def: 1 },
      { key: "dpp_penyebut", label: "DPP penyebut", type: "int", min: 1, max: 65535, def: 1 },
      { key: "berlaku_dari", label: "Berlaku dari", type: "date", req: true },
      { key: "berlaku_sampai", label: "Berlaku sampai", type: "date", nullable: true },
      { key: "akun_penjualan_id", label: "Akun pajak penjualan", type: "int", nullable: true, ref: refAkun },
      { key: "akun_pembelian_id", label: "Akun pajak pembelian", type: "int", nullable: true, ref: refAkun },
    ],
    note: "Kode dan jenis tidak bisa diubah. Untuk tarif baru, buat versi baru dengan tanggal berlaku berbeda; dokumen lama tetap memakai tarif lamanya.",
  },
});

Object.keys(MASTERS).forEach(slug => { SCREENS[slug] = masterScreen(MASTERS[slug]); });
