/* Kasira API client: session cookie (Sanctum SPA) + header X-Company-Id.
   Semua endpoint backend berada di /api/v1 dan membalas { data: ... }. */
class ApiError extends Error {
  constructor(status, body) {
    super((body && body.message) || ("HTTP " + status));
    this.status = status;
    this.code = body && body.code;          // mis. company_required, not_a_member
    this.errors = (body && body.errors) || {}; // galat validasi per kolom (422)
    this.body = body;
  }
  first() {                                   // pesan galat pertama, untuk ditampilkan
    const k = Object.keys(this.errors)[0];
    return k ? this.errors[k][0] : this.message;
  }
}

const API = {
  base: "/api/v1",
  company: sessionStorage.getItem("kasira.company") || null,
  onUnauth: null, // dipanggil saat sesi berakhir (401) pada permintaan non-silent

  setCompany(id) {
    API.company = id || null;
    id ? sessionStorage.setItem("kasira.company", id) : sessionStorage.removeItem("kasira.company");
  },

  xsrf() {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return m ? decodeURIComponent(m[1]) : "";
  },

  csrf() {
    return fetch("/sanctum/csrf-cookie", { credentials: "same-origin", headers: { Accept: "application/json" } });
  },

  async req(method, path, body, opt = {}) {
    const h = { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" };
    const t = API.xsrf();
    if (t) h["X-XSRF-TOKEN"] = t;
    if (API.company) h["X-Company-Id"] = API.company;
    const init = { method, headers: h, credentials: "same-origin" };
    if (body !== undefined) { h["Content-Type"] = "application/json"; init.body = JSON.stringify(body); }

    const r = await fetch(API.base + path, init);
    const txt = await r.text();
    let data = null;
    if (txt) { try { data = JSON.parse(txt); } catch (e) { data = { message: txt.slice(0, 200) }; } }

    if (r.status === 419 && !opt.retried) {   // token CSRF kedaluwarsa: ambil ulang, coba sekali lagi
      await API.csrf();
      return API.req(method, path, body, { ...opt, retried: true });
    }
    if (r.status === 401 && !opt.silent && API.onUnauth) API.onUnauth();
    if (!r.ok) throw new ApiError(r.status, data);
    return data;
  },

  get: (p, o) => API.req("GET", p, undefined, o),
  post: (p, b, o) => API.req("POST", p, b === undefined ? {} : b, o),
  put: (p, b, o) => API.req("PUT", p, b === undefined ? {} : b, o),
  del: (p, o) => API.req("DELETE", p, undefined, o),
};
