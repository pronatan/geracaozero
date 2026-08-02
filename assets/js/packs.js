/**
 * Carrega catálogo da API AWS → window.GZ_PACKS
 * Mantém fallback local se a API falhar.
 */
(function (w) {
  "use strict";

  w.GZ_PACKS = w.GZ_PACKS || {};

  w.gzLoadCatalog = async function () {
    try {
      var res = await (w.gzFetch
        ? w.gzFetch("/api/catalog.php")
        : fetch((w.GZ_API_BASE || "") + "/api/catalog.php", { cache: "no-store" }));
      var data = await res.json();
      if (res.ok && data.ok && data.packs) {
        w.GZ_PACKS = data.packs;
        w.GZ_PRODUCTS = data.products || [];
        return w.GZ_PACKS;
      }
    } catch (e) {
      console.warn("catalog API falhou, usando fallback", e);
    }
    return w.GZ_PACKS;
  };
})(window);
