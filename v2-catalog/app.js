// Shared JS for the v2-catalog pages (category index + product grid).
// No SPA show/hide here — these are real multi-page PHP views now, so
// navigation is plain <a href> links. Only the ring-grid's column/row
// sizing (desktop only; mobile scrolls) and the cart icon are shared.

// --- Tracking (Meta Pixel + CAPI, Google Ads, Yandex Metrika goals) -------
// Restored from the old (deleted) static site's app.js — same IDs/worker,
// just re-wired to v2-catalog's actual pages (product.php instead of
// per-category listings, cart submission instead of a bare WhatsApp click).
const CAPI_ENDPOINT = 'https://joma-capi.joma-retail.workers.dev/event';
const GOOGLE_ADS_CONVERSION_LABELS = {
  ViewContent: 'AW-18418538749/TPupCPnC7u8cEP2x0s5E',
  Lead: 'AW-18418538749/b881CPyM4-8cEP2x0s5E',
};
// Нужны обе цели в обеих Метриках (112418009 и 113775674) — завести их
// самим именем в настройках счётчика, иначе reachGoal тихо ничего не
// делает, но и не ломается.
const YANDEX_METRIKA_IDS = [113775674, 112418009];
const YANDEX_METRIKA_GOALS = { ViewContent: 'view_content', Lead: 'lead' };

function getCookie(name) {
  const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
  return match ? decodeURIComponent(match[1]) : null;
}

function sendCapiEvent(eventName, eventId, customData) {
  const payload = JSON.stringify({
    event_name: eventName,
    event_id: eventId,
    event_source_url: window.location.href,
    user_data: {
      client_user_agent: navigator.userAgent,
      fbp: getCookie('_fbp'),
      fbc: getCookie('_fbc'),
    },
    custom_data: customData || {},
  });
  fetch(CAPI_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: payload,
    keepalive: true,
  }).catch(() => {});
}

function trackGoogleAdsConversion(eventName, customData) {
  const sendTo = GOOGLE_ADS_CONVERSION_LABELS[eventName];
  if (!sendTo || typeof window.gtag !== 'function') return;
  window.gtag('event', 'conversion', {
    send_to: sendTo,
    value: typeof customData?.value === 'number' ? customData.value : undefined,
    currency: 'KZT',
  });
}

function trackYandexGoal(eventName) {
  const goal = YANDEX_METRIKA_GOALS[eventName];
  if (!goal || typeof window.ym !== 'function') return;
  YANDEX_METRIKA_IDS.forEach((id) => window.ym(id, 'reachGoal', goal));
}

function trackEvent(eventName, customData) {
  const eventId = `${eventName}.${Date.now()}.${Math.random().toString(36).slice(2)}`;
  if (window.fbq) window.fbq('track', eventName, customData || {}, { eventID: eventId });
  sendCapiEvent(eventName, eventId, customData);
  trackGoogleAdsConversion(eventName, customData);
  trackYandexGoal(eventName);
}
window.trackEvent = trackEvent;

// Any WhatsApp link (header, footer, cart's fallback) counts as a Lead —
// the one in the cart submit flow below tracks itself directly instead
// (it's a successful form submission, not a link click).
document.addEventListener('click', (event) => {
  const waLink = event.target.closest('a[href*="wa.me"]');
  if (!waLink || waLink.dataset.capiHandled) return;
  trackEvent('Lead', { content_name: 'WhatsApp' });
});
// --- end tracking -----------------------------------------------------

// Cart panel open/close — JomaCart (cart.js, loaded via header.php on every
// page) owns the data and rendering; this just wires the chrome around it.
const cartOverlay = document.getElementById('cartOverlay');
const cartPanel = document.getElementById('cartPanel');
function openCart() {
  cartOverlay?.classList.add('is-open');
  cartPanel?.classList.add('is-open');
}
function closeCart() {
  cartOverlay?.classList.remove('is-open');
  cartPanel?.classList.remove('is-open');
}
document.getElementById('cartIconBtn')?.addEventListener('click', openCart);
document.getElementById('cartClose')?.addEventListener('click', closeCart);
cartOverlay?.addEventListener('click', closeCart);
window.addEventListener('jomacart:change', () => {
  if (typeof JomaCart !== 'undefined') JomaCart.renderPanel();
});
if (typeof JomaCart !== 'undefined') JomaCart.renderPanel();

// Submit flow: validate phone client-side (cheap, friendly), then hand off
// to JomaCart.submitLead which posts to our own api/lead.php (secret stays
// server-side there). On failure, surface the error and reveal the
// WhatsApp link as a fallback instead of leaving the visitor stuck.
const submitBtn = document.getElementById('cartSubmitBtn');
const cartError = document.getElementById('cartError');
const cartWaFallback = document.getElementById('cartWaFallback');
const cartWaLink = document.getElementById('cartWaLink');

function showCartError(message) {
  if (!cartError) return;
  cartError.textContent = message;
  cartError.hidden = false;
}
function hideCartError() {
  if (cartError) cartError.hidden = true;
}

// Comment field only matters once the visitor says they need branding —
// keep it hidden until then, matches the checkbox state on load too (in
// case the panel re-renders while it's already checked).
const cartPrintNeeded = document.getElementById('cartPrintNeeded');
const cartPrintCommentWrap = document.getElementById('cartPrintCommentWrap');
cartPrintNeeded?.addEventListener('change', () => {
  if (cartPrintCommentWrap) cartPrintCommentWrap.hidden = !cartPrintNeeded.checked;
});

submitBtn?.addEventListener('click', async () => {
  if (typeof JomaCart === 'undefined') return;
  hideCartError();
  if (cartWaFallback) cartWaFallback.hidden = true;

  const phoneInput = document.getElementById('cartPhone');
  const nameInput = document.getElementById('cartName');
  const phone = phoneInput ? phoneInput.value.trim() : '';
  const digits = phone.replace(/\D/g, '');

  if (digits.length < 10) {
    showCartError('Укажите номер телефона для связи.');
    phoneInput?.focus();
    return;
  }

  submitBtn.disabled = true;
  submitBtn.classList.add('is-loading');
  const originalLabel = submitBtn.textContent;
  submitBtn.textContent = 'Отправляем…';

  const printCommentInput = document.getElementById('cartPrintComment');
  const result = await JomaCart.submitLead(
    phone,
    nameInput ? nameInput.value.trim() : '',
    cartPrintNeeded ? cartPrintNeeded.checked : false,
    printCommentInput ? printCommentInput.value.trim() : ''
  );

  submitBtn.classList.remove('is-loading');
  submitBtn.textContent = originalLabel;

  if (result.ok) {
    trackEvent('Lead', { content_name: 'cart_submit', value: JomaCart.totalSum(), num_items: JomaCart.totalCount() });
    JomaCart.clear();
    const body = document.getElementById('cartBody');
    if (body) {
      body.innerHTML = '<p class="cart-success">Спасибо! Заявка отправлена — менеджер свяжется с вами в ближайшее время.</p>';
    }
    const summary = document.getElementById('cartSummary');
    if (summary) summary.hidden = true;
    submitBtn.hidden = true;
    if (cartPrintNeeded) cartPrintNeeded.checked = false;
    if (cartPrintCommentWrap) cartPrintCommentWrap.hidden = true;
    if (printCommentInput) printCommentInput.value = '';
  } else {
    submitBtn.disabled = false;
    const messages = {
      invalid_phone: 'Проверьте номер телефона — похоже, в нём ошибка.',
      empty_cart: 'Корзина пуста.',
      below_minimum: 'Минимальный заказ — 10 шт.',
    };
    showCartError(messages[result.error] || 'Не получилось отправить заявку. Попробуйте ещё раз или напишите нам в WhatsApp.');
    if (cartWaFallback && cartWaLink) {
      cartWaLink.href = JomaCart.whatsAppHref();
      cartWaFallback.hidden = false;
    }
  }
});

// Only the category index page has a capped, single-screen ring grid
// (14 categories fit on one screen); the per-category product grid uses
// a plain scrolling CSS grid instead (see category.php's own styles) since
// real inventory ranges from dozens to hundreds of items per category.
function layoutRingsGrid() {
  const isMobile = window.matchMedia('(max-width:720px)').matches;
  document.querySelectorAll('.rings-grid').forEach(grid => {
    if (isMobile) {
      grid.style.gridTemplateColumns = '';
      grid.style.gridTemplateRows = '';
      return;
    }
    const n = grid.querySelectorAll('.ring-card').length;
    const rows = Math.ceil(n / Math.min(5, n));
    const cols = Math.max(1, Math.ceil(n / rows));
    grid.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;
    grid.style.gridTemplateRows = `repeat(${rows}, 1fr)`;
  });
}

if (document.querySelector('.rings-grid')) {
  layoutRingsGrid();
  window.addEventListener('resize', layoutRingsGrid);
  window.addEventListener('orientationchange', () => setTimeout(layoutRingsGrid, 150));
}

