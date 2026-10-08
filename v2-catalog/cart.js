// Client-side cart for the v2 catalog — localStorage only, no server state
// yet (the CRM's POST /api/lead integration needs a shared secret that
// isn't provisioned on this side yet; see README). Submission for now goes
// through WhatsApp with a prefilled message, same as the original v2
// prototype's own "Отправить набор в WhatsApp" design.
//
// Separate from the old site's root-level cart.js (different storage key,
// different data shape) — these are two different catalogs until the old
// one is retired.

const JomaCart = (() => {
  const STORAGE_KEY = 'jomaCartV2';
  const WHATSAPP_NUMBER = '77782683837';

  function read() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      return raw ? JSON.parse(raw) : [];
    } catch {
      return [];
    }
  }

  function write(lines) {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(lines));
    } catch {
      // Private browsing / storage blocked — cart just won't persist
      // across reloads, not fatal, the page still works this session.
    }
    window.dispatchEvent(new CustomEvent('jomacart:change', { detail: { lines } }));
  }

  function lineKey(l) {
    return l.article + '|' + l.size;
  }

  function addLine(line) {
    const lines = read();
    const key = lineKey(line);
    const existing = lines.find((l) => lineKey(l) === key);
    if (existing) {
      existing.qty += line.qty;
    } else {
      lines.push(line);
    }
    write(lines);
  }

  function setQty(article, size, qty) {
    let lines = read();
    if (qty <= 0) {
      lines = lines.filter((l) => !(l.article === article && l.size === size));
    } else {
      const existing = lines.find((l) => l.article === article && l.size === size);
      if (existing) existing.qty = qty;
    }
    write(lines);
  }

  function removeLine(article, size) {
    setQty(article, size, 0);
  }

  function clear() {
    write([]);
  }

  function totalCount() {
    return read().reduce((sum, l) => sum + l.qty, 0);
  }

  function totalSum() {
    return read().reduce((sum, l) => sum + l.qty * l.unitPrice, 0);
  }

  function fmtTenge(n) {
    return Math.round(n).toLocaleString('ru-RU').replace(/,/g, ' ') + ' ₸';
  }

  // Groups by model+color (one product card in the panel), sizes listed
  // underneath — matches the original prototype's cart mockup layout.
  function groupedLines() {
    const lines = read();
    const byArticle = new Map();
    for (const l of lines) {
      if (!byArticle.has(l.article)) {
        byArticle.set(l.article, { ...l, sizes: [] });
      }
      byArticle.get(l.article).sizes.push({ size: l.size, qty: l.qty });
    }
    return Array.from(byArticle.values());
  }

  function renderPanel() {
    const body = document.getElementById('cartBody');
    const badge = document.getElementById('cartIconBadge');
    const summary = document.getElementById('cartSummary');
    const submitBtn = document.getElementById('cartSubmitBtn');
    if (!body) return;

    const groups = groupedLines();
    const count = totalCount();

    if (badge) {
      badge.textContent = String(count);
      badge.style.display = count > 0 ? 'flex' : 'none';
    }

    if (groups.length === 0) {
      body.innerHTML = '<p class="cart-empty">Пока пусто — добавьте товары из каталога.</p>';
      if (summary) summary.hidden = true;
      if (submitBtn) submitBtn.disabled = true;
      return;
    }

    if (summary) summary.hidden = false;
    if (submitBtn) submitBtn.disabled = false;

    body.innerHTML = groups
      .map((g) => {
        const lineQty = g.sizes.reduce((s, x) => s + x.qty, 0);
        const lineTotal = lineQty * g.unitPrice;
        const sizesHtml = g.sizes
          .map(
            (s) => `<span>${esc(s.size)} × ${s.qty} <button class="size-remove" data-remove-article="${esc(g.article)}" data-remove-size="${esc(s.size)}" aria-label="Убрать">×</button></span>`
          )
          .join('');
        return `
          <div class="cart-item-row">
            <img src="${esc(g.photo)}" alt="" onerror="this.remove()">
            <div class="cart-item-info">
              <p class="cart-item-category">${esc(g.category)}</p>
              <p class="cart-item-name">${esc(g.name)} · ${esc(g.color)}</p>
              <div class="size-breakdown">${sizesHtml}</div>
              <p class="cart-item-total">${lineQty} шт · ${fmtTenge(lineTotal)}</p>
            </div>
          </div>`;
      })
      .join('');

    body.querySelectorAll('[data-remove-article]').forEach((btn) => {
      btn.addEventListener('click', () => {
        removeLine(btn.dataset.removeArticle, btn.dataset.removeSize);
      });
    });

    if (summary) {
      summary.querySelector('.value').textContent = fmtTenge(totalSum());
      summary.querySelector('.label').textContent = `Итого · ${count} шт`;
    }
  }

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
  }

  function buildWhatsAppMessage() {
    const groups = groupedLines();
    const teamInput = document.getElementById('cartTeamName');
    const team = teamInput && teamInput.value.trim() ? teamInput.value.trim() : '';
    let text = 'Здравствуйте! Хочу оформить заявку';
    if (team) text += ` для команды «${team}»`;
    text += ':\n\n';
    for (const g of groups) {
      const sizesText = g.sizes.map((s) => `${s.size} × ${s.qty}`).join(', ');
      text += `• ${g.name} (${g.color}) — ${sizesText}\n`;
    }
    text += `\nИтого: ${fmtTenge(totalSum())}`;
    return text;
  }

  function submitViaWhatsApp() {
    const text = buildWhatsAppMessage();
    window.open(`https://wa.me/${WHATSAPP_NUMBER}?text=${encodeURIComponent(text)}`, '_blank');
  }

  return { addLine, setQty, removeLine, clear, totalCount, totalSum, renderPanel, submitViaWhatsApp };
})();
