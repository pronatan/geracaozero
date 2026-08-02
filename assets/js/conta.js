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
      if (!orders.length) {
        $("conta-orders").innerHTML = "<p>Nenhum pedido ainda. Visite a <a href='/loja'>loja</a>.</p>";
      } else {
        $("conta-orders").innerHTML =
          '<table class="admin-table" style="width:100%;font-size:0.48rem">' +
          "<thead><tr><th>VIP</th><th>Valor</th><th>Status</th><th>Entrega</th><th>Data</th></tr></thead><tbody>" +
          orders.map(function (o) {
            return "<tr>" +
              "<td>" + esc(o.productTitle || o.vip) + "</td>" +
              "<td>" + esc(o.amount) + "</td>" +
              "<td>" + esc(o.status || "-") + "</td>" +
              "<td>" + esc(o.fulfillmentStatus || "pending") + "</td>" +
              "<td>" + esc(String(o.createdAt || "").replace("T", " ").slice(0, 19)) + "</td>" +
              "</tr>";
          }).join("") +
          "</tbody></table>";
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

  document.addEventListener("DOMContentLoaded", function () {
    bindForm();
    load();
  });
})();
