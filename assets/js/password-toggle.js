/**
 * Toggle mostrar/ocultar senha com ícones pixel.
 */
(function () {
  "use strict";

  var EYE = "assets/img/icon-eye-pixel.png?v=1";
  var EYE_OFF = "assets/img/icon-eye-off-pixel.png?v=1";

  function wrapInput(input) {
    if (!input || input.dataset.pwToggle === "1") return;
    input.dataset.pwToggle = "1";

    var control = input.parentElement;
    if (!control) return;
    control.classList.add("has-icons-right", "gz-password-control");

    // padding for icon button
    input.classList.add("gz-password-input");

    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "gz-password-toggle";
    btn.setAttribute("aria-label", "Mostrar senha");
    btn.setAttribute("title", "Mostrar senha");
    btn.innerHTML =
      '<img class="gz-password-eye" src="' + EYE_OFF + '" alt="" width="22" height="22">';

    btn.addEventListener("click", function () {
      var show = input.type === "password";
      input.type = show ? "text" : "password";
      var img = btn.querySelector("img");
      if (img) img.src = show ? EYE : EYE_OFF;
      btn.setAttribute("aria-label", show ? "Ocultar senha" : "Mostrar senha");
      btn.setAttribute("title", show ? "Ocultar senha" : "Mostrar senha");
      input.focus();
    });

    control.appendChild(btn);
  }

  function init() {
    document.querySelectorAll('input[type="password"]').forEach(wrapInput);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  // admin form may show later
  window.gzInitPasswordToggles = init;
})();
