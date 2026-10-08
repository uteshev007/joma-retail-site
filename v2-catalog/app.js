// Shared JS for the v2-catalog pages (category index + product grid).
// No SPA show/hide here — these are real multi-page PHP views now, so
// navigation is plain <a href> links. Only the ring-grid's column/row
// sizing (desktop only; mobile scrolls) and the cart icon are shared.

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

  const result = await JomaCart.submitLead(phone, nameInput ? nameInput.value.trim() : '');

  submitBtn.classList.remove('is-loading');
  submitBtn.textContent = originalLabel;

  if (result.ok) {
    JomaCart.clear();
    const body = document.getElementById('cartBody');
    if (body) {
      body.innerHTML = '<p class="cart-success">Спасибо! Заявка отправлена — менеджер свяжется с вами в ближайшее время.</p>';
    }
    const summary = document.getElementById('cartSummary');
    if (summary) summary.hidden = true;
    submitBtn.hidden = true;
  } else {
    submitBtn.disabled = false;
    const messages = {
      invalid_phone: 'Проверьте номер телефона — похоже, в нём ошибка.',
      empty_cart: 'Корзина пуста.',
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

