(function () {
  "use strict";

  function $(id) { return document.getElementById(id); }

  function show(el, yes) {
    if (!el) return;
    el.classList.toggle("is-hidden", !yes);
  }

  function setMsg(text, type) {
    var el = $("conta-msg");
    if (!el) return;
    el.textContent = text || "";
    el.className = "checkout-msg" + (type ? " is-" + type : "") + (text ? "" : " is-hidden");
  }

  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");
  }

  function formatMoney(amount) {
    var n = Number(String(amount == null ? "" : amount).replace(",", "."));
    if (isNaN(n)) return amount == null || amount === "" ? "-" : ("R$" + amount);
    return "R$" + n.toFixed(2).replace(".", ",");
  }

  function formatDate(iso) {
    var s = String(iso || "");
    if (!s) return "-";
    // 2026-08-02T15:01:54.337Z → 02/08/2026 12:01
    var d = new Date(s);
    if (isNaN(d.getTime())) return s.replace("T", " ").slice(0, 16);
    var pad = function (x) { return String(x).padStart(2, "0"); };
    return pad(d.getDate()) + "/" + pad(d.getMonth() + 1) + "/" + d.getFullYear() +
      " " + pad(d.getHours()) + ":" + pad(d.getMinutes());
  }

  function entregaLabel(o) {
    var pay = String(o.status || "").toLowerCase();
    var detail = String(o.statusDetail || o.paymentStatus || "").toLowerCase();
    var fulfill = String(o.fulfillmentStatus || "pending").toLowerCase();

    if (fulfill === "done") return "VIP liberado";

    if (
      pay === "failed" || pay === "cancelled" || pay === "canceled" || pay === "rejected" ||
      detail === "failed" || detail === "high_risk" || detail.indexOf("cc_rejected") === 0 ||
      detail === "expired"
    ) {
      return "Pagamento não aprovado";
    }

    if (
      pay === "action_required" || pay === "pending" ||
      detail === "waiting_transfer" || detail === "pending_waiting_transfer" ||
      detail === "pending_challenge" || detail.indexOf("waiting") !== -1
    ) {
      return "Aguardando pagamento";
    }

    if (pay === "processed" || pay === "approved" || detail === "accredited") {
      return "Aguardando liberação";
    }

    // fallback amigável (sem expor status técnico)
    return "Em andamento";
  }

  function needsPay(o) {
    return entregaLabel(o) === "Aguardando pagamento";
  }

  function isVipActive(o) {
    var pay = String(o.status || "").toLowerCase();
    var detail = String(o.statusDetail || o.paymentStatus || "").toLowerCase();
    var fulfill = String(o.fulfillmentStatus || "pending").toLowerCase();
    if (fulfill === "done") return true;
    return pay === "processed" || pay === "approved" || detail === "accredited";
  }

  function renderBenefits(orders) {
    var el = $("conta-benefits");
    if (!el) return;
    var active = (orders || []).filter(isVipActive);
    if (!active.length) {
      el.innerHTML = "<p>Nenhum VIP ativo ainda. Confira a <a href='/loja'>loja</a>.</p>";
      return;
    }
    el.innerHTML = "<ul class='list'>" + active.map(function (o) {
      var title = o.productTitle || o.vip || "VIP";
      var para = o.deliveryNick || o.giftNick || o.nick || "-";
      var st = String(o.fulfillmentStatus || "").toLowerCase() === "done"
        ? "Liberado no servidor"
        : "Pago — aguardando liberação in-game";
      return "<li><i class='fas fa-check'></i> <b>" + esc(title) + "</b> → " + esc(para) +
        " <small>(" + esc(st) + ")</small></li>";
    }).join("") + "</ul>";
  }

  function payUrl(o) {
    var id = o.id || o.orderId || "";
    var vip = o.vip || "";
    var q = "/checkout?orderId=" + encodeURIComponent(id);
    if (vip) q += "&vip=" + encodeURIComponent(vip);
    return q;
  }

  function typeLabel(t) {
    if (t === "bedrock") return "Bedrock";
    if (t === "tlauncher") return "TLauncher";
    return "Java";
  }

  function renderLinked(list) {
    var el = $("conta-linked-list");
    if (!el) return;
    list = list || [];
    if (!list.length) {
      el.innerHTML = "<p class='checkout-note'>Nenhuma conta extra vinculada.</p>";
      return;
    }
    el.innerHTML = "<ul class='list'>" + list.map(function (a) {
      return "<li><b>" + esc(typeLabel(a.type)) + "</b>: " + esc(a.nick) +
        ' <button type="button" class="button is-small is-danger" data-rm-linked="' + esc(a.nick) + '" data-rm-type="' + esc(a.type || "") + '">Remover</button></li>';
    }).join("") + "</ul>";
  }

  function renderDiscord(u) {
    var st = $("conta-discord-status");
    var link = $("conta-discord-link");
    var unlink = $("btn-unlink-discord");
    if (u.discordId) {
      if (st) st.innerHTML = "Vinculado: <b>" + esc(u.discordUsername || u.discordId) + "</b>";
      if (link) link.classList.add("is-hidden");
      if (unlink) unlink.classList.remove("is-hidden");
    } else {
      if (st) st.textContent = u.discordOAuthReady === false
        ? "Discord OAuth ainda não configurado no servidor."
        : "Não vinculado";
      if (link) {
        var tok = (window.gzGetToken && window.gzGetToken()) || (localStorage.getItem("gz_token") || "");
        link.href = "/api/auth/discord-start.php" + (tok ? ("?token=" + encodeURIComponent(tok)) : "");
        link.classList.toggle("is-hidden", u.discordOAuthReady === false);
      }
      if (unlink) unlink.classList.add("is-hidden");
    }
  }

  function renderSecurity(u) {
    var en = $("conta-2fa-enabled");
    var ch = $("conta-2fa-channel");
    if (en) en.checked = !!u.twoFactorEnabled;
    if (ch) ch.value = u.twoFactorChannel === "discord" ? "discord" : "email";
  }

  async function load() {
    try {
      var res = await window.gzFetch("/api/auth/profile.php");
      var data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || "Faça login");

      show($("conta-gate"), false);
      show($("conta-app"), true);

      var u = data.user || {};
      $("conta-nick").textContent = u.nick || "-";
      $("conta-email-view").textContent = u.email || "-";
      $("conta-email").value = u.email || "";
      renderSecurity(u);
      renderDiscord(u);
      renderLinked(u.linkedAccounts || []);
      if (window.gzRenderAvatarChip) {
        window.gzRenderAvatarChip($("conta-avatar"), u, {
          label: u.nick || "Conta",
          editable: true,
          inputId: "conta-avatar-file",
          onSaved: function (user) {
            window.GZ_USER = user;
            window.gzRenderAvatarChip($("conta-avatar"), user, {
              label: user.nick || "Conta",
              editable: true,
              inputId: "conta-avatar-file",
            });
            setMsg("Foto atualizada!", "ok");
          },
        });
      }
      if (u.role === "admin") {
        show($("conta-role-wrap"), true);
        $("conta-role").textContent = "admin";
        show($("conta-admin-link"), true);
      }

      var orders = data.orders || [];
      renderBenefits(orders);
      if (!orders.length) {
        $("conta-orders").innerHTML = "<p>Nenhum pedido ainda. Visite a <a href='/loja'>loja</a>.</p>";
      } else {
        $("conta-orders").innerHTML =
          '<table class="admin-table" style="width:100%;font-size:0.48rem">' +
          "<thead><tr><th>Produto</th><th>Para</th><th>Valor</th><th>Entrega</th><th>Data</th><th></th></tr></thead><tbody>" +
          orders.map(function (o) {
            var id = o.id || "";
            var payBtn = needsPay(o)
              ? '<a class="button is-small is-success" href="' + esc(payUrl(o)) + '">Pagar</a> '
              : "";
            var delBtn = id
              ? '<button type="button" class="button is-small is-danger" data-del-my-order="' + esc(id) + '">Excluir</button>'
              : "";
            var para = o.deliveryNick || o.giftNick || o.nick || "-";
            var receipt = (entregaLabel(o) === "VIP liberado" || entregaLabel(o) === "Aguardando liberação")
              ? '<a class="button is-small is-link" target="_blank" href="/api/auth/receipt.php?orderId=' + esc(id) + '">Comprovante</a> '
              : "";
            return "<tr>" +
              "<td>" + esc(o.productTitle || o.vip || "VIP") + "</td>" +
              "<td>" + esc(para) + "</td>" +
              "<td>" + esc(formatMoney(o.amount)) + "</td>" +
              "<td>" + esc(entregaLabel(o)) + "</td>" +
              "<td>" + esc(formatDate(o.createdAt)) + "</td>" +
              '<td class="admin-actions">' + payBtn + receipt + delBtn + "</td>" +
              "</tr>";
          }).join("") +
          "</tbody></table>";
      }

      var qs = new URLSearchParams(window.location.search);
      if (qs.get("discord") === "linked") {
        setMsg("Discord vinculado com sucesso!", "ok");
        history.replaceState({}, "", "/conta");
      }
    } catch (e) {
      show($("conta-gate"), true);
      show($("conta-app"), false);
    }
  }

  function bindForm() {
    var form = $("form-conta");
    if (!form) return;
    form.addEventListener("submit", async function (e) {
      e.preventDefault();
      var btn = form.querySelector('button[type="submit"]');
      var payload = { email: $("conta-email").value.trim() };
      var neo = $("conta-pass-new").value;
      if (neo) {
        payload.currentPassword = $("conta-pass-current").value;
        payload.password = neo;
        payload.passwordConfirm = $("conta-pass-confirm").value;
      }
      if (window.gzSetBtnLoading) window.gzSetBtnLoading(btn, true);
      try {
        setMsg("Salvando...", "");
        var res = await window.gzFetch("/api/auth/profile.php", {
          method: "PUT",
          body: JSON.stringify(payload),
        });
        var data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.message || "Falha ao salvar");
        setMsg("Conta atualizada!", "ok");
        $("conta-pass-current").value = "";
        $("conta-pass-new").value = "";
        $("conta-pass-confirm").value = "";
        $("conta-email-view").textContent = (data.user && data.user.email) || payload.email;
      } catch (err) {
        setMsg(err.message || "Erro", "error");
      } finally {
        if (window.gzSetBtnLoading) window.gzSetBtnLoading(btn, false);
      }
    });
  }

  function bindOrdersActions() {
    var wrap = $("conta-orders");
    if (!wrap || wrap.dataset.boundDel) return;
    wrap.dataset.boundDel = "1";
    wrap.addEventListener("click", async function (e) {
      var btn = e.target.closest("[data-del-my-order]");
      if (!btn) return;
      var id = btn.getAttribute("data-del-my-order");
      if (!id) return;
      if (!confirm("Excluir este pedido da sua conta?")) return;
      if (window.gzSetBtnLoading) window.gzSetBtnLoading(btn, true);
      else btn.disabled = true;
      try {
        var res = await window.gzFetch(
          "/api/auth/profile.php?orderId=" + encodeURIComponent(id),
          { method: "DELETE" }
        );
        var data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.message || "Falha ao excluir");
        setMsg("Pedido excluído.", "ok");
        await load();
      } catch (err) {
        setMsg(err.message || "Erro ao excluir", "error");
        if (window.gzSetBtnLoading) window.gzSetBtnLoading(btn, false);
        else btn.disabled = false;
      }
    });
  }

  function bindExtras() {
    var btn2fa = $("btn-save-2fa");
    if (btn2fa && !btn2fa.dataset.bound) {
      btn2fa.dataset.bound = "1";
      btn2fa.addEventListener("click", async function () {
        if (window.gzSetBtnLoading) window.gzSetBtnLoading(btn2fa, true);
        try {
          setMsg("Salvando 2FA...", "");
          var res = await window.gzFetch("/api/auth/profile.php", {
            method: "PUT",
            body: JSON.stringify({
              twoFactorEnabled: !!($("conta-2fa-enabled") && $("conta-2fa-enabled").checked),
              twoFactorChannel: ($("conta-2fa-channel") && $("conta-2fa-channel").value) || "email",
            }),
          });
          var data = await res.json();
          if (!res.ok || !data.ok) throw new Error(data.message || "Falha ao salvar 2FA");
          setMsg("2FA atualizado!", "ok");
          renderSecurity(data.user || {});
        } catch (err) {
          setMsg(err.message || "Erro 2FA", "error");
        } finally {
          if (window.gzSetBtnLoading) window.gzSetBtnLoading(btn2fa, false);
        }
      });
    }

    var unlink = $("btn-unlink-discord");
    if (unlink && !unlink.dataset.bound) {
      unlink.dataset.bound = "1";
      unlink.addEventListener("click", async function () {
        if (!confirm("Desvincular Discord desta conta?")) return;
        try {
          var res = await window.gzFetch("/api/auth/profile.php", {
            method: "PUT",
            body: JSON.stringify({ unlinkDiscord: true }),
          });
          var data = await res.json();
          if (!res.ok || !data.ok) throw new Error(data.message || "Falha");
          setMsg("Discord desvinculado.", "ok");
          renderDiscord(data.user || {});
          renderSecurity(data.user || {});
        } catch (err) {
          setMsg(err.message || "Erro", "error");
        }
      });
    }

    var addBtn = $("btn-add-linked");
    if (addBtn && !addBtn.dataset.bound) {
      addBtn.dataset.bound = "1";
      addBtn.addEventListener("click", async function () {
        var nick = ($("link-nick") && $("link-nick").value || "").trim();
        var type = ($("link-type") && $("link-type").value) || "java";
        if (!nick) {
          setMsg("Informe o nick/gamertag.", "error");
          return;
        }
        if (window.gzSetBtnLoading) window.gzSetBtnLoading(addBtn, true);
        try {
          setMsg("Vinculando...", "");
          var res = await window.gzFetch("/api/auth/profile.php", {
            method: "PUT",
            body: JSON.stringify({ addLinkedAccount: { type: type, nick: nick } }),
          });
          var data = await res.json();
          if (!res.ok || !data.ok) throw new Error(data.message || "Falha ao vincular");
          setMsg("Conta vinculada!", "ok");
          $("link-nick").value = "";
          renderLinked((data.user && data.user.linkedAccounts) || []);
        } catch (err) {
          setMsg(err.message || "Erro", "error");
        } finally {
          if (window.gzSetBtnLoading) window.gzSetBtnLoading(addBtn, false);
        }
      });
    }

    var linkedWrap = $("conta-linked-list");
    if (linkedWrap && !linkedWrap.dataset.boundRm) {
      linkedWrap.dataset.boundRm = "1";
      linkedWrap.addEventListener("click", async function (e) {
        var btn = e.target.closest("[data-rm-linked]");
        if (!btn) return;
        var nick = btn.getAttribute("data-rm-linked");
        var type = btn.getAttribute("data-rm-type") || "";
        try {
          var res = await window.gzFetch("/api/auth/profile.php", {
            method: "PUT",
            body: JSON.stringify({ removeLinkedAccount: { nick: nick, type: type } }),
          });
          var data = await res.json();
          if (!res.ok || !data.ok) throw new Error(data.message || "Falha");
          setMsg("Conta removida.", "ok");
          renderLinked((data.user && data.user.linkedAccounts) || []);
        } catch (err) {
          setMsg(err.message || "Erro", "error");
        }
      });
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    bindForm();
    bindOrdersActions();
    bindExtras();
    load();
  });

  // Voltar da página de pagamento (bfcache / aba) → recarrega pedidos atualizados
  window.addEventListener("pageshow", function (e) {
    if (e.persisted) load();
  });
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible" && $("conta-app") && !$("conta-app").classList.contains("is-hidden")) {
      load();
    }
  });
})();
