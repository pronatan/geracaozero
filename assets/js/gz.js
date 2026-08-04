//
//  CONFIGURAÇÃO - Geração Zero
//
var SERVER_HOST = "geracaozero.bedrock.net.br";
var SERVER_PORT = 25565;
var SERVER_ADDRESS = SERVER_HOST;
var SERVER_MOTD = "Bem-vindo ao Geração Zero, jogue e evolua junto";

function formatMotd(motd) {
  if (motd && typeof motd === "object") {
    if (Array.isArray(motd.extra)) {
      motd = (motd.text || "") + motd.extra.map(function (part) {
        return part && part.text ? part.text : "";
      }).join("");
    } else if (motd.text) {
      motd = motd.text;
    } else {
      motd = "";
    }
  }

  var text = String(motd || "")
    .replace(/§./gi, "")
    .replace(/\r/g, "")
    .split(/\n+/)
    .map(function (line) { return line.trim(); })
    .filter(Boolean)
    .join(", ");

  // Placeholder padrão do Minecraft → texto em português
  if (!text || /^first\s*line,\s*second\s*line$/i.test(text)) {
    return SERVER_MOTD;
  }

  return text;
}

//
//  COPIAR IP
//
var ip = document.querySelector("#ip");
if (ip) {
  var ipSpan = ip.querySelector("span");
  var ipTextarea = ip.querySelector("textarea");

  if (ipSpan) {
    ipSpan.textContent = SERVER_ADDRESS;
  }

  ip.addEventListener("click", function () {
    ip.classList.add("is-active");
    setTimeout(function () {
      ip.classList.remove("is-active");
    }, 1500);

    if (!ipTextarea) return;
    ipTextarea.classList.add("is-active");
    ipTextarea.value = SERVER_ADDRESS;
    ipTextarea.select();
    ipTextarea.setSelectionRange(0, 99999);
    try {
      navigator.clipboard.writeText(SERVER_ADDRESS);
    } catch (e) {
      document.execCommand("copy");
    }
    ipTextarea.classList.remove("is-active");
  });
}

//
//  STATUS DO SERVIDOR
//
if (typeof $ !== "undefined" && $("#status").length) {
  $("#motd").text(SERVER_MOTD);
  if ($("#players").length) {
    $("#players").text("...");
  }

  $.getJSON(
    "https://api.minetools.eu/ping/" + SERVER_HOST + "/" + SERVER_PORT,
    function (data) {
      if (data.error) {
        $("#status").html('<i class="fas fa-times"></i> Servidor offline');
        $("#motd").text(SERVER_MOTD);
        if ($("#players").length) $("#players").text("0/0");
      } else {
        $("#status").html('<i class="fas fa-check"></i> Servidor online');
        $("#motd").text(formatMotd(data.description));
        var online = data.players && data.players.online != null ? data.players.online : "?";
        var max = data.players && data.players.max != null ? data.players.max : "?";
        if ($("#players").length) {
          $("#players").text(online + "/" + max);
        }
        if ($("#status-players-list").length && data.players && Array.isArray(data.players.sample)) {
          var names = data.players.sample.map(function (p) { return p && p.name ? p.name : ""; }).filter(Boolean);
          $("#status-players-list").html(
            names.length
              ? names.map(function (n) { return "<li>" + n + "</li>"; }).join("")
              : "<li>Lista de nicks indisponível</li>"
          );
        }
      }
    },
  ).fail(function () {
    $("#status").html('<i class="fas fa-times"></i> Status indisponível');
    $("#motd").text(SERVER_MOTD);
    if ($("#players").length) $("#players").text("-");
  });
}

//
//  MENU MOBILE
//
$(".navbar-burger").on("click", function () {
  var $burger = $(this);
  var $menu = $(".navbar-menu");
  var isOpen = !$menu.hasClass("is-active");

  $burger.toggleClass("is-active", isOpen);
  $menu.toggleClass("is-active", isOpen);
  $burger.attr("aria-expanded", isOpen ? "true" : "false");
});
