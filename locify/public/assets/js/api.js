/* ============================================================
   LOCIFY — API client (pure JavaScript, no dependencies)
   Tokens live in memory only (no localStorage/cookies for tokens).
   ============================================================ */

"use strict";

const API = (() => {
  let accessToken = null;
  let refreshToken = null;
  let currentUser = null;

  const BASE = "/api/v1";
  const SESSION_KEY = "locify.session.v1";

  // Restore session from sessionStorage (per-tab, cleared when the tab closes).
  // Keeps tokens out of persistent storage while surviving page navigations.
  function restoreSession() {
    try {
      const saved = JSON.parse(sessionStorage.getItem(SESSION_KEY) || "null");
      if (saved && saved.accessToken) {
        accessToken = saved.accessToken;
        refreshToken = saved.refreshToken || null;
      }
    } catch (_) { /* corrupt session — start fresh */ }
  }

  function persistSession() {
    try {
      if (accessToken) {
        sessionStorage.setItem(SESSION_KEY, JSON.stringify({
          accessToken, refreshToken,
        }));
      } else {
        sessionStorage.removeItem(SESSION_KEY);
      }
    } catch (_) { /* storage unavailable — memory-only session */ }
  }

  restoreSession();

  async function request(method, path, body, isRetry = false) {
    const headers = { "Content-Type": "application/json" };
    if (accessToken) headers["Authorization"] = "Bearer " + accessToken;

    const res = await fetch(BASE + path, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    if (res.status === 401 && !isRetry && refreshToken) {
      const ok = await tryRefresh();
      if (ok) return request(method, path, body, true);
    }

    let data = null;
    try { data = await res.json(); } catch (_) { /* empty body */ }

    if (!res.ok) {
      const err = new Error(data?.error?.message || "Request failed");
      err.code = data?.error?.code || "ERROR";
      err.status = res.status;
      err.data = data || null;
      throw err;
    }
    return data;
  }

  async function tryRefresh() {
    if (!refreshToken) return false;
    try {
      const res = await fetch(BASE + "/auth/refresh", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ refresh_token: refreshToken }),
      });
      const data = await res.json();
      if (res.ok && data.access_token) {
        accessToken = data.access_token;
        persistSession();
        return true;
      }
    } catch (_) { /* ignore */ }
    logout();
    return false;
  }

  function login(username, password) {
    return request("POST", "/auth/login", { username, password })
      .then((data) => {
        accessToken = data.access_token;
        refreshToken = data.refresh_token;
        persistSession();
        return fetchMe();
      });
  }

  // Complete a two-factor (TOTP/recovery code) challenge from a login attempt.
  function verifyMfa(mfaToken, code) {
    return request("POST", "/auth/mfa/verify", { mfa_token: mfaToken, code })
      .then((data) => {
        accessToken = data.access_token;
        refreshToken = data.refresh_token;
        persistSession();
        return fetchMe();
      });
  }

  async function fetchMe() {
    currentUser = await request("GET", "/auth/me");
    return currentUser;
  }

  function logout() {
    accessToken = null;
    refreshToken = null;
    currentUser = null;
    sessionStorage.removeItem(SESSION_KEY);
    window.location.href = "/login";
  }

  const get = (p) => request("GET", p);
  const post = (p, b) => request("POST", p, b);
  const put = (p, b) => request("PUT", p, b);
  const del = (p, b) => request("DELETE", p, b);

  return {
    get, post, put, del,
    login, verifyMfa, logout, fetchMe,
    getToken: () => accessToken,
    getUser: () => currentUser,
    isLoggedIn: () => !!accessToken,
    base: BASE,
  };
})();

/* ---------- UI helpers ---------- */

function el(tag, attrs = {}, children = []) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === "class") node.className = v;
    else if (k === "text") node.textContent = v;
    else if (k === "html") node.innerHTML = v;
    else if (k.startsWith("on")) node.addEventListener(k.slice(2), v);
    else if (v !== null && v !== undefined) node.setAttribute(k, v);
  }
  for (const child of children.flat()) {
    if (child) node.append(child);
  }
  return node;
}

function badge(status) {
  const map = {
    active: "green", completed: "green", issued: "green", signed: "green", valid: "green", printed: "green",
    approved: "green", verified: "green", collected: "green",
    submitted: "blue", pending: "gold", in_review: "gold", waiting: "gold", booked: "gold", draft: "gray",
    queued: "gold", printing: "gold", hold: "gold", resumed: "gold", confirmed: "gold", checked_in: "gold",
    verification: "blue", document_check: "blue", needs_correction: "gold", correction_submitted: "blue",
    rejected: "red", revoked: "red", invalid: "red", cancelled: "red", failed: "red", expired: "red",
    quality_failed: "red",
  };
  const kind = map[status] || "gray";
  const b = el("span", { class: "badge badge-" + kind, text: status.replace(/_/g, " ") });
  return b;
}

function showAlert(container, message, kind = "error") {
  container.innerHTML = "";
  container.append(el("div", { class: "alert alert-" + kind, text: message }));
}

function fmtDate(value) {
  if (!value) return "—";
  return new Date(String(value).replace(" ", "T")).toLocaleString("en-GB", {
    day: "2-digit", month: "short", year: "numeric",
    hour: "2-digit", minute: "2-digit",
  });
}
