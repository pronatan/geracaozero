/**
 * Avatar helpers — redimensiona e salva no perfil.
 */
(function (w) {
  "use strict";

  w.gzAvatarInitial = function (nick) {
    var s = String(nick || "?").trim();
    return (s.charAt(0) || "?").toUpperCase();
  };

  w.gzResizeImageFile = function (file, maxSize, quality) {
    maxSize = maxSize || 128;
    quality = quality || 0.82;
    return new Promise(function (resolve, reject) {
      if (!file || !file.type || file.type.indexOf("image/") !== 0) {
        reject(new Error("Selecione uma imagem"));
        return;
      }
      var reader = new FileReader();
      reader.onerror = function () { reject(new Error("Falha ao ler arquivo")); };
      reader.onload = function () {
        var img = new Image();
        img.onerror = function () { reject(new Error("Imagem inválida")); };
        img.onload = function () {
          var canvas = document.createElement("canvas");
          var size = Math.min(maxSize, Math.max(img.width, img.height));
          // crop center square
          var side = Math.min(img.width, img.height);
          var sx = (img.width - side) / 2;
          var sy = (img.height - side) / 2;
          canvas.width = maxSize;
          canvas.height = maxSize;
          var ctx = canvas.getContext("2d");
          ctx.imageSmoothingEnabled = true;
          ctx.drawImage(img, sx, sy, side, side, 0, 0, maxSize, maxSize);
          resolve(canvas.toDataURL("image/jpeg", quality));
        };
        img.src = reader.result;
      };
      reader.readAsDataURL(file);
    });
  };

  w.gzRenderAvatarChip = function (el, user, opts) {
    if (!el) return;
    opts = opts || {};
    var nick = (user && user.nick) || "user";
    var avatar = (user && user.avatar) || "";
    var label = opts.label || nick;
    var canEdit = !!opts.editable;
    var inputId = opts.inputId || ("avatar-file-" + Math.random().toString(36).slice(2, 8));

    var media = avatar
      ? '<img class="gz-avatar-img" src="' + avatar + '" alt="' + nick + '">'
      : '<span class="gz-avatar-fallback">' + w.gzAvatarInitial(nick) + "</span>";

    el.className = (el.className || "").replace(/\bis-hidden\b/g, "").trim();
    if (el.className.indexOf("gz-avatar-chip") === -1) {
      el.className += (el.className ? " " : "") + "gz-avatar-chip";
    }

    el.innerHTML =
      '<div class="gz-avatar-media" aria-hidden="true">' + media + "</div>" +
      '<div class="gz-avatar-meta">' +
        '<span class="gz-avatar-label">' + label + "</span>" +
        (canEdit ? '<label class="gz-avatar-add" for="' + inputId + '"><i class="fas fa-camera"></i> Foto</label>' : "") +
      "</div>" +
      (canEdit
        ? '<input type="file" id="' + inputId + '" class="gz-avatar-input" accept="image/*">'
        : "");

    if (canEdit) {
      var input = el.querySelector("#" + inputId);
      if (input) {
        input.addEventListener("change", async function () {
          var file = input.files && input.files[0];
          if (!file) return;
          try {
            if (opts.onUploading) opts.onUploading(true);
            var dataUrl = await w.gzResizeImageFile(file, 128, 0.82);
            var res = await w.gzFetch("/api/auth/profile.php", {
              method: "PUT",
              body: JSON.stringify({ avatar: dataUrl }),
            });
            var data = await res.json();
            if (!res.ok || !data.ok) throw new Error(data.message || "Falha ao salvar foto");
            if (opts.onSaved) opts.onSaved(data.user);
            else w.gzRenderAvatarChip(el, data.user, opts);
          } catch (err) {
            alert(err.message || "Erro ao enviar foto");
          } finally {
            input.value = "";
            if (opts.onUploading) opts.onUploading(false);
          }
        });
      }
    }
  };
})(window);
