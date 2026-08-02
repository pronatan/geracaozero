(function () {
  "use strict";

  function authTargets() {
    return Array.prototype.slice.call(document.querySelectorAll("[data-auth-slot]"));
  }

  function renderGuest(slot) {
    var place = slot.getAttribute("data-auth-slot");
    if (place === "brand") {
      slot.innerHTML =
        '<a href="/login" class="navbar-item auth-btn">Entrar</a>' +
        '<a href="/register" class="navbar-item auth-btn auth-btn-accent">Criar conta</a>';
      return;
    }
    slot.innerHTML =
      '<a href="/login" class="navbar-item auth-btn"><i class="fas fa-sign-in-alt"></i> Entrar</a>' +
      '<a href="/register" class="navbar-item auth-btn auth-btn-accent"><i class="fas fa-user-plus"></i> Criar conta</a>';
  }

  function renderUser(slot, user) {
    var nick = (user && user.nick) ? user.nick : "Conta";
    var avatarHtml = (user && user.avatar)
      ? '<img class="gz-nav-avatar" src="' + user.avatar + '" alt="">'
      : '<span class="gz-nav-avatar gz-nav-avatar-fallback">' + ((nick.charAt(0) || "?").toUpperCase()) + "</span>";
    var accountCta =
      '<a href="/conta" class="navbar-item auth-btn auth-btn-account" title="Gerenciar conta">' +
      avatarHtml +
      '<span class="auth-nick-text">' + nick + "</span>" +
      "</a>";
    var adminLink = (user && user.role === "admin")
      ? '<a href="/admin" class="navbar-item auth-btn auth-btn-accent" title="Acessar painel">Painel</a>'
      : "";
    if (slot.getAttribute("data-auth-slot") === "brand") {
      slot.innerHTML =
        accountCta +
        adminLink +
        '<a href="#" class="navbar-item auth-btn" data-auth-logout>Sair</a>';
      return;
    }
    slot.innerHTML =
      accountCta +
      adminLink +
      '<a href="#" class="navbar-item auth-btn" data-auth-logout><i class="fas fa-sign-out-alt"></i> Sair</a>';
  }

  function bindLogout(root) {
    Array.prototype.forEach.call(root.querySelectorAll("[data-auth-logout]"), function (btn) {
      btn.addEventListener("click", function (e) {
        e.preventDefault();
        var done = function () {
          if (window.gzSetToken) window.gzSetToken("");
          window.location.href = "/";
        };
        (window.gzFetch ? window.gzFetch("/api/auth/logout.php", { method: "POST" })
          : fetch("api/auth/logout.php", { method: "POST" }))
          .then(done)
          .catch(done);
      });
    });
  }

  function paint(user) {
    authTargets().forEach(function (slot) {
      if (user) renderUser(slot, user);
      else renderGuest(slot);
      bindLogout(slot);
    });
  }

  async function loadSession() {
    try {
      var res = await (window.gzFetch
        ? window.gzFetch("/api/auth/me.php")
        : fetch("api/auth/me.php", { cache: "no-store" }));
      var data = await res.json();
      if (data && data.authenticated && data.user) {
        paint(data.user);
        window.GZ_USER = data.user;
      } else {
        paint(null);
        window.GZ_USER = null;
      }
    } catch (e) {
      paint(null);
      window.GZ_USER = null;
    }
  }

  function setBtnLoading(btn, loading, labelWhenIdle) {
    if (window.gzSetBtnLoading) {
      window.gzSetBtnLoading(btn, loading);
      return;
    }
    if (!btn) return;
    if (loading) {
      if (!btn.dataset.label) btn.dataset.label = btn.textContent.trim();
      btn.classList.add("is-loading");
      btn.disabled = true;
    } else {
      btn.classList.remove("is-loading");
      btn.disabled = false;
      if (labelWhenIdle || btn.dataset.label) {
        btn.textContent = labelWhenIdle || btn.dataset.label;
      }
    }
  }

  function bindForms() {
    var loginForm = document.getElementById("form-login");
    if (loginForm) {
      loginForm.addEventListener("submit", async function (e) {
        e.preventDefault();
        var msg = document.getElementById("auth-msg");
        var btn = loginForm.querySelector('button[type="submit"]');
        var login = document.getElementById("login-user").value.trim();
        var password = document.getElementById("login-password").value;
        msg.className = "checkout-msg";
        msg.textContent = "Entrando...";
        setBtnLoading(btn, true);
        try {
          var res = await window.gzFetch("/api/auth/login.php", {
            method: "POST",
            body: JSON.stringify({ login: login, password: password }),
          });
          var data = await res.json();
          if (!res.ok || !data.ok) throw new Error(data.message || "Falha no login");
          if (data.token && window.gzSetToken) window.gzSetToken(data.token);
          msg.className = "checkout-msg is-ok";
          msg.textContent = "Login ok! Redirecionando...";
          setTimeout(function () { window.location.href = "/"; }, 600);
        } catch (err) {
          setBtnLoading(btn, false);
          msg.className = "checkout-msg is-error";
          msg.textContent = err.message || "Erro ao entrar";
        }
      });
    }

    var registerForm = document.getElementById("form-register");
    if (registerForm) {
      var nickLookup = null;
      if (window.gzBindMinecraftNickLookup) {
        nickLookup = window.gzBindMinecraftNickLookup({
          inputId: "reg-nick",
          statusId: "reg-nick-status",
          avatarId: "reg-nick-avatar",
        });
      }

      registerForm.addEventListener("submit", async function (e) {
        e.preventDefault();
        var msg = document.getElementById("auth-msg");
        var btn = registerForm.querySelector('button[type="submit"]');
        var nick = document.getElementById("reg-nick").value.trim();

        if (nickLookup) {
          var st = nickLookup.getState();
          if (!st.found) {
            await nickLookup.lookup();
            st = nickLookup.getState();
          }
          if (!st.found) {
            msg.className = "checkout-msg is-error";
            msg.textContent = "Digite um nick válido encontrado na Mojang ou TLauncher.";
            return;
          }
          if (st.data && st.data.nick) nick = st.data.nick;
        }

        var payload = {
          nick: nick,
          email: document.getElementById("reg-email").value.trim(),
          password: document.getElementById("reg-password").value,
          passwordConfirm: document.getElementById("reg-password2").value,
        };
        msg.className = "checkout-msg";
        msg.textContent = "Criando conta...";
        setBtnLoading(btn, true);
        try {
          var res = await window.gzFetch("/api/auth/register.php", {
            method: "POST",
            body: JSON.stringify(payload),
          });
          var data = await res.json();
          if (!res.ok || !data.ok) throw new Error(data.message || "Falha ao criar conta");
          if (data.token && window.gzSetToken) window.gzSetToken(data.token);
          msg.className = "checkout-msg is-ok";
          msg.textContent = "Conta criada! Redirecionando...";
          setTimeout(function () { window.location.href = "/"; }, 700);
        } catch (err) {
          setBtnLoading(btn, false);
          msg.className = "checkout-msg is-error";
          msg.textContent = err.message || "Erro ao criar conta";
        }
      });
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    loadSession();
    bindForms();
  });
})();
