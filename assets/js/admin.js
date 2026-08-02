(function () {
  "use strict";

  var state = { products: [], users: [], orders: [], editingProductId: null };

  function $(id) { return document.getElementById(id); }

  function show(el, yes) {
    if (!el) return;
    el.classList.toggle("is-hidden", !yes);
  }

  function msg(id, text, type) {
    var el = $(id);
    if (!el) return;
    el.textContent = text || "";
    el.className = "checkout-msg" + (type ? " is-" + type : "") + (text ? "" : " is-hidden");
  }

  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  async function api(path, options) {
    options = options || {};
    var res = await window.gzFetch(path, options);
    var data = {};
    try { data = await res.json(); } catch (e) { data = {}; }
    if (!res.ok || data.ok === false) {
      throw new Error(data.message || ("Erro HTTP " + res.status));
    }
    return data;
  }

  function setTab(name) {
    document.querySelectorAll(".admin-tabs li").forEach(function (li) {
      li.classList.toggle("is-active", li.getAttribute("data-tab") === name);
    });
    ["products", "orders", "users"].forEach(function (t) {
      show($("tab-" + t), t === name);
    });
  }

  function renderStats(stats) {
    var el = $("admin-stats");
    if (!el || !stats) return;
    el.innerHTML =
      card(stats.users, "Usuários") +
      card(stats.admins, "Admins") +
      card(stats.productsActive + "/" + stats.products, "Produtos ativos") +
      card(stats.ordersPaid + "/" + stats.orders, "Pedidos pagos");
  }

  function card(n, label) {
    return '<div class="admin-stat"><strong>' + esc(n) + "</strong><span>" + esc(label) + "</span></div>";
  }

  function renderProducts() {
    var rows = state.products.map(function (p) {
      return "<tr>" +
        "<td>" + esc(p.id) + "</td>" +
        "<td>" + esc(p.title) + "</td>" +
        "<td>" + esc(p.priceLabel || p.amount) + "</td>" +
        "<td>" + esc(p.sortOrder) + "</td>" +
        "<td>" + (p.active ? '<span class="badge badge-ok">ativo</span>' : '<span class="badge badge-wait">off</span>') + "</td>" +
        '<td class="admin-actions">' +
          '<button type="button" class="button is-small is-info" data-edit-product="' + esc(p.id) + '">Editar</button>' +
          '<button type="button" class="button is-small is-danger" data-del-product="' + esc(p.id) + '">Excluir</button>' +
        "</td></tr>";
    }).join("");
    $("products-table").innerHTML =
      '<table class="admin-table"><thead><tr><th>ID</th><th>Título</th><th>Preço</th><th>Ordem</th><th>Status</th><th></th></tr></thead><tbody>' +
      (rows || "<tr><td colspan='6'>Nenhum produto</td></tr>") +
      "</tbody></table>";
  }

  function renderOrders() {
    var rows = state.orders.map(function (o) {
      var st = o.status || "-";
      var fulfill = o.fulfillmentStatus || "pending";
      return "<tr>" +
        "<td>" + esc((o.id || "").slice(0, 12)) + "…</td>" +
        "<td>" + esc(o.nick) + "</td>" +
        "<td>" + esc(o.vip) + "</td>" +
        "<td>" + esc(o.amount) + "</td>" +
        "<td>" + esc(o.method) + "</td>" +
        "<td>" + esc(st) + "</td>" +
        "<td>" + esc(fulfill) + "</td>" +
        "<td>" + esc((o.createdAt || "").replace("T", " ").slice(0, 19)) + "</td>" +
        '<td class="admin-actions">' +
          (fulfill !== "done"
            ? '<button type="button" class="button is-small is-success" data-fulfill="' + esc(o.id) + '">Marcar liberado</button>'
            : "") +
        "</td></tr>";
    }).join("");
    $("orders-table").innerHTML =
      '<table class="admin-table"><thead><tr><th>ID</th><th>Nick</th><th>VIP</th><th>Valor</th><th>Método</th><th>Status</th><th>Entrega</th><th>Criado</th><th></th></tr></thead><tbody>' +
      (rows || "<tr><td colspan='9'>Nenhum pedido</td></tr>") +
      "</tbody></table>";
  }

  function renderUsers() {
    var rows = state.users.map(function (u) {
      var roleBadge = u.role === "admin"
        ? '<span class="badge badge-admin">admin</span>'
        : '<span class="badge badge-user">user</span>';
      return "<tr>" +
        "<td>" + esc(u.nick) + "</td>" +
        "<td>" + esc(u.email) + "</td>" +
        "<td>" + roleBadge + "</td>" +
        "<td>" + esc((u.createdAt || "").replace("T", " ").slice(0, 19)) + "</td>" +
        '<td class="admin-actions">' +
          (u.role === "admin"
            ? '<button type="button" class="button is-small" data-role-user="' + esc(u.id) + '">Tornar user</button>'
            : '<button type="button" class="button is-small is-warning" data-role-admin="' + esc(u.id) + '">Tornar admin</button>') +
          '<button type="button" class="button is-small is-danger" data-del-user="' + esc(u.id) + '">Excluir</button>' +
        "</td></tr>";
    }).join("");
    $("users-table").innerHTML =
      '<table class="admin-table"><thead><tr><th>Nick</th><th>E-mail</th><th>Role</th><th>Criado</th><th></th></tr></thead><tbody>' +
      (rows || "<tr><td colspan='5'>Nenhum usuário</td></tr>") +
      "</tbody></table>";
  }

  async function refreshAll() {
    var stats = await api("/api/admin/stats.php");
    renderStats(stats.stats);
    if (window.gzRenderAvatarChip) {
      window.gzRenderAvatarChip($("admin-who"), stats.admin || {}, {
        label: (stats.admin && stats.admin.nick) ? ("Admin: " + stats.admin.nick) : "Admin",
        editable: true,
        inputId: "admin-avatar-file",
        onSaved: function (user) {
          window.gzRenderAvatarChip($("admin-who"), user, {
            label: "Admin: " + (user.nick || "admin"),
            editable: true,
            inputId: "admin-avatar-file",
          });
        },
      });
    } else {
      $("admin-who").textContent = (stats.admin && stats.admin.nick) ? ("Admin: " + stats.admin.nick) : "Admin";
    }

    var products = await api("/api/admin/products.php");
    state.products = products.products || [];
    renderProducts();

    var orders = await api("/api/admin/orders.php");
    state.orders = orders.orders || [];
    renderOrders();

    var users = await api("/api/admin/users.php");
    state.users = users.users || [];
    renderUsers();
  }

  function openProductForm(product) {
    show($("form-product"), true);
    state.editingProductId = product ? product.id : null;
    $("product-form-title").textContent = product ? ("Editar: " + product.id) : "Novo produto";
    $("p-id").value = product ? product.id : "";
    $("p-id").readOnly = !!product;
    $("p-title").value = product ? product.title : "";
    $("p-amount").value = product ? product.amount : "";
    $("p-price").value = product ? (product.priceLabel || "") : "";
    $("p-img").value = product ? (product.imageUrl || "") : "";
    $("p-desc").value = product ? (product.description || "") : "";
    $("p-perks").value = product && product.perks ? product.perks.join("\n") : "";
    $("p-sort").value = product ? (product.sortOrder || 1) : 99;
    $("p-active").checked = product ? !!product.active : true;
    msg("product-msg", "");
  }

  function bindEvents() {
    document.querySelectorAll(".admin-tabs li").forEach(function (li) {
      li.addEventListener("click", function () {
        setTab(li.getAttribute("data-tab"));
      });
    });

    $("btn-new-product").addEventListener("click", function () { openProductForm(null); });
    $("btn-cancel-product").addEventListener("click", function () { show($("form-product"), false); });
    $("btn-new-user").addEventListener("click", function () { show($("form-user"), true); msg("user-msg", ""); });
    $("btn-cancel-user").addEventListener("click", function () { show($("form-user"), false); });

    $("form-product").addEventListener("submit", async function (e) {
      e.preventDefault();
      var payload = {
        id: $("p-id").value.trim().toLowerCase(),
        title: $("p-title").value.trim(),
        amount: $("p-amount").value.trim(),
        priceLabel: $("p-price").value.trim(),
        imageUrl: $("p-img").value.trim(),
        description: $("p-desc").value.trim(),
        perks: $("p-perks").value,
        sortOrder: parseInt($("p-sort").value, 10) || 0,
        active: $("p-active").checked,
      };
      try {
        msg("product-msg", "Salvando...", "");
        await api("/api/admin/products.php", {
          method: state.editingProductId ? "PUT" : "POST",
          body: JSON.stringify(payload),
        });
        msg("product-msg", "Salvo!", "ok");
        show($("form-product"), false);
        await refreshAll();
      } catch (err) {
        msg("product-msg", err.message, "error");
      }
    });

    $("form-user").addEventListener("submit", async function (e) {
      e.preventDefault();
      var payload = {
        nick: $("u-nick").value.trim(),
        email: $("u-email").value.trim(),
        password: $("u-password").value,
        role: $("u-role").value,
      };
      try {
        msg("user-msg", "Criando...", "");
        await api("/api/admin/users.php", { method: "POST", body: JSON.stringify(payload) });
        msg("user-msg", "Usuário criado!", "ok");
        $("form-user").reset();
        show($("form-user"), false);
        await refreshAll();
      } catch (err) {
        msg("user-msg", err.message, "error");
      }
    });

    document.body.addEventListener("click", async function (e) {
      var t = e.target.closest("[data-edit-product],[data-del-product],[data-del-user],[data-role-admin],[data-role-user],[data-fulfill]");
      if (!t) return;
      try {
        if (t.hasAttribute("data-edit-product")) {
          var id = t.getAttribute("data-edit-product");
          var p = state.products.find(function (x) { return x.id === id; });
          if (p) openProductForm(p);
        } else if (t.hasAttribute("data-del-product")) {
          if (!confirm("Excluir produto?")) return;
          await api("/api/admin/products.php?id=" + encodeURIComponent(t.getAttribute("data-del-product")), { method: "DELETE" });
          await refreshAll();
        } else if (t.hasAttribute("data-del-user")) {
          if (!confirm("Excluir usuário?")) return;
          await api("/api/admin/users.php?id=" + encodeURIComponent(t.getAttribute("data-del-user")), { method: "DELETE" });
          await refreshAll();
        } else if (t.hasAttribute("data-role-admin")) {
          await api("/api/admin/users.php", {
            method: "PUT",
            body: JSON.stringify({ id: t.getAttribute("data-role-admin"), role: "admin" }),
          });
          await refreshAll();
        } else if (t.hasAttribute("data-role-user")) {
          await api("/api/admin/users.php", {
            method: "PUT",
            body: JSON.stringify({ id: t.getAttribute("data-role-user"), role: "user" }),
          });
          await refreshAll();
        } else if (t.hasAttribute("data-fulfill")) {
          await api("/api/admin/orders.php", {
            method: "PUT",
            body: JSON.stringify({ id: t.getAttribute("data-fulfill"), fulfillmentStatus: "done" }),
          });
          await refreshAll();
        }
      } catch (err) {
        alert(err.message || "Erro");
      }
    });

    $("btn-admin-logout").addEventListener("click", function () {
      var done = function () {
        if (window.gzSetToken) window.gzSetToken("");
        location.href = "login.html";
      };
      window.gzFetch("/api/auth/logout.php", { method: "POST" }).then(done).catch(done);
    });
  }

  async function boot() {
    bindEvents();
    try {
      var me = await api("/api/auth/me.php");
      if (!me.authenticated || !me.user || me.user.role !== "admin") {
        show($("admin-gate"), true);
        show($("admin-app"), false);
        return;
      }
      show($("admin-gate"), false);
      show($("admin-app"), true);
      await refreshAll();
    } catch (e) {
      show($("admin-gate"), true);
      show($("admin-app"), false);
    }
  }

  document.addEventListener("DOMContentLoaded", boot);
})();
