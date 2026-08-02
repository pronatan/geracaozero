/**
 * API base — sempre a API publicada na AWS (não o PHP local).
 * Troque aqui se o domínio mudar.
 */
(function (w) {
  "use strict";

  var DEFAULT_API = "https://geracaozero.ddnsfree.com";

  // Se a página já está no mesmo host da API, usa URL relativa (mesmo origin).
  // Caso contrário (Live Server / localhost / arquivo local), aponta pra AWS.
  var host = (w.location && w.location.hostname) || "";
  var onAws =
    host === "geracaozero.ddnsfree.com" ||
    host === "geracaozero.freeddns.org" ||
    host === "www.geracaozero.ddnsfree.com" ||
    host.indexOf("elasticbeanstalk.com") !== -1 ||
    host.indexOf("amazonaws.com") !== -1;

  // Preferir HTTPS quando a página já está em HTTPS
  if (!onAws && w.location && w.location.protocol === "http:") {
    DEFAULT_API = "https://geracaozero.ddnsfree.com";
  }

  w.GZ_API_BASE = onAws ? "" : DEFAULT_API;

  w.gzApiUrl = function (path) {
    var p = String(path || "");
    if (p.charAt(0) !== "/") p = "/" + p;
    // paths da API começam em /api/...
    if (p.indexOf("/api/") !== 0 && p.indexOf("api/") === 0) p = "/" + p;
    var base = String(w.GZ_API_BASE || "").replace(/\/$/, "");
    return base + p;
  };

  w.gzGetToken = function () {
    try {
      return w.localStorage.getItem("gz_token") || "";
    } catch (e) {
      return "";
    }
  };

  w.gzSetToken = function (token) {
    try {
      if (token) w.localStorage.setItem("gz_token", token);
      else w.localStorage.removeItem("gz_token");
    } catch (e) { /* ignore */ }
  };

  w.gzAuthHeaders = function (extra) {
    var headers = {};
    if (extra) {
      Object.keys(extra).forEach(function (k) {
        headers[k] = extra[k];
      });
    }
    var token = w.gzGetToken();
    if (token) headers["Authorization"] = "Bearer " + token;
    return headers;
  };

  w.gzFetch = function (path, options) {
    options = options || {};
    var headers = w.gzAuthHeaders(options.headers || {});
    if (options.body && !headers["Content-Type"] && !headers["content-type"]) {
      headers["Content-Type"] = "application/json";
    }
    options.headers = headers;
    options.cache = options.cache || "no-store";
    return fetch(w.gzApiUrl(path), options);
  };

  /** Spinner Bulma no botão enquanto processa */
  w.gzSetBtnLoading = function (btn, loading) {
    if (!btn) return;
    if (loading) {
      if (!btn.dataset.gzLabelHtml) btn.dataset.gzLabelHtml = btn.innerHTML;
      btn.classList.add("is-loading");
      btn.disabled = true;
      btn.setAttribute("aria-busy", "true");
    } else {
      btn.classList.remove("is-loading");
      btn.disabled = false;
      btn.removeAttribute("aria-busy");
      if (btn.dataset.gzLabelHtml) btn.innerHTML = btn.dataset.gzLabelHtml;
    }
  };
})(window);
