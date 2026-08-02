(function () {
  "use strict";

  var state = {
    vip: "lacoste",
    amount: "29.90",
    title: "VIP Lacoste",
    publicKey: null,
    mp: null,
    cardForm: null,
    orderId: null,
    pollTimer: null,
    nick: "",
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
    var vip = new URLSearchParams(location.search).get("vip") || "lacoste";
    var pack = packs[vip] || packs.lacoste || packs[Object.keys(packs)[0]];
    if (!pack) return;
    state.vip = vip;
    state.title = pack.titulo;
    state.amount = String(pack.amount || "29.90");
    if ($("checkout-resumo")) {
      $("checkout-resumo").textContent = pack.titulo + " — " + money(state.amount);
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
      "Pagamento confirmado para " + (data.nick || state.nick || "seu nick") +
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

  async function submitPix() {
    var nick = ($("checkout-nick") || {}).value || "";
    var email = ($("checkout-email") || {}).value || "";
    var cpf = ($("checkout-cpf") || {}).value || "";

    state.nick = nick.trim();
    setMsg("Gerando Pix...", null);
    if (window.gzSetBtnLoading) window.gzSetBtnLoading($("btn-pay-pix"), true);
    else $("btn-pay-pix").disabled = true;

    try {
      var data = await createOrder({
        vip: state.vip,
        method: "pix",
        nick: nick.trim(),
        email: email.trim(),
        cpf: cpf.trim(),
      });

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

    var nick = ($("checkout-nick") || {}).value || "";
    state.nick = nick.trim();

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
      var data = await createOrder({
        vip: state.vip,
        method: "credit_card",
        nick: nick.trim(),
        email: cardData.cardholderEmail || (($("checkout-email") || {}).value || ""),
        cpf: cardData.identificationNumber || (($("checkout-cpf") || {}).value || ""),
        cardToken: cardData.token,
        paymentMethodId: cardData.paymentMethodId,
        installments: Number(cardData.installments || 1),
        issuerId: cardData.issuerId,
      });

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
  }

  async function init() {
    if (!$("checkout-root")) return;
    await ensureCatalog();
    syncPackFromPage();
    bindUI();
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
          }
          if ($("checkout-email") && !$("checkout-email").value) {
            $("checkout-email").value = meData.user.email || "";
          }
        }
      } catch (ignore) {}
    } catch (e) {
      console.error(e);
      setMsg(e.message + " (precisa PHP local/hospedagem com api/)", "error");
    }
  }

  document.addEventListener("DOMContentLoaded", init);
})();
