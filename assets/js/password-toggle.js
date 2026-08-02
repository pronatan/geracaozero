/**
 * Toggle mostrar/ocultar senha com ícones pixel.
 */
(function () {
  "use strict";

  var EYE = "assets/img/icon-eye-pixel.png?v=2";
  var EYE_OFF = "assets/img/icon-eye-off-pixel.png?v=2";

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
    btn.setAttribute("aria-label", "Mostrar senha");
    btn.setAttribute("title", "Mostrar senha");
    btn.innerHTML =
      '<img class="gz-password-eye" src="' + EYE_OFF + '" alt="" width="20" height="20">';

    btn.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      var show = input.type === "password";
      input.type = show ? "text" : "password";
      var img = btn.querySelector("img");
      if (img) img.src = show ? EYE : EYE_OFF;
      btn.setAttribute("aria-label", show ? "Ocultar senha" : "Mostrar senha");
      btn.setAttribute("title", show ? "Ocultar senha" : "Mostrar senha");
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
