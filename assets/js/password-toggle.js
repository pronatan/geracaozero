/**
 * Toggle mostrar/ocultar senha — Font Awesome (fa-eye / fa-eye-slash).
 */
(function () {
  "use strict";

  function setIcon(btn, visible) {
    btn.innerHTML = visible
      ? '<i class="fas fa-eye gz-password-eye" aria-hidden="true"></i>'
      : '<i class="fas fa-eye-slash gz-password-eye" aria-hidden="true"></i>';
    btn.setAttribute("aria-label", visible ? "Ocultar senha" : "Mostrar senha");
    btn.setAttribute("title", visible ? "Ocultar senha" : "Mostrar senha");
    if (window.FontAwesome && FontAwesome.dom && typeof FontAwesome.dom.i2svg === "function") {
      try { FontAwesome.dom.i2svg({ node: btn }); } catch (e) { /* ignore */ }
    }
  }

  function wrapInput(input) {
    if (!input || input.dataset.pwToggle === "1") return;
    input.dataset.pwToggle = "1";

    var wrap = document.createElement("div");
    wrap.className = "gz-password-wrap";

    var parent = input.parentNode;
    parent.insertBefore(wrap, input);
    wrap.appendChild(input);
    input.classList.add("gz-password-input");

    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "gz-password-toggle";
    setIcon(btn, false);

    btn.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      var show = input.type === "password";
      input.type = show ? "text" : "password";
      setIcon(btn, show);
      input.focus();
    });

    wrap.appendChild(btn);
  }

  function init() {
    document.querySelectorAll('input[type="password"]').forEach(wrapInput);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  window.gzInitPasswordToggles = init;
})();
