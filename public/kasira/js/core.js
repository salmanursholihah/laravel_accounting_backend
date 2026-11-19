/* Pembantu dasar. Nama global ($, rp, pg, ST, toast, dlg, cl, head, bdg) sengaja SAMA dengan prototipe,
   sehingga kode layar dari prototipe bisa dipindahkan hampir tanpa ubahan pada langkah berikutnya. */
const $ = s => document.querySelector(s),
  rp = n => "Rp " + Math.round(n).toLocaleString("id-ID"),
  pg = $("#pg"),
  ST = {};
const esc = s => String(s == null ? "" : s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const toast = m => { const t = $("#ts"); t.textContent = m; t.style.display = "block"; clearTimeout(toast.t); toast.t = setTimeout(() => t.style.display = "none", 2600); },
  dlg = h => { $("#dlg").innerHTML = h; $("#ov").classList.add("on"); },
  cl = () => $("#ov").classList.remove("on");
let cur = 0, sel = null;
const bdg = s => `<span class="bd ${s}">${{ Draft: "◔", Pending: "◷", Posted: "✓", Void: "✕" }[s]} ${s}</span>`;
function head(f, m, btns) { return `<div class="hd"><div><div class="crumb">${MD[m][0]} / ${f.n}</div><h1>${f.n} <span class="chip ${TY[f.t][1]}">${TY[f.t][0]}</span></h1><div class="crumb">${PL[f.p]}</div></div><div>${btns || ""}</div></div>`; }
