/**
 * Lookup visual de nick Minecraft (Mojang + TLauncher)
 */
(function (w) {
  "use strict";

  function debounce(fn, ms) {
    var t = null;
    return function () {
      var ctx = this, args = arguments;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(ctx, args); }, ms);
    };
  }

  /**
   * @param {object} opts
   *  inputId, statusId, avatarId, onFound(data|null)
   */
  w.gzBindMinecraftNickLookup = function (opts) {
    opts = opts || {};
    var input = document.getElementById(opts.inputId || "");
    var status = document.getElementById(opts.statusId || "");
    var avatar = document.getElementById(opts.avatarId || "");
    if (!input) return;

    var lastReq = 0;
    var state = { found: false, data: null };

    function setStatus(text, type) {
      if (!status) return;
      status.textContent = text || "";
      status.className = "checkout-msg mc-lookup-status" +
        (type ? " is-" + type : "") +
        (text ? "" : " is-hidden");
    }

    function setAvatar(url) {
      if (!avatar) return;
      if (url) {
        avatar.src = url;
        avatar.classList.remove("is-hidden");
      } else {
        avatar.removeAttribute("src");
        avatar.classList.add("is-hidden");
      }
    }

    async function lookup() {
      var nick = String(input.value || "").trim();
      state.found = false;
      state.data = null;
      if (opts.onFound) opts.onFound(null);

      if (nick.length < 3) {
        setStatus("", null);
        setAvatar("");
        return;
      }
      if (!/^[a-zA-Z0-9_]+$/.test(nick)) {
        setStatus("Nick inválido (letras, números e _)", "error");
        setAvatar("");
        return;
      }

      var reqId = ++lastReq;
      setStatus("Buscando…", null);

      try {
        var res = await (w.gzFetch
          ? w.gzFetch("/api/minecraft-lookup.php?nick=" + encodeURIComponent(nick))
          : fetch("api/minecraft-lookup.php?nick=" + encodeURIComponent(nick), { cache: "no-store" }));
        var data = await res.json();
        if (reqId !== lastReq) return;

        if (!res.ok || !data.ok) {
          setStatus(data.message || "Falha ao consultar nick", "error");
          setAvatar("");
          return;
        }

        if (data.found) {
          state.found = true;
          state.data = data;
          // corrige capitalização oficial
          if (data.nick && data.nick !== input.value) {
            input.value = data.nick;
          }
          setAvatar(data.avatar || "");
          setStatus("Conta encontrada", "ok");
          if (opts.onFound) opts.onFound(data);
        } else {
          setAvatar("");
          setStatus(data.message || "Nick não encontrado", "error");
          if (opts.onFound) opts.onFound(null);
        }
      } catch (e) {
        if (reqId !== lastReq) return;
        setStatus("Erro ao buscar nick", "error");
        setAvatar("");
      }
    }

    var run = debounce(lookup, 550);
    input.addEventListener("input", run);
    input.addEventListener("blur", lookup);

    return {
      lookup: lookup,
      getState: function () { return state; },
    };
  };
})(window);
