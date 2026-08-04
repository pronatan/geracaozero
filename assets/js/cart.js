/**
 * Carrinho localStorage — vários VIPs
 * Key: gz_cart = [{ id, qty }]
 */
(function (w) {
  "use strict";
  var KEY = "gz_cart";

  function read() {
    try {
      var raw = localStorage.getItem(KEY);
      var arr = raw ? JSON.parse(raw) : [];
      return Array.isArray(arr) ? arr : [];
    } catch (e) {
      return [];
    }
  }

  function write(items) {
    localStorage.setItem(KEY, JSON.stringify(items || []));
    w.dispatchEvent(new CustomEvent("gz-cart-changed", { detail: { count: count() } }));
  }

  function count() {
    return read().reduce(function (n, it) { return n + (Number(it.qty) || 0); }, 0);
  }

  function add(id, qty) {
    id = String(id || "").toLowerCase();
    qty = Math.max(1, Number(qty) || 1);
    if (!id) return read();
    var items = read();
    var found = items.find(function (x) { return x.id === id; });
    if (found) found.qty = (Number(found.qty) || 0) + qty;
    else items.push({ id: id, qty: qty });
    write(items);
    return items;
  }

  function setQty(id, qty) {
    id = String(id || "").toLowerCase();
    qty = Number(qty) || 0;
    var items = read().filter(function (x) {
      if (x.id !== id) return true;
      if (qty < 1) return false;
      x.qty = qty;
      return true;
    });
    write(items);
    return items;
  }

  function remove(id) {
    return setQty(id, 0);
  }

  function clear() {
    write([]);
  }

  function items() {
    return read();
  }

  w.gzCart = { add: add, setQty: setQty, remove: remove, clear: clear, items: items, count: count };
})(window);
