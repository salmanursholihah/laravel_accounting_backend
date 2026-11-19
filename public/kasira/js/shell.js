/* Kasira shell: rail + flyout, pencarian, tema, router layar, masuk dan pilih perusahaan (API asli). */

/* ---------- status aplikasi & izin ---------- */
const APP = { user: null, companies: [], company: null, perms: new Set(), byFeature: {}, grants: {} };

// Slug fitur = kunci izin di backend (config/rbac.php). Hanya "Toko Aplikasi" yang namanya berbeda.
const SLUG_OVR = { "Toko Aplikasi": "accurate_store" };
const slugOf = n => SLUG_OVR[n] || n.toLowerCase().replace(/[^a-z0-9]+/g, "_").replace(/^_|_$/g, "");
FE.forEach(a => a && a.forEach(f => f.slug = slugOf(f.n)));

const can = (slug, aksi = "view") => APP.perms.has(slug + ":" + aksi);
const canAny = m => m == 0 || FE[m].some(f => can(f.slug));      // modul tampil bila ada satu fitur yang boleh dilihat
const initials = n => (String(n).split(/\s+/).filter(w => w && !/^(PT|CV|UD)$/i.test(w)).slice(0, 2).map(w => w[0]).join("") || String(n).slice(0, 2)).toUpperCase();
const findFeature = slug => { for (let m = 1; m < FE.length; m++) { const j = FE[m].findIndex(f => f.slug == slug); if (j >= 0) return [m, j]; } return null; };

/* ---------- registri layar: diisi pada langkah berikutnya ----------
   SCREENS[slug] = fn(f,m,j)   layar khusus satu fitur (mis. faktur_penjualan)
   PATTERN[pola] = fn(f,m,j)   layar generik per pola D/A/M/R/W/S/G/I
   Bila keduanya kosong, tampil layar "belum tersambung". */
const SCREENS = {}, PATTERN = {};

/* ---------- rail + flyout ---------- */
$("#rail").innerHTML = '<div class="logo">K</div>' + MD.map((m, i) => `<button class="rb" data-i="${i}"><svg viewBox="0 0 24 24"><path d="${m[1]}"/></svg>${m[0]}</button>`).join("");
function rail(i) { $("#rail").querySelectorAll(".rb").forEach((b, j) => b.classList.toggle("on", j == i)); }
function fly(i) {
  const L = FE[i]; rail(i);
  $("#fly").innerHTML = `<h2>${MD[i][0]}</h2><div class="bar"></div>` + Object.keys(TY).filter(k => L.some(f => f.t == k && can(f.slug))).map(k =>
    `<div class="grp"><h3>${TY[k][0]}</h3><div class="cards">${L.map((f, j) => f.t == k && can(f.slug) ? `<button class="fc ${TY[k][1]}" data-m="${i}" data-j="${j}">${f.n}${f.p == "I" ? "<em>Segera</em>" : ""}</button>` : "").join("")}</div></div>`).join("");
  $("#fly").classList.add("open"); $("#scrim").classList.add("on");
}
const closeF = () => { $("#fly").classList.remove("open"); $("#scrim").classList.remove("on"); };
$("#rail").onclick = e => { const b = e.target.closest(".rb"); if (!b) return; +b.dataset.i ? fly(+b.dataset.i) : (closeF(), home()); };
$("#fly").onclick = e => { const b = e.target.closest(".fc"); if (b) { closeF(); show(+b.dataset.m, +b.dataset.j); } };
$("#scrim").onclick = closeF;
document.addEventListener("keydown", e => {
  if (e.key == "Escape") { closeF(); cl(); $("#qd").classList.remove("on"); }
  if ((e.ctrlKey || e.metaKey) && e.key == "k") { e.preventDefault(); $("#q").focus(); }
});

/* ---------- pencarian fitur (hanya yang boleh dilihat) ---------- */
$("#q").oninput = e => {
  const v = e.target.value.toLowerCase().trim(), d = $("#dd");
  if (!v) { d.style.display = "none"; return; }
  const r = [];
  FE.forEach((a, m) => a && a.forEach((f, j) => f.n.toLowerCase().includes(v) && can(f.slug) && r.push([m, j, f])));
  d.innerHTML = r.slice(0, 10).map(x => `<button data-m="${x[0]}" data-j="${x[1]}">${x[2].n}<small>${MD[x[0]][0]}</small></button>`).join("") || '<div class="empty">Tidak ditemukan</div>';
  d.style.display = "block";
};
$("#dd").onclick = e => { const b = e.target.closest("button"); if (b) { $("#dd").style.display = "none"; $("#q").value = ""; show(+b.dataset.m, +b.dataset.j); } };

/* ---------- tema, dialog, buat cepat ---------- */
$("#th").onclick = () => { const r = document.documentElement, d = r.dataset.theme == "dark" || (!r.dataset.theme && matchMedia("(prefers-color-scheme:dark)").matches); r.dataset.theme = d ? "light" : "dark"; };
$("#ov").onclick = e => { if (e.target.id == "ov") cl(); };
$("#mk").onclick = () => $("#qd").classList.toggle("on");
$("#qd").onclick = e => { const b = e.target.closest("button"); if (b) { $("#qd").classList.remove("on"); show(+b.dataset.m, +b.dataset.j); } };
$("#bl").onclick = () => toast("Kotak persetujuan disambungkan pada langkah berikutnya");
$("#co").onclick = () => gate(1);
$("#av").onclick = () => {
  dlg(`<h3>${esc(APP.user.name)}</h3><p style="color:var(--mut);margin:0 0 12px">${esc(APP.user.email)}<br>Peran: ${esc(APP.company.role)} di ${esc(APP.company.nama)}</p><div style="display:flex;gap:8px;justify-content:flex-end"><button class="btn" id="dc">Tutup</button><button class="btn d" id="lo">Keluar</button></div>`);
  $("#dc").onclick = cl; $("#lo").onclick = logout;
};

const QK = ["Faktur Penjualan", "Penerimaan Penjualan", "Faktur Pembelian", "Pembayaran", "Jurnal Umum"];
function buildQuick() {
  const items = QK.map(n => { let r; FE.forEach((a, m) => a && a.forEach((f, j) => { if (f.n == n && !r) r = [m, j, f]; })); return r; }).filter(r => r && can(r[2].slug, "create"));
  $("#qd").innerHTML = items.map(r => `<button data-m="${r[0]}" data-j="${r[1]}">${r[2].n}</button>`).join("");
  $("#mk").style.display = items.length ? "" : "none";
}

/* ---------- router layar ---------- */
function show(m, j, fromHash) {
  const f = FE[m][j]; cur = [m, j]; rail(m);
  if (!fromHash && location.hash != "#/" + f.slug) history.pushState(null, "", "#/" + f.slug);
  if (!can(f.slug)) return denied(f, m);
  (SCREENS[f.slug] || PATTERN[f.p] || placeholder)(f, m, j);
}
function route() {
  const h = location.hash.match(/^#\/([a-z0-9_]+)$/), hit = h && findFeature(h[1]);
  hit ? show(hit[0], hit[1], true) : home(true);
}
window.addEventListener("popstate", () => APP.company && route());

function denied(f, m) {
  pg.innerHTML = head(f, m) + `<div class="card"><span class="bd er">Tidak ada akses</span><p>Peran ${esc(APP.company.role)} tidak memiliki hak melihat fitur ini. Hubungi Owner perusahaan bila Anda memerlukannya.</p></div>`;
}
const AKSI = { view: "Lihat", create: "Tambah", update: "Ubah", delete: "Hapus", print: "Cetak", export: "Ekspor", post: "Posting", void: "Batalkan" };
function placeholder(f, m) {
  const acts = APP.byFeature[f.slug] || [], own = APP.grants[f.slug + ":view"] && APP.grants[f.slug + ":view"].hanya_miliknya;
  pg.innerHTML = head(f, m) + `<div class="card"><span class="bd wn">Belum tersambung</span>
  <p style="margin:10px 0">Layar ini belum dipindahkan dari prototipe dan belum terhubung ke backend. Kerangka, menu, dan hak akses sudah aktif.</p>
  <h3>Hak akses peran ${esc(APP.company.role)} pada fitur ini</h3>
  <p style="margin:0 0 8px">${acts.map(a => `<span class="chip kk">${AKSI[a] || esc(a)}</span>`).join(" ") || "-"}${own ? " <small style=\"color:var(--mut)\">(hanya dokumen miliknya untuk posting)</small>" : ""}</p>
  ${f.f.length ? `<h3>Isian di prototipe</h3><p style="margin:0 0 8px;color:var(--mut)">${f.f.map(x => esc(x.split(":")[0])).join(" · ")}</p>` : ""}
  ${f.j ? `<h3>Catatan jurnal / langkah</h3><p style="margin:0;color:var(--mut)">${esc(f.j)}</p>` : ""}</div>`;
}

/* ---------- Beranda: dashboard.js menggambar data asli; bila belum dimuat, tampil ringkasan perusahaan ---------- */
function home(fromHash) {
  rail(0); cur = 0;
  if (!fromHash && location.hash) history.pushState(null, "", location.pathname + location.search);
  typeof renderDashboard == "function" ? renderDashboard() : homeBasic();
}
function homeBasic() {
  const c = APP.company;
  pg.innerHTML = `<div class="hd"><div><h1>Beranda</h1><div class="crumb">${esc(c.nama)} · peran ${esc(c.role)}</div></div></div>`;
}

/* ---------- masuk, pilih perusahaan, buat perusahaan ---------- */
function resetSession() { APP.user = null; APP.companies = []; APP.company = null; APP.perms = new Set(); APP.byFeature = {}; APP.grants = {}; API.setCompany(null); }
async function logout() {
  try { await API.post("/auth/logout", undefined, { silent: true }); } catch (e) { /* sesi mungkin sudah berakhir */ }
  cl(); closeF(); resetSession(); history.replaceState(null, "", location.pathname); gate(0);
}
const errBox = (el, err) => { $(el).innerHTML = `<div class="warn e">${esc(err.status == 429 ? "Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi." : err.first ? err.first() : "Tidak dapat menghubungi server.")}</div>`; };

function gate(step, msg) {
  let g = $("#gate");
  if (!g) { g = document.createElement("div"); g.id = "gate"; g.className = "gate"; document.body.appendChild(g); }
  const note = msg ? `<div class="warn">${esc(msg)}</div>` : "";
  if (step == 0) {
    g.innerHTML = `<form class="bx" id="lf" novalidate><div class="lg">K</div><h2>Masuk ke Kasira</h2><p>Akuntansi untuk beberapa perusahaan dalam satu akun.</p>${note}
      <label>Email<input id="em" type="email" autocomplete="username" required></label>
      <label>Kata sandi<input id="pw" type="password" autocomplete="current-password" required></label>
      <div id="ge"></div><button class="btn p" id="go" type="submit">Masuk</button></form>`;
    $("#em").focus();
    $("#lf").onsubmit = async e => {
      e.preventDefault(); const b = $("#go"); b.disabled = true; b.textContent = "Memeriksa…"; $("#ge").innerHTML = "";
      try {
        await API.csrf();
        const r = await API.post("/auth/login", { email: $("#em").value.trim(), password: $("#pw").value }, { silent: true });
        APP.user = r.data; APP.companies = r.data.companies; afterLogin();
      } catch (err) { errBox("#ge", err); b.disabled = false; b.textContent = "Masuk"; }
    };
  } else if (step == 1) {
    g.innerHTML = `<div class="bx"><div class="lg">K</div><h2>Pilih perusahaan</h2><p>Hak akses mengikuti peran ${esc(APP.user.name)} di tiap perusahaan.</p>${note}
      ${APP.companies.map((c, i) => `<button class="cp" data-i="${i}"><i>${esc(initials(c.nama))}</i><span><b>${esc(c.nama)}</b><small>Peran: ${esc(c.role)}</small></span><span class="rb2">${esc(c.role)}</span></button>`).join("") || '<p>Anda belum tergabung di perusahaan mana pun.</p>'}
      <div id="ge"></div>
      <button class="btn" id="nc">+ Buat perusahaan baru</button>
      ${APP.company ? '<button class="btn" id="bt">Batal</button>' : '<button class="btn" id="lo2">Keluar</button>'}</div>`;
    g.querySelectorAll(".cp").forEach(b => b.onclick = async () => {
      $("#ge").innerHTML = ""; b.disabled = true;
      try { await enter(APP.companies[+b.dataset.i]); } catch (err) { errBox("#ge", err); b.disabled = false; }
    });
    $("#nc").onclick = () => gate(2);
    if ($("#bt")) $("#bt").onclick = () => g.remove();
    if ($("#lo2")) $("#lo2").onclick = logout;
  } else {
    const hari = new Date().toISOString().slice(0, 10);
    g.innerHTML = `<form class="bx" id="cf" novalidate><div class="lg">K</div><h2>Buat perusahaan</h2><p>Anda otomatis menjadi Owner. Bagan akun, penomoran, dan pajak disiapkan otomatis.</p>${note}
      <label>Nama perusahaan<input id="cn" required></label>
      <label>NPWP (opsional)<input id="cnp"></label>
      <label>Status pajak<select id="ck"><option value="0">Non-PKP</option><option value="1">PKP</option></select></label>
      <label>Tanggal mulai pembukuan<input id="cd" type="date" value="${hari}" required></label>
      <label>Jenis usaha<select id="ct"><option value="dagang">Dagang</option><option value="jasa">Jasa</option><option value="manufaktur">Manufaktur</option></select></label>
      <div id="ge"></div><button class="btn p" id="cs" type="submit">Buat perusahaan</button><button class="btn" type="button" id="cb">Kembali</button></form>`;
    $("#cn").focus();
    $("#cb").onclick = () => APP.companies.length ? gate(1) : logout();
    $("#cf").onsubmit = async e => {
      e.preventDefault(); const b = $("#cs"); b.disabled = true; b.textContent = "Menyiapkan…"; $("#ge").innerHTML = "";
      try {
        const r = await API.post("/companies", { nama: $("#cn").value.trim(), npwp: $("#cnp").value.trim() || null, is_pkp: $("#ck").value == "1", tanggal_mulai_pembukuan: $("#cd").value, template: $("#ct").value });
        APP.companies = (await API.get("/me/companies")).data;
        await enter(APP.companies.find(c => c.public_id == r.data.public_id));
      } catch (err) { errBox("#ge", err); b.disabled = false; b.textContent = "Buat perusahaan"; }
    };
  }
}
function afterLogin() {
  const n = APP.companies.length;
  n == 0 ? gate(2) : n == 1 ? enter(APP.companies[0]).catch(err => gate(1, err.first())) : gate(1);
}

/* Pilih perusahaan: sinkronkan sesi server, muat profil + izin, lalu tampilkan aplikasi. */
async function enter(c) {
  await API.post("/session/company", { company_id: c.public_id });
  API.setCompany(c.public_id);
  const [co, pm] = await Promise.all([API.get("/company"), API.get("/company/permissions")]);
  APP.company = { ...c, ...co.data };
  APP.perms = new Set(); APP.byFeature = {}; APP.grants = {};
  pm.data.forEach(g => { const k = g.fitur + ":" + g.aksi; APP.perms.add(k); APP.grants[k] = g; (APP.byFeature[g.fitur] = APP.byFeature[g.fitur] || []).push(g.aksi); });

  $("#coi").textContent = initials(c.nama); $("#con").textContent = c.nama;
  $("#av").innerHTML = `<i>${esc(APP.user.name.slice(0, 1).toUpperCase())}</i><div><b>${esc(APP.user.name.split(" ")[0])}</b><small>${esc(APP.company.role)}</small></div>`;
  $("#rail").querySelectorAll(".rb").forEach(b => b.hidden = !canAny(+b.dataset.i));
  const bn = $("#bl .n"); if (bn) { bn.textContent = ""; bn.style.display = "none"; }
  buildQuick(); closeF(); cl();
  const g = $("#gate"); if (g) g.remove();
  route();
  toast("Perusahaan aktif: " + c.nama + " (" + APP.company.role + ")");
}

/* ---------- mulai ---------- */
async function boot() {
  API.onUnauth = () => { resetSession(); closeF(); cl(); gate(0, "Sesi berakhir, silakan masuk lagi."); };
  let me;
  try { me = (await API.get("/auth/me", { silent: true })).data; }
  catch (e) { return e.status == 401 ? gate(0) : gate(0, "Tidak dapat memuat akun: " + e.message); }
  APP.user = me; APP.companies = me.companies;
  const pick = me.companies.find(c => c.public_id == API.company) || (me.companies.length == 1 ? me.companies[0] : null);
  if (!pick) return gate(me.companies.length ? 1 : 2);
  try { await enter(pick); } catch (e) { if (e.status != 401) { API.setCompany(null); gate(1, e.first ? e.first() : "Gagal memuat perusahaan."); } }
}
// Mulai setelah semua skrip (termasuk dashboard.js dan layar lain) terpasang.
document.readyState == "loading" ? document.addEventListener("DOMContentLoaded", boot) : boot();
