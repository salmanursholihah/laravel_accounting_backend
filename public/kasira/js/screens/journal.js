/* Langkah 4d-1: Jurnal Umum.
   Beda dengan dokumen lain: TIDAK ada draft. POST /journals langsung memposting jurnal (hak "post"), sehingga
   form ini punya satu tombol "Posting jurnal". Void hanya untuk jurnal bertipe umum/koreksi; jurnal otomatis
   dibatalkan lewat Void dokumen sumbernya (aturan backend). Jurnal koreksi (hak khusus) belum dibuat di layar ini.
   Dimuat setelah invoice.js dan payment.js (memakai invRef, invToday, u4, f4, payInt). */

const JTOK = { n: 0 };
const JTIPE = { general: "Umum", auto: "Otomatis", correction: "Koreksi", opening: "Saldo awal", closing: "Penutup", reversal: "Pembalik" };
const JSTAT = { posted: ["Diposting", "ok"], void: ["Void", "er"], pembalik: ["Pembalik", "gr"] };
const jBadge = s => { const x = JSTAT[s] || [s, "gr"]; return `<span class="bd ${x[1]}">${esc(x[0])}</span>`; };
const jLine = () => ({ account_id: "", debit: "", kredit: "", memo: "", contact_id: "" });
const jTot = L => L.reduce((a, l) => ({ d: a.d + Math.max(u4(l.debit), 0), k: a.k + Math.max(u4(l.kredit), 0) }), { d: 0, k: 0 });

function journalScreen() {
  return async function (f, m, j) {
    const tok = ++JTOK.n, alive = () => tok === JTOK.n && cur && cur[0] === m && cur[1] === j;
    const S = { view: "list", rows: [], next: null, q: "", tipe: "", dari: "", sampai: "", loading: true, err: null, D: null, F: null, R: null, busy: false };
    journalScreen.last = S; S.openDoc = openDoc; S.openNew = openNew;  // dipakai pengujian
    const can_ = a => can(f.slug, a);

    async function refs() {
      if (S.R) return S.R;
      const [acc, cus, sup] = await Promise.all([invRef("/accounts?postable=1"), invRef("/customers?limit=100"), invRef("/suppliers?limit=100")]);
      return S.R = { acc, cus, sup };
    }
    const kn = x => (x.kode ? x.kode + " - " : "") + x.nama;

    /* ---------- daftar ---------- */
    async function loadList(more) {
      S.loading = true; S.err = null;
      try {
        const qs = new URLSearchParams(); qs.set("limit", "50");
        if (S.q) qs.set("q", S.q); if (S.tipe) qs.set("tipe", S.tipe); if (S.dari) qs.set("dari", S.dari); if (S.sampai) qs.set("sampai", S.sampai);
        if (more && S.next) qs.set("before_id", S.next);
        const r = await API.get("/journals?" + qs);
        if (!alive()) return;
        S.rows = more ? S.rows.concat(r.data) : r.data; S.next = r.next_before_id || null;
      } catch (e) { if (!alive()) return; S.err = e.message || "Gagal memuat data."; }
      S.loading = false; drawList();
    }

    function drawList() {
      if (!alive() || S.view !== "list") return;
      pg.innerHTML = head(f, m, can_("post") ? `<button class="btn p" id="ad">+ Jurnal baru</button>` : "") + `<div class="card tw"><div class="fl">
        <select id="ft" aria-label="Tipe"><option value="">Semua tipe</option>${Object.keys(JTIPE).map(k => `<option value="${k}" ${S.tipe === k ? "selected" : ""}>${esc(JTIPE[k])}</option>`).join("")}</select>
        <input id="fq" placeholder="Cari nomor…" value="${esc(S.q)}" style="flex:1;min-width:140px"><input id="fd" type="date" value="${esc(S.dari)}" aria-label="Dari"><input id="fe" type="date" value="${esc(S.sampai)}" aria-label="Sampai"></div>
        ${S.err ? `<div class="warn e">${esc(S.err)} <a class="lk" id="rt">Coba lagi</a></div>` : ""}
        <table><thead><tr><th>Nomor</th><th>Tanggal</th><th>Tipe</th><th>Memo</th><th class="num">Debit</th><th class="num">Kredit</th><th>Status</th></tr></thead><tbody>
        ${S.rows.map(d => `<tr class="row" data-id="${d.id}" tabindex="0"><td><b>${esc(d.nomor || "-")}</b></td><td>${esc(d.tanggal)}</td><td>${esc(JTIPE[d.tipe] || d.tipe)}</td><td>${esc(d.memo || "")}</td><td class="num">${rp(u4(d.total_debit) / 1e4)}</td><td class="num">${rp(u4(d.total_kredit) / 1e4)}</td><td>${jBadge(d.status)}</td></tr>`).join("")}</tbody></table>
        ${!S.rows.length && !S.loading && !S.err ? `<div class="empty">Belum ada jurnal${S.q || S.tipe || S.dari || S.sampai ? " yang cocok" : ""}.</div>` : ""}${S.loading ? `<div class="empty">Memuat…</div>` : ""}
        <div class="ft"><span>${S.rows.length} jurnal</span>${S.next ? `<a class="lk" id="more">Muat lainnya</a>` : "<span></span>"}</div></div>`;
      const ad = $("#ad"); if (ad) ad.onclick = () => openNew();
      pg.querySelectorAll(".row").forEach(r => { const o = () => openDoc(+r.dataset.id); r.onclick = o; r.onkeydown = e => { if (e.key === "Enter") o(); }; });
      const re = () => loadList(false);
      $("#ft").onchange = e => { S.tipe = e.target.value; re(); }; $("#fd").onchange = e => { S.dari = e.target.value; re(); }; $("#fe").onchange = e => { S.sampai = e.target.value; re(); };
      $("#fq").oninput = e => { S.q = e.target.value; clearTimeout(journalScreen.t); journalScreen.t = setTimeout(() => re().then(() => { const q = $("#fq"); if (q) { q.focus(); q.setSelectionRange(S.q.length, S.q.length); } }), 300); };
      const rt = $("#rt"); if (rt) rt.onclick = re; const mo = $("#more"); if (mo) mo.onclick = () => loadList(true);
    }

    /* ---------- detail ---------- */
    async function openDoc(id) {
      try {
        const r = await API.get("/journals/" + id);
        if (!alive()) return;
        S.view = "detail"; S.D = r.data; drawDetail();
      } catch (e) { toast(e.message); }
    }

    function drawDetail() {
      if (!alive()) return;
      const D = S.D, canVoid = can_("void") && D.status === "posted" && (D.tipe === "general" || D.tipe === "correction");
      const td = D.lines.reduce((a, l) => a + u4(l.debit), 0), tk = D.lines.reduce((a, l) => a + u4(l.kredit), 0);
      pg.innerHTML = head(f, m, `<button class="btn" id="bk">Kembali</button> ${canVoid ? `<button class="btn d" id="vd">Void</button>` : ""}`) +
        `<div class="card"><h3 style="margin:0 0 10px">Jurnal ${esc(D.nomor)} ${jBadge(D.status)} <span class="chip gr">${esc(JTIPE[D.tipe] || D.tipe)}</span></h3>
        ${D.tipe === "auto" ? `<div class="warn i">Jurnal ini dibuat otomatis oleh dokumen${D.source_type ? " (" + esc(D.source_type) + (D.source_id ? " #" + esc(D.source_id) : "") + ")" : ""}. Untuk membatalkannya, Void dokumen sumbernya.</div>` : ""}
        ${D.dibalik_dari_nomor ? `<div class="warn i">Jurnal pembalik dari ${esc(D.dibalik_dari_nomor)}.</div>` : ""}
        ${D.dibalik_oleh_nomor ? `<div class="warn e">Dibalik oleh ${esc(D.dibalik_oleh_nomor)}.</div>` : ""}
        <div class="gh" style="margin-top:10px"><label><span>Tanggal</span><input value="${esc(D.tanggal)}" disabled></label><label style="grid-column:span 2"><span>Memo</span><input value="${esc(D.memo || "")}" disabled></label></div></div>
        <div class="card"><h3 style="margin:0 0 8px">Rincian</h3><div class="tw"><table><thead><tr><th>Akun</th><th>Memo</th><th class="num">Debit</th><th class="num">Kredit</th></tr></thead><tbody>
        ${D.lines.map(l => `<tr><td>${esc((l.kode ? l.kode + " - " : "") + l.nama)}</td><td>${esc(l.memo || "")}</td><td class="num">${u4(l.debit) ? rp(u4(l.debit) / 1e4) : ""}</td><td class="num">${u4(l.kredit) ? rp(u4(l.kredit) / 1e4) : ""}</td></tr>`).join("")}
        <tr><td colspan="2"><b>Total</b></td><td class="num"><b>${rp(td / 1e4)}</b></td><td class="num"><b>${rp(tk / 1e4)}</b></td></tr></tbody></table></div></div><div id="me"></div>`;
      $("#bk").onclick = () => { S.view = "list"; loadList(false); };
      const vd = $("#vd"); if (vd) vd.onclick = askVoid;
    }

    function askVoid() {
      const D = S.D;
      dlg(`<h3>Void jurnal ${esc(D.nomor)}?</h3><p style="color:var(--mut);margin:0 0 8px">Jurnal pembalik dibuat dan jurnal asli ditandai void. Alasan wajib diisi.</p><input id="rs" maxlength="400" placeholder="Alasan pembatalan" style="width:100%"><div id="de"></div><div style="text-align:right;margin-top:10px"><button class="btn" id="x">Batal</button> <button class="btn d" id="ok" disabled>Void</button></div>`);
      $("#x").onclick = cl; $("#rs").oninput = e => { $("#ok").disabled = !e.target.value.trim(); };
      $("#ok").onclick = async () => {
        $("#ok").disabled = true;
        try { const r = await API.post("/journals/" + D.id + "/void", { alasan: $("#rs").value.trim() }); cl(); toast("Dibatalkan, jurnal pembalik " + (r.data.nomor || "") + " dibuat"); openDoc(D.id); }
        catch (e) { $("#ok").disabled = false; $("#de").innerHTML = `<div class="warn e">${esc(e.first ? e.first() : e.message)}</div>`; }
      };
    }

    /* ---------- form baru ---------- */
    async function openNew() { await refs(); if (!alive()) return; S.view = "form"; S.F = { tanggal: invToday(), memo: "", lines: [jLine(), jLine()] }; drawForm(); }

    const opt = (list, val, lb) => `<option value="">-</option>` + list.map(x => `<option value="${esc(x.id)}" ${String(x.id) === String(val) ? "selected" : ""}>${esc(lb(x))}</option>`).join("");
    const contactOpt = val => { const R = S.R;
      const g = (t, l) => l.length ? `<optgroup label="${t}">${l.map(x => `<option value="${esc(x.contact_id)}" ${String(x.contact_id) === String(val) ? "selected" : ""}>${esc(kn(x))}</option>`).join("")}</optgroup>` : "";
      return `<option value="">-</option>` + g("Pelanggan", R.cus.list) + g("Pemasok", R.sup.list); };

    function drawForm() {
      if (!alive()) return;
      const F = S.F, R = S.R, ag = R.acc.ok ? "" : "disabled", cg = (R.cus.ok || R.sup.ok) ? "" : "disabled";
      pg.innerHTML = head(f, m, `<button class="btn" id="bk">Kembali</button> <button class="btn p" id="po">Posting jurnal</button>`) +
        `<div class="card"><h3 style="margin:0 0 10px">Jurnal baru</h3><div class="warn i">Jurnal umum langsung diposting dan tidak punya draft. Koreksi dilakukan dengan Void atau jurnal koreksi.</div><div id="me"></div>
        ${!R.acc.ok ? `<div class="warn">Daftar akun tidak bisa dimuat (tanpa hak lihat); pilihan akun dikunci.</div>` : ""}
        <div class="gh" style="margin-top:10px"><label><span>Tanggal *</span><input type="date" data-f="tanggal" value="${esc(F.tanggal)}"></label><label style="grid-column:span 2"><span>Memo</span><input data-f="memo" value="${esc(F.memo)}"></label></div></div>
        <div class="card"><h3 style="margin:0 0 8px">Rincian</h3><div class="tw"><table class="ln"><thead><tr><th>Akun *</th><th>Memo baris</th><th>Kontak</th><th class="num">Debit</th><th class="num">Kredit</th><th></th></tr></thead><tbody>
        ${F.lines.map((l, i) => `<tr>
          <td><select data-l="${i}:account_id" ${ag}>${opt(R.acc.list, l.account_id, kn)}</select></td>
          <td><input data-l="${i}:memo" value="${esc(l.memo)}"></td>
          <td><select data-l="${i}:contact_id" ${cg}>${contactOpt(l.contact_id)}</select></td>
          <td><input data-l="${i}:debit" type="number" min="0" step="any" value="${esc(l.debit)}" style="width:130px;text-align:right"></td>
          <td><input data-l="${i}:kredit" type="number" min="0" step="any" value="${esc(l.kredit)}" style="width:130px;text-align:right"></td>
          <td>${F.lines.length < 3 ? "" : `<button class="btn" data-rm="${i}" aria-label="Hapus baris">✕</button>`}</td></tr>`).join("")}</tbody></table></div>
        <p style="margin:10px 0 0"><button class="btn" id="al">+ Tambah baris</button></p><div id="bal" style="max-width:340px;margin-left:auto;margin-top:12px"></div></div>`;
      bindForm(); drawBal();
    }

    function drawBal() {
      const b = $("#bal"); if (!b) return;
      const t = jTot(S.F.lines), sel = t.d - t.k;
      b.innerHTML = `<div class="sum"><div><span>Total debit</span><b class="num">${rp(t.d / 1e4)}</b></div><div><span>Total kredit</span><b class="num">${rp(t.k / 1e4)}</b></div><div><span>Selisih</span><b class="num" style="${sel ? "color:var(--bad)" : ""}">${rp(Math.abs(sel) / 1e4)}</b></div></div>${sel ? `<div class="warn e">Jurnal belum seimbang.</div>` : t.d ? `<div class="warn i">Seimbang.</div>` : ""}`;
    }

    function bindForm() {
      pg.querySelectorAll("[data-f]").forEach(el => el.oninput = () => { S.F[el.dataset.f] = el.value; });
      pg.querySelectorAll("[data-l]").forEach(el => el[el.tagName === "SELECT" ? "onchange" : "oninput"] = () => {
        const [i, k] = el.dataset.l.split(":"), l = S.F.lines[+i]; l[k] = el.value;
        if ((k === "debit" || k === "kredit") && el.value !== "") {   // satu baris hanya satu sisi: kosongkan sisi lawan
          const o = k === "debit" ? "kredit" : "debit"; l[o] = ""; const oe = pg.querySelector(`[data-l="${i}:${o}"]`); if (oe) oe.value = "";
        }
        drawBal();
      });
      pg.querySelectorAll("[data-rm]").forEach(b => b.onclick = () => { S.F.lines.splice(+b.dataset.rm, 1); drawForm(); });
      $("#al").onclick = () => { S.F.lines.push(jLine()); drawForm(); };
      $("#bk").onclick = () => { S.view = "list"; loadList(false); };
      $("#po").onclick = post;
    }

    const showErr = e => { const el = $("#me"); const msg = e.first ? e.first() : e.message; el ? el.innerHTML = `<div class="warn e">${esc(msg)}</div>` : toast(msg); };

    async function post() {
      if (S.busy) return; const F = S.F;
      if (!F.tanggal) return showErr(new Error("Tanggal wajib diisi."));
      for (let i = 0; i < F.lines.length; i++) {
        const l = F.lines[i], d = u4(l.debit), k = u4(l.kredit);
        if (!l.account_id) return showErr(new Error("Baris " + (i + 1) + ": akun wajib dipilih."));
        if (d > 0 && k > 0) return showErr(new Error("Baris " + (i + 1) + ": satu baris hanya boleh berisi debit atau kredit."));
        if (!(d > 0) && !(k > 0)) return showErr(new Error("Baris " + (i + 1) + ": isi debit atau kredit."));
      }
      const t = jTot(F.lines);
      if (t.d !== t.k) return showErr(new Error("Jurnal tidak seimbang: selisih " + rp(Math.abs(t.d - t.k) / 1e4) + "."));
      S.busy = true;
      try {
        const body = { tanggal: F.tanggal, memo: F.memo.trim() || null, lines: F.lines.map(l => ({ account_id: payInt(l.account_id), debit: u4(l.debit) > 0 ? f4(u4(l.debit)) : null, kredit: u4(l.kredit) > 0 ? f4(u4(l.kredit)) : null, memo: l.memo.trim() || null, contact_id: payInt(l.contact_id) })) };
        const r = await API.post("/journals", body);
        toast("Jurnal " + (r.data.nomor || "") + " diposting"); S.busy = false; openDoc(r.data.id);
      } catch (e) { S.busy = false; showErr(e); }
    }

    pg.innerHTML = head(f, m, "") + `<div class="card"><div class="empty">Memuat…</div></div>`;
    loadList(false);
  };
}
SCREENS["jurnal_umum"] = journalScreen();
