(function () {
  "use strict";

  var state = {
    vip: "lacoste",
    amount: "29.90",
    amountOriginal: "29.90",
    title: "VIP Lacoste",
    publicKey: null,
    mp: null,
    cardForm: null,
    orderId: null,
    pollTimer: null,
    nick: "",
    nickLookup: null,
    coupon: "",
    couponPercent: 0,
  };

    var packs = window.GZ_PACKS || {};

  async function ensureCatalog() {
    if (window.gzLoadCatalog) {
      await window.gzLoadCatalog();
      packs = window.GZ_PACKS || packs;
    }
  }

  function $(id) {
    return document.getElementById(id);
  }

  function money(value) {
    return "R$" + String(value).replace(".", ",");
  }

  function setMsg(text, type) {
    var el = $("checkout-msg");
    if (!el) return;
    var hasText = !!(text && String(text).trim());
    el.className = "checkout-msg" + (type ? " is-" + type : "") + (hasText ? "" : " is-hidden");
    el.textContent = hasText ? text : "";
  }

  function show(el, yes) {
    if (!el) return;
    el.classList.toggle("is-hidden", !yes);
  }

  function getSelectedMethod() {
    var checked = document.querySelector('input[name="pay-method"]:checked');
    return checked ? checked.value : "pix";
  }

  async function loadPublicKey() {
    var res = await window.gzFetch("/api/public-key.php");
    var data = await res.json();
    if (!res.ok || !data.ok) {
      throw new Error(data.message || "Não foi possível carregar a chave pública");
    }
    state.publicKey = data.publicKey;
    state.mp = new MercadoPago(data.publicKey, { locale: "pt-BR" });
  }

  function mountCardForm() {
    if (!state.mp || state.cardForm) return;

    state.cardForm = state.mp.cardForm({
      amount: String(state.amount),
      iframe: true,
      form: {
        id: "form-checkout-card",
        cardNumber: { id: "form-checkout__cardNumber", placeholder: "Número do cartão" },
        expirationDate: { id: "form-checkout__expirationDate", placeholder: "MM/AA" },
        securityCode: { id: "form-checkout__securityCode", placeholder: "CVV" },
        cardholderName: { id: "form-checkout__cardholderName", placeholder: "Nome impresso no cartão" },
        issuer: { id: "form-checkout__issuer", placeholder: "Banco emissor" },
        installments: { id: "form-checkout__installments", placeholder: "Parcelas" },
        identificationType: { id: "form-checkout__identificationType", placeholder: "Tipo doc." },
        identificationNumber: { id: "form-checkout__identificationNumber", placeholder: "CPF" },
        cardholderEmail: { id: "form-checkout__cardholderEmail", placeholder: "E-mail" },
      },
      callbacks: {
        onFormMounted: function (error) {
          if (error) console.warn("cardForm mount", error);
        },
        onSubmit: function (event) {
          event.preventDefault();
          submitCard();
        },
        onError: function (error) {
          console.warn("cardForm error", error);
          setMsg("Revise os dados do cartão.", "error");
        },
      },
    });
  }

  function syncPackFromPage() {
    var vipQ = new URLSearchParams(location.search).get("vip") || "";
    var cartItems = (window.gzCart && window.gzCart.items) ? window.gzCart.items() : [];
    state.cartLines = [];

    if (cartItems.length) {
      cartItems.forEach(function (it) {
        var pack = packs[it.id];
        if (!pack) return;
        state.cartLines.push({
          vip: it.id,
          qty: Math.max(1, Number(it.qty) || 1),
          title: pack.titulo,
          unit: Number(pack.amount || 0),
        });
      });
    }

    if (!state.cartLines.length) {
      var vip = vipQ || "lacoste";
      var pack = packs[vip] || packs.lacoste || packs[Object.keys(packs)[0]];
      if (!pack) return;
      state.cartLines = [{ vip: vip, qty: 1, title: pack.titulo, unit: Number(pack.amount || 0) }];
    }

    state.vip = state.cartLines[0].vip;
    state.title = state.cartLines.map(function (l) {
      return l.title + (l.qty > 1 ? (" x" + l.qty) : "");
    }).join(" + ");
    state.amountOriginal = String(state.cartLines.reduce(function (s, l) {
      return s + l.unit * l.qty;
    }, 0).toFixed(2));
    state.amount = state.amountOriginal;
    state.coupon = "";
    state.couponPercent = 0;
    renderCartBox();
    updateResumo();
  }

  function renderCartBox() {
    var box = $("checkout-cart-box");
    if (!box) return;
    if (!state.cartLines || state.cartLines.length < 2) {
      box.classList.add("is-hidden");
      box.innerHTML = "";
      return;
    }
    box.classList.remove("is-hidden");
    box.innerHTML = "<b>Carrinho</b><ul style='margin:0.4rem 0 0;padding-left:1.1rem'>" +
      state.cartLines.map(function (l) {
        return "<li>" + l.title + " × " + l.qty + " — " + money(l.unit * l.qty) + "</li>";
      }).join("") +
      "</ul><a href='/loja' style='font-size:0.75rem'>Adicionar mais na loja</a>";
  }

  function orderItemsPayload() {
    return (state.cartLines || []).map(function (l) {
      return { vip: l.vip, qty: l.qty };
    });
  }

  function updateResumo() {
    if (!$("checkout-resumo")) return;
    var text = state.title + " — " + money(state.amount);
    if (state.coupon && state.couponPercent) {
      text += " (cupom " + state.coupon + " −" + state.couponPercent + "%)";
    }
    $("checkout-resumo").textContent = text;
    if ($("produto-preco")) $("produto-preco").textContent = money(state.amount);
  }

  function getGiftNick() {
    var toggle = $("checkout-gift-toggle");
    if (!toggle || !toggle.checked) return "";
    return (($("checkout-gift-nick") && $("checkout-gift-nick").value) || "").trim();
  }

  async function applyCoupon() {
    var code = (($("checkout-coupon") && $("checkout-coupon").value) || "").trim();
    var status = $("checkout-coupon-status");
    if (!code) {
      state.coupon = "";
      state.couponPercent = 0;
      state.amount = state.amountOriginal;
      updateResumo();
      if (status) {
        status.textContent = "";
        status.className = "checkout-msg is-hidden";
      }
      return;
    }
    try {
      var res = await window.gzFetch("/api/validate-coupon.php", {
        method: "POST",
        body: JSON.stringify({ code: code, amount: Number(state.amountOriginal) }),
      });
      var data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || "Cupom inválido");
      state.coupon = data.code;
      state.couponPercent = data.percent;
      state.amount = String(data.amount || state.amountOriginal);
      updateResumo();
      if (status) {
        status.textContent = data.message || "Cupom aplicado";
        status.className = "checkout-msg is-ok";
      }
    } catch (e) {
      state.coupon = "";
      state.couponPercent = 0;
      state.amount = state.amountOriginal;
      updateResumo();
      if (status) {
        status.textContent = e.message || "Cupom inválido";
        status.className = "checkout-msg is-error";
      }
    }
  }

  async function createOrder(payload) {
    var res = await window.gzFetch("/api/create-order.php", {
      method: "POST",
      body: JSON.stringify(payload),
    });
    var data = await res.json();
    if (!res.ok || !data.ok) {
      var err = new Error(data.message || "Falha ao criar pagamento");
      err.details = data.details;
      throw err;
    }
    return data;
  }

  function renderPix(data) {
    var box = $("pix-result");
    show(box, true);
    show($("checkout-form-wrap"), false);

    var img = $("pix-qr-img");
    var code = $("pix-copy-code");
    var link = $("pix-ticket-link");

    if (data.pix && data.pix.qrCodeBase64) {
      img.src = "data:image/png;base64," + data.pix.qrCodeBase64;
      show(img, true);
    } else {
      show(img, false);
    }

    code.value = (data.pix && data.pix.qrCode) || "";
    if (data.pix && data.pix.ticketUrl) {
      link.href = data.pix.ticketUrl;
      show(link, true);
    } else {
      show(link, false);
    }

    state.orderId = data.orderId;
    setMsg("Pix gerado! Pague e aguarde a confirmação automática.", "ok");
    startPolling();
  }

  function renderPaid(data) {
    stopPolling();
    show($("checkout-form-wrap"), false);
    show($("pix-result"), false);
    show($("pay-success"), true);
    $("pay-success-text").textContent =
      "Pagamento confirmado para " + (data.deliveryNick || data.nick || state.nick || "seu nick") +
      ". Em breve o VIP será liberado. Guarde o pedido: " + (data.externalReference || data.orderId || "");
    setMsg("Pagamento aprovado!", "ok");
  }

  function isPaidStatus(data) {
    var ok = ["processed", "accredited", "approved"];
    return (
      ok.indexOf(data.status) !== -1 ||
      ok.indexOf(data.statusDetail) !== -1 ||
      ok.indexOf(data.paymentStatus) !== -1 ||
      ok.indexOf(data.paymentStatusDetail) !== -1
    );
  }

  function startPolling() {
    stopPolling();
    if (!state.orderId) return;
    state.pollTimer = setInterval(async function () {
      try {
        var res = await window.gzFetch(
          "/api/order-status.php?id=" + encodeURIComponent(state.orderId)
        );
        var data = await res.json();
        if (!data.ok) return;
        if (isPaidStatus(data)) {
          renderPaid({
            nick: state.nick,
            orderId: data.orderId,
            externalReference: data.externalReference,
          });
        }
      } catch (e) {
        /* ignore transient */
      }
    }, 4000);
  }

  function stopPolling() {
    if (state.pollTimer) {
      clearInterval(state.pollTimer);
      state.pollTimer = null;
    }
  }

  async function ensureNickFound() {
    var nickEl = $("checkout-nick");
    var nick = ((nickEl && nickEl.value) || "").trim();
    if (!state.nickLookup) {
      state.nick = nick;
      return nick;
    }
    var st = state.nickLookup.getState();
    if (!st.found) {
      await state.nickLookup.lookup();
      st = state.nickLookup.getState();
    }
    if (!st.found) {
      throw new Error("Digite um nick válido.");
    }
    if (st.data && st.data.nick) {
      nick = st.data.nick;
      if (nickEl) nickEl.value = nick;
    }
    state.nick = nick;
    return nick;
  }

  async function submitPix() {
    var email = ($("checkout-email") || {}).value || "";
    var cpf = ($("checkout-cpf") || {}).value || "";

    setMsg("Gerando Pix...", null);
    if (window.gzSetBtnLoading) window.gzSetBtnLoading($("btn-pay-pix"), true);
    else $("btn-pay-pix").disabled = true;

    try {
      var typedCoupon = (($("checkout-coupon") && $("checkout-coupon").value) || "").trim();
      if (typedCoupon && (!state.coupon || state.coupon.toUpperCase() !== typedCoupon.toUpperCase())) {
        await applyCoupon();
        if (typedCoupon && !state.coupon) {
          throw new Error(($("checkout-coupon-status") && $("checkout-coupon-status").textContent) || "Cupom inválido");
        }
      }
      var nick = await ensureNickFound();
      var data = await createOrder({
        vip: state.vip,
        items: orderItemsPayload(),
        method: "pix",
        nick: nick,
        giftNick: getGiftNick(),
        coupon: state.coupon || "",
        email: email.trim(),
        cpf: cpf.trim(),
      });
      if (window.gzCart) window.gzCart.clear();

      if (data.status === "processed" || data.statusDetail === "accredited") {
        renderPaid(data);
      } else {
        renderPix(data);
      }
    } catch (e) {
      console.error(e);
      setMsg(e.message || "Erro ao gerar Pix", "error");
    } finally {
      if (window.gzSetBtnLoading) window.gzSetBtnLoading($("btn-pay-pix"), false);
      else $("btn-pay-pix").disabled = false;
    }
  }

  async function submitCard() {
    if (!state.cardForm) {
      setMsg("Formulário de cartão não carregou. Recarregue a página.", "error");
      return;
    }

    var cardData;
    try {
      cardData = state.cardForm.getCardFormData();
    } catch (e) {
      setMsg("Preencha os dados do cartão corretamente.", "error");
      return;
    }

    if (!cardData || !cardData.token) {
      setMsg("Não foi possível tokenizar o cartão.", "error");
      return;
    }

    setMsg("Processando cartão...", null);
    if (window.gzSetBtnLoading) window.gzSetBtnLoading($("btn-pay-card"), true);
    else $("btn-pay-card").disabled = true;

    try {
      var typedCouponCard = (($("checkout-coupon") && $("checkout-coupon").value) || "").trim();
      if (typedCouponCard && (!state.coupon || state.coupon.toUpperCase() !== typedCouponCard.toUpperCase())) {
        await applyCoupon();
        if (typedCouponCard && !state.coupon) {
          throw new Error(($("checkout-coupon-status") && $("checkout-coupon-status").textContent) || "Cupom inválido");
        }
      }
      var nick = await ensureNickFound();
      var data = await createOrder({
        vip: state.vip,
        items: orderItemsPayload(),
        method: "credit_card",
        nick: nick,
        giftNick: getGiftNick(),
        coupon: state.coupon || "",
        email: cardData.cardholderEmail || (($("checkout-email") || {}).value || ""),
        cpf: cardData.identificationNumber || (($("checkout-cpf") || {}).value || ""),
        cardToken: cardData.token,
        paymentMethodId: cardData.paymentMethodId,
        installments: Number(cardData.installments || 1),
        issuerId: cardData.issuerId,
      });
      if (window.gzCart) window.gzCart.clear();

      state.orderId = data.orderId || null;

      if (isPaidStatus(data)) {
        renderPaid(data);
      } else if (data.status === "action_required" || data.status === "pending") {
        setMsg("Pagamento em análise. Confirme no app do banco se pedido e aguarde…", "ok");
        startPolling();
      } else {
        setMsg("Status: " + (data.statusDetail || data.status || "pendente") + ". Aguardando confirmação…", "ok");
        startPolling();
      }
    } catch (e) {
      console.error(e);
      setMsg(e.message || "Pagamento recusado ou inválido", "error");
    } finally {
      if (window.gzSetBtnLoading) window.gzSetBtnLoading($("btn-pay-card"), false);
      else $("btn-pay-card").disabled = false;
    }
  }

  function bindUI() {
    document.querySelectorAll('input[name="pay-method"]').forEach(function (input) {
      input.addEventListener("change", function () {
        var method = getSelectedMethod();
        show($("panel-pix"), method === "pix");
        show($("panel-card"), method === "credit_card");
        if (method === "credit_card") mountCardForm();
      });
    });

    var btnPix = $("btn-pay-pix");
    if (btnPix) {
      btnPix.addEventListener("click", function (e) {
        e.preventDefault();
        submitPix();
      });
    }

    var copyBtn = $("pix-copy-btn");
    if (copyBtn) {
      copyBtn.addEventListener("click", function () {
        var code = $("pix-copy-code");
        if (!code || !code.value) return;
        navigator.clipboard.writeText(code.value).then(function () {
          setMsg("Código Pix copiado!", "ok");
        });
      });
    }

    var giftToggle = $("checkout-gift-toggle");
    if (giftToggle) {
      giftToggle.addEventListener("change", function () {
        show($("checkout-gift-wrap"), !!giftToggle.checked);
      });
    }

    var btnCoupon = $("btn-apply-coupon");
    if (btnCoupon) {
      btnCoupon.addEventListener("click", function (e) {
        e.preventDefault();
        applyCoupon();
      });
    }
  }

  async function resumePendingOrder(orderId) {
    setMsg("Carregando pagamento...", null);
    try {
      var res = await window.gzFetch(
        "/api/order-status.php?id=" + encodeURIComponent(orderId)
      );
      var data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || "Pedido não encontrado");

      state.orderId = data.orderId || orderId;
      state.nick = data.nick || state.nick || "";
      if (data.vip) state.vip = data.vip;
      if (data.amount) state.amount = String(data.amount);
      if (data.productTitle) state.title = data.productTitle;
      if ($("checkout-resumo") && (data.productTitle || data.amount)) {
        $("checkout-resumo").textContent =
          (data.productTitle || state.title) + " — R$" + String(data.amount || state.amount).replace(".", ",");
      }
      if (data.nick && $("checkout-nick")) {
        $("checkout-nick").value = data.nick;
      }

      if (isPaidStatus(data)) {
        renderPaid({
          nick: data.nick || state.nick,
          orderId: data.orderId,
          externalReference: data.externalReference,
        });
        return;
      }

      var waiting =
        data.status === "action_required" ||
        data.status === "pending" ||
        (data.statusDetail && String(data.statusDetail).indexOf("waiting") !== -1);

      var isPix = data.method === "pix" || !!(data.pix && (data.pix.qrCode || data.pix.qrCodeBase64));
      var isCard = data.method === "credit_card";

      // Pix: já mostra QR / código na hora
      if (isPix && data.pix && (data.pix.qrCode || data.pix.qrCodeBase64 || data.pix.ticketUrl)) {
        var pixRadio = document.querySelector('input[name="pay-method"][value="pix"]');
        if (pixRadio) pixRadio.checked = true;
        renderPix(data);
        return;
      }

      // Cartão: abre direto a área do cartão
      if (isCard || (!isPix && waiting)) {
        show($("checkout-form-wrap"), true);
        show($("pix-result"), false);
        show($("pay-success"), false);
        var cardRadio = document.querySelector('input[name="pay-method"][value="credit_card"]');
        if (cardRadio) cardRadio.checked = true;
        show($("panel-pix"), false);
        show($("panel-card"), true);
        mountCardForm();
        setMsg("Digite os dados do cartão para pagar.", "ok");
        var cardForm = $("form-checkout-card");
        if (cardForm && cardForm.scrollIntoView) {
          setTimeout(function () { cardForm.scrollIntoView({ behavior: "smooth", block: "center" }); }, 120);
        }
        return;
      }

      // Sem QR ainda (Pix): deixa pronto pra gerar
      show($("checkout-form-wrap"), true);
      var pixR = document.querySelector('input[name="pay-method"][value="pix"]');
      if (pixR) pixR.checked = true;
      show($("panel-pix"), true);
      show($("panel-card"), false);
      setMsg("Clique em Pagar com Pix para gerar o código.", "ok");
    } catch (e) {
      console.error(e);
      setMsg(e.message || "Não foi possível reabrir o pagamento", "error");
    }
  }

  async function init() {
    if (!$("checkout-root")) return;
    await ensureCatalog();
    syncPackFromPage();
    bindUI();
    if (window.gzBindMinecraftNickLookup) {
      state.nickLookup = window.gzBindMinecraftNickLookup({
        inputId: "checkout-nick",
        statusId: "checkout-nick-status",
        avatarId: "checkout-nick-avatar",
      });
    }
    try {
      await loadPublicKey();
      setMsg("", null);
      try {
        var meRes = await window.gzFetch("/api/auth/me.php");
        var meData = await meRes.json();
        if (meData && meData.authenticated && meData.user) {
          window.GZ_USER = meData.user;
          if ($("checkout-nick") && !$("checkout-nick").value) {
            $("checkout-nick").value = meData.user.nick || "";
            if (state.nickLookup) state.nickLookup.lookup();
          }
          if ($("checkout-email") && !$("checkout-email").value) {
            $("checkout-email").value = meData.user.email || "";
          }
        }
      } catch (ignore) {}

      var resumeId = new URLSearchParams(location.search).get("orderId");
      if (resumeId) {
        await resumePendingOrder(resumeId);
      }
    } catch (e) {
      console.error(e);
      setMsg(e.message + " (precisa PHP local/hospedagem com api/)", "error");
    }
  }

  document.addEventListener("DOMContentLoaded", init);
})();
