const CART_STORAGE_KEY = 'joma_cart_v1';
const CART_PHONE = '77080298284';
const QUOTE_ENDPOINT = 'https://joma-capi.joma-retail.workers.dev/quote';

// sendBeacon survives the page being backgrounded/unloaded (e.g. the OS
// handing off to the WhatsApp app on mobile right after this click), unlike
// a plain fetch — even with keepalive — which can get cut off mid-flight.
function sendQuoteBeacon(team, items) {
  const payload = JSON.stringify({ team, items });
  if (navigator.sendBeacon) {
    const blob = new Blob([payload], { type: 'application/json' });
    if (navigator.sendBeacon(QUOTE_ENDPOINT, blob)) return;
  }
  fetch(QUOTE_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: payload,
    keepalive: true,
  }).catch(() => {});
}

function cartRead() {
  try {
    const raw = localStorage.getItem(CART_STORAGE_KEY);
    const items = raw ? JSON.parse(raw) : [];
    return Array.isArray(items) ? items : [];
  } catch {
    return [];
  }
}

function cartWrite(items) {
  try {
    localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(items));
  } catch {
    // storage unavailable (private mode, quota) — cart just won't persist
  }
}

function cartAdd(category, model, img) {
  const items = cartRead();
  const idx = items.findIndex((item) => item.category === category && item.model === model);
  if (idx >= 0) {
    items[idx].qty += 1;
  } else {
    items.push({ category, model, img, qty: 10 });
  }
  cartWrite(items);
  cartRenderAll();
}

function cartSetQty(index, qty) {
  const items = cartRead();
  if (!items[index]) return;
  items[index].qty = Math.max(1, qty);
  cartWrite(items);
  cartRenderAll();
}

function cartRemove(index) {
  const items = cartRead();
  items.splice(index, 1);
  cartWrite(items);
  cartRenderAll();
}

function cartBuildMessage(items, team) {
  const lines = ['Здравствуйте! Хочу заказать набор экипировки:'];
  items.forEach((item, i) => {
    lines.push(`${i + 1}. ${item.category} — ${item.model} — ${item.qty} шт`);
  });
  if (team) lines.push(`Команда: ${team}`);
  lines.push('Пришлите, пожалуйста, расчёт.');
  return lines.join('\n');
}

function ensureCartWidget() {
  if (document.querySelector('.cart-toggle')) return;

  const overlay = document.createElement('div');
  overlay.className = 'cart-overlay';

  const toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.className = 'cart-toggle';
  toggle.setAttribute('aria-label', 'Продолжить с набором экипировки');
  toggle.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M4 6h16l-1.5 10.5a2 2 0 0 1-2 1.5H7.5a2 2 0 0 1-2-1.5L4 6Z"/><path d="M8 6V5a4 4 0 0 1 8 0v1"/></svg><span class="cart-badge">0</span><span>Продолжить с набором →</span>';

  const panel = document.createElement('div');
  panel.className = 'cart-panel';
  panel.innerHTML = `
    <div class="cart-panel-header">
      <div>
        <p class="funnel-step">Шаг 3 из 3 · Отправка</p>
        <h2>Ваш набор</h2>
      </div>
      <button type="button" class="cart-panel-close" aria-label="Закрыть">×</button>
    </div>
    <div class="cart-items"></div>
    <label class="cart-team-field">Название команды (необязательно)
      <input type="text" class="cart-team" placeholder="Например, FC Astana">
    </label>
    <a class="button button-light cart-submit" href="https://wa.me/${CART_PHONE}" target="_blank" rel="noopener" data-capi-handled="true">Отправить набор в WhatsApp <span>→</span></a>
  `;

  document.body.appendChild(overlay);
  document.body.appendChild(toggle);
  document.body.appendChild(panel);

  function openPanel() {
    overlay.classList.add('is-open');
    panel.classList.add('is-open');
  }
  function closePanel() {
    overlay.classList.remove('is-open');
    panel.classList.remove('is-open');
  }

  toggle.addEventListener('click', openPanel);
  overlay.addEventListener('click', closePanel);
  panel.querySelector('.cart-panel-close').addEventListener('click', closePanel);

  const teamInput = panel.querySelector('.cart-team');
  const submitLink = panel.querySelector('.cart-submit');

  function updateSubmitLink() {
    const items = cartRead();
    const message = cartBuildMessage(items, teamInput.value.trim());
    submitLink.setAttribute('href', `https://wa.me/${CART_PHONE}?text=${encodeURIComponent(message)}`);
  }
  teamInput.addEventListener('input', updateSubmitLink);

  submitLink.addEventListener('click', () => {
    const items = cartRead();
    if (!items.length) return;
    if (typeof trackEvent === 'function') {
      trackEvent('Lead', {
        content_name: 'Набор из каталога',
        content_category: [...new Set(items.map((item) => item.category))].join(', '),
        value: items.reduce((sum, item) => sum + item.qty, 0),
      });
    }
    sendQuoteBeacon(teamInput.value.trim(), items);
    // Clear the cart AFTER this tick — the browser reads the link's href to
    // open WhatsApp only once this click handler finishes, so clearing (and
    // re-rendering, which rewrites this same href) immediately would send an
    // empty message instead of the list the visitor just built.
    window.setTimeout(() => {
      cartWrite([]);
      cartRenderAll();
      closePanel();
    }, 0);
  });

  window.cartUpdateSubmitLink = updateSubmitLink;
}

function cartRenderAll() {
  ensureCartWidget();
  const items = cartRead();

  const toggle = document.querySelector('.cart-toggle');
  const badge = document.querySelector('.cart-badge');
  if (toggle && badge) {
    badge.textContent = String(items.length);
    toggle.classList.toggle('is-visible', items.length > 0);
  }

  const list = document.querySelector('.cart-items');
  if (list) {
    if (!items.length) {
      list.innerHTML = '<p class="cart-empty">Пока пусто — добавьте модели из каталога кнопкой «+» на карточке товара.</p>';
    } else {
      list.innerHTML = '';
      items.forEach((item, index) => {
        const row = document.createElement('div');
        row.className = 'cart-item-row';
        row.innerHTML = `
          <img class="cart-item-img" src="${item.img}" alt="${item.model}">
          <div class="cart-item-info">
            <p class="cart-item-category">${item.category}</p>
            <p class="cart-item-name">${item.model}</p>
            <div class="cart-item-qty">
              <button type="button" class="cart-qty-btn" data-action="dec">−</button>
              <span>${item.qty}</span>
              <button type="button" class="cart-qty-btn" data-action="inc">+</button>
            </div>
          </div>
          <button type="button" class="cart-item-remove" aria-label="Убрать">×</button>
        `;
        row.querySelector('[data-action="dec"]').addEventListener('click', () => cartSetQty(index, item.qty - 1));
        row.querySelector('[data-action="inc"]').addEventListener('click', () => cartSetQty(index, item.qty + 1));
        row.querySelector('.cart-item-remove').addEventListener('click', () => cartRemove(index));
        list.appendChild(row);
      });
    }
  }

  if (typeof window.cartUpdateSubmitLink === 'function') window.cartUpdateSubmitLink();
}

function initCatalogCart() {
  ensureCartWidget();
  cartRenderAll();

  const heading = document.querySelector('.catalog-list-hero h1');
  if (!heading) return;
  const category = heading.textContent.trim();

  document.querySelectorAll('.catalog-list-grid .catalog-item').forEach((card) => {
    if (card.querySelector('.catalog-add-btn')) return;
    const img = card.querySelector('img');
    // Scoped to .catalog-item-link — a bare 'span' would match the price-tier
    // badge (Бюджет/Средний/Премиум) instead, since it's the first <span> in the card.
    const nameEl = card.querySelector('.catalog-item-link span');
    if (!img || !nameEl) return;
    const model = nameEl.textContent.trim();
    const sportGroupTitle = card.closest('.catalog-category')?.querySelector('.catalog-category-title')?.textContent.trim();
    const cardCategory = sportGroupTitle ? `${category} — ${sportGroupTitle}` : category;

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'catalog-add-btn';
    btn.setAttribute('aria-label', `Добавить ${model} в набор`);
    btn.textContent = '+';
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      // Read the image fresh: the visitor may have cycled color variants
      // (prev/next arrows or dots) before adding this card to the set.
      const currentImg = img.getAttribute('src') || card.dataset.main;
      cartAdd(cardCategory, model, currentImg);
      btn.classList.add('is-added');
      btn.textContent = '✓';
      window.setTimeout(() => {
        btn.classList.remove('is-added');
        btn.textContent = '+';
      }, 900);
    });
    card.appendChild(btn);
  });
}

window.initCatalogCart = initCatalogCart;
initCatalogCart();
