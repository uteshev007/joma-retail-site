// Client-side cart for the v2 catalog — localStorage only (no server-side
// session). Submission posts to our own api/lead.php, which signs the
// request server-side and forwards it to the CRM (раздел 12.1 ТЗ CRM) —
// the shared secret never reaches this client-side code. WhatsApp is kept
// as a visible fallback link if that submission fails for any reason.
//
// Separate from the old site's root-level cart.js (different storage key,
// different data shape) — these are two different catalogs until the old
// one is retired.

const JomaCart = (() => {
  const STORAGE_KEY = 'jomaCartV2';
  const WHATSAPP_NUMBER = '77782683837';
  // Оптовый минимум — меньше не отгружаем, см. требование "минимальный
  // закуп от 10шт, не меньше".
  const MIN_ORDER_QTY = 10;

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

  // So a product page can show "you already have 3 of these in your cart"
  // when enforcing the stock cap, instead of only knowing about clicks
  // made in the current page view.
  function getLineQty(article, size) {
    const line = read().find((l) => l.article === article && l.size === size);
    return line ? line.qty : 0;
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

    const belowMin = count < MIN_ORDER_QTY;
    if (summary) summary.hidden = false;
    if (submitBtn) {
      submitBtn.disabled = belowMin;
      // Un-hide in case a previous successful submission hid it (see
      // app.js's post-submit handling) — a fresh add-to-cart after that
      // should bring the button back.
      submitBtn.hidden = false;
    }

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

    const minNotice = document.getElementById('cartMinNotice');
    if (minNotice) {
      if (belowMin) {
        const left = MIN_ORDER_QTY - count;
        minNotice.textContent = `Минимальный заказ — ${MIN_ORDER_QTY} шт. Добавьте ещё ${left} ${pluralPiece(left)}.`;
        minNotice.hidden = false;
      } else {
        minNotice.hidden = true;
      }
    }
  }

  function pluralPiece(n) {
    const mod10 = n % 10;
    const mod100 = n % 100;
    if (mod10 === 1 && mod100 !== 11) return 'штуку';
    if ([2, 3, 4].includes(mod10) && ![12, 13, 14].includes(mod100)) return 'штуки';
    return 'штук';
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
    const printCheckbox = document.getElementById('cartPrintNeeded');
    if (printCheckbox && printCheckbox.checked) {
      const printComment = document.getElementById('cartPrintComment');
      const comment = printComment && printComment.value.trim() ? ` (${printComment.value.trim()})` : '';
      text += `\n\nНужно нанесение (логотип/принт)${comment}`;
    }
    return text;
  }

  function submitViaWhatsApp() {
    const text = buildWhatsAppMessage();
    window.open(`https://wa.me/${WHATSAPP_NUMBER}?text=${encodeURIComponent(text)}`, '_blank');
  }

  function whatsAppHref() {
    return `https://wa.me/${WHATSAPP_NUMBER}?text=${encodeURIComponent(buildWhatsAppMessage())}`;
  }

  // Доработка 16.10.2026: раньше сюда сводилось только {category, qty} —
  // артикул, размер, цвет терялись, хотя в самой корзине (строки lines)
  // всё это есть. CRM (раздел 12.1, доработка 16.10.2026 (13)) теперь
  // умеет брать точный артикул+qty из каждой строки корзины — поэтому
  // шлём сюда полные строки как есть, без сведения по категории.
  function leadCartLines() {
    return read().map((l) => ({
      article: l.article,
      model: l.modelNumber || null,
      name: l.name,
      category: l.category,
      color: l.color || null,
      size: l.size || null,
      qty: l.qty,
      unitPrice: l.unitPrice || 0,
    }));
  }

  /**
   * Submits the lead. Returns a Promise resolving to
   * {ok: true} or {ok: false, error: string} — never rejects, so the
   * caller doesn't need its own try/catch for the network-failure case.
   *
   * printNeeded/printComment: нанесение (лого/принт) — сайт только
   * фиксирует намерение клиента и свободный комментарий (место/логотип),
   * саму логику выбора мест нанесения и расчёт цены (от 1500тг + 500тг за
   * каждое место) делает менеджер в CRM при подборе (app/Selection.php) —
   * сайт её не дублирует.
   */
  async function submitLead(phone, name, printNeeded, printComment) {
    const cart = leadCartLines();
    if (cart.length === 0) {
      return { ok: false, error: 'empty_cart' };
    }
    if (totalCount() < MIN_ORDER_QTY) {
      return { ok: false, error: 'below_minimum' };
    }
    const payload = {
      phone,
      name: name || null,
      cart,
      printNeeded: !!printNeeded,
      printComment: printComment || null,
      utm: null,
      fbp: readCookie('_fbp'),
      fbc: readCookie('_fbc'),
      page: window.location.href,
    };
    try {
      const res = await fetch('api/lead.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data || data.ok === false) {
        return { ok: false, error: (data && data.error) || 'request_failed' };
      }
      return { ok: true };
    } catch {
      return { ok: false, error: 'network_error' };
    }
  }

  function readCookie(name) {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
  }

  return {
    addLine, setQty, removeLine, clear, totalCount, totalSum, renderPanel,
    submitViaWhatsApp, getLineQty, submitLead, whatsAppHref,
  };
})();
