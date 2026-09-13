// --- Meta Pixel + Conversions API ---------------------------------------
// Fill in after creating the Pixel in Meta Events Manager and deploying the
// Cloudflare Worker in capi-worker/ (see that folder for setup steps).
const META_PIXEL_ID = '1608737524200147';
const CAPI_ENDPOINT = 'https://joma-capi.joma-retail.workers.dev/event';

(function initMetaPixel() {
  if (META_PIXEL_ID.startsWith('REPLACE_')) return;
  /* eslint-disable */
  !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
  n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
  document,'script','https://connect.facebook.net/en_US/fbevents.js');
  /* eslint-enable */
  window.fbq('init', META_PIXEL_ID);
  window.fbq('track', 'PageView');
})();

// --- Google Ads conversion tracking -------------------------------------
const GOOGLE_ADS_ID = 'AW-18418538749';
const GOOGLE_ADS_CONVERSION_LABELS = {
  ViewContent: 'AW-18418538749/TPupCPnC7u8cEP2x0s5E',
  Lead: 'AW-18418538749/b881CPyM4-8cEP2x0s5E',
};

(function initGoogleAds() {
  window.dataLayer = window.dataLayer || [];
  window.gtag = window.gtag || function gtag() { window.dataLayer.push(arguments); };
  const script = document.createElement('script');
  script.async = true;
  script.src = `https://www.googletagmanager.com/gtag/js?id=${GOOGLE_ADS_ID}`;
  document.head.appendChild(script);
  window.gtag('js', new Date());
  window.gtag('config', GOOGLE_ADS_ID);
})();

function trackGoogleAdsConversion(eventName, customData) {
  const sendTo = GOOGLE_ADS_CONVERSION_LABELS[eventName];
  if (!sendTo || typeof window.gtag !== 'function') return;
  window.gtag('event', 'conversion', {
    send_to: sendTo,
    value: typeof customData?.value === 'number' ? customData.value : undefined,
    currency: 'KZT',
  });
}
// --- end Google Ads conversion tracking ----------------------------------

// --- Yandex Metrika --------------------------------------------------------
const YANDEX_METRIKA_ID = 112418009;
const YANDEX_METRIKA_GOALS = {
  ViewContent: 'view_content',
  Lead: 'lead',
};

(function initYandexMetrika() {
  /* eslint-disable */
  (function(m,e,t,r,i,k,a){
    m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
    m[i].l=1*new Date();
    for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
    k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)
  })(window, document,'script','https://mc.yandex.ru/metrika/tag.js?id=' + YANDEX_METRIKA_ID, 'ym');
  /* eslint-enable */
  window.ym(YANDEX_METRIKA_ID, 'init', {
    ssr: true,
    webvisor: true,
    clickmap: true,
    ecommerce: 'dataLayer',
    referrer: document.referrer,
    url: location.href,
    accurateTrackBounce: true,
    trackLinks: true,
  });
})();

function trackYandexGoal(eventName) {
  const goal = YANDEX_METRIKA_GOALS[eventName];
  if (goal && typeof window.ym === 'function') {
    window.ym(YANDEX_METRIKA_ID, 'reachGoal', goal);
  }
}
// --- end Yandex Metrika ----------------------------------------------------

function getCookie(name) {
  const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
  return match ? decodeURIComponent(match[1]) : null;
}

// Sends the same event server-side via our Conversions API relay, using the
// same event_id as the browser Pixel call so Meta deduplicates the two.
function sendCapiEvent(eventName, eventId, customData) {
  if (META_PIXEL_ID.startsWith('REPLACE_') || CAPI_ENDPOINT.includes('example.workers.dev')) return;
  const payload = {
    event_name: eventName,
    event_id: eventId,
    event_source_url: window.location.href,
    user_data: {
      client_user_agent: navigator.userAgent,
      fbp: getCookie('_fbp'),
      fbc: getCookie('_fbc'),
    },
    custom_data: customData || {},
  };
  fetch(CAPI_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
    keepalive: true,
  }).catch(() => {});
}

function trackEvent(eventName, customData) {
  const eventId = `${eventName}.${Date.now()}.${Math.random().toString(36).slice(2)}`;
  if (window.fbq) window.fbq('track', eventName, customData || {}, { eventID: eventId });
  sendCapiEvent(eventName, eventId, customData);
  trackGoogleAdsConversion(eventName, customData);
  trackYandexGoal(eventName);
}

// Category catalog pages (catalog-formal.html etc.) carry a clean single-line
// heading inside .catalog-list-hero — use it as the WhatsApp/ViewContent context.
function getPageCategory() {
  const heading = document.querySelector('.catalog-list-hero h1');
  return heading ? heading.textContent.trim() : null;
}

// "Командная экипировка" is the catalog section — count opening any of its
// pages as ViewContent, using the specific category where available.
function trackViewContentIfCatalog(pathname) {
  const category = getPageCategory();
  if (category) {
    trackEvent('ViewContent', { content_name: category, content_category: 'catalog' });
    return;
  }
  if (pathname.endsWith('/catalog.html') || pathname === '/catalog.html') {
    trackEvent('ViewContent', { content_name: 'Командная экипировка', content_category: 'catalog' });
  }
}

function buildWhatsappUrl(category) {
  const text = category
    ? `Здравствуйте! Интересует ${category.toLowerCase()} — пришлите, пожалуйста, расчёт.`
    : 'Здравствуйте, интересует командная экипировка.';
  return `https://wa.me/77080298284?text=${encodeURIComponent(text)}`;
}

// Any WhatsApp link (header, hero, footer, catalog pages) counts as a Lead.
// Links the cart/configurator already track themselves (data-capi-handled) are
// skipped here to avoid double-counting. If the visitor has already built a
// set in the cart, ANY WhatsApp button — including the header/footer icons —
// sends that full list instead of a generic one-line message.
document.addEventListener('click', (event) => {
  const waLink = event.target.closest('a[href*="wa.me"]');
  if (!waLink || waLink.dataset.capiHandled) return;

  if (typeof cartRead === 'function') {
    const cartItems = cartRead();
    if (cartItems.length) {
      const teamInput = document.querySelector('.cart-team');
      const message = cartBuildMessage(cartItems, teamInput ? teamInput.value.trim() : '');
      waLink.setAttribute('href', `https://wa.me/77080298284?text=${encodeURIComponent(message)}`);
      trackEvent('Lead', {
        content_name: 'Набор из каталога',
        content_category: [...new Set(cartItems.map((item) => item.category))].join(', '),
        value: cartItems.reduce((sum, item) => sum + item.qty, 0),
      });
      cartWrite([]);
      if (typeof cartRenderAll === 'function') cartRenderAll();
      return;
    }
  }

  const category = getPageCategory();
  waLink.setAttribute('href', buildWhatsappUrl(category));
  trackEvent('Lead', category ? { content_name: 'WhatsApp', content_category: category } : { content_name: 'WhatsApp' });
});

trackViewContentIfCatalog(window.location.pathname);
// --- end Meta Pixel + Conversions API -----------------------------------

const siteLoader = document.querySelector('.site-loader');

function initResponsiveImages() {
  const responsiveMq = window.matchMedia('(max-width: 800px)');
  document.querySelectorAll('.responsive-media-img').forEach((img) => {
    const setImage = () => {
      const nextSrc = responsiveMq.matches ? img.dataset.mobileSrc : img.dataset.desktopSrc;
      if (!img.src.endsWith(nextSrc)) img.src = nextSrc;
    };
    setImage();
    responsiveMq.addEventListener('change', setImage);
  });
}

function initCatalogTilt() {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  document.querySelectorAll('.catalog-item').forEach((card) => {
    card.addEventListener('mousemove', (event) => {
      const rect = card.getBoundingClientRect();
      const px = (event.clientX - rect.left) / rect.width - 0.5;
      const py = (event.clientY - rect.top) / rect.height - 0.5;
      card.style.transform = `translateY(-6px) rotateX(${(-py * 14).toFixed(2)}deg) rotateY(${(px * 14).toFixed(2)}deg) scale(1.04)`;
    });
    card.addEventListener('mouseleave', () => {
      card.style.transform = '';
    });
  });
}

// Cards with data-variants can be paged manually through their other
// colorways with the prev/next arrows or the dot indicators; resets to the
// cover image on mouseleave.
function initCatalogVariantCycle() {
  document.querySelectorAll('.catalog-item[data-variants]').forEach((card) => {
    let variants;
    try {
      variants = JSON.parse(card.dataset.variants);
    } catch {
      variants = [];
    }
    if (!Array.isArray(variants) || variants.length === 0) return;
    const img = card.querySelector('img');
    const mainSrc = card.dataset.main || img.src;
    const frames = [mainSrc, ...variants];
    const prevBtn = card.querySelector('.catalog-nav-prev');
    const nextBtn = card.querySelector('.catalog-nav-next');
    const dotsWrap = card.querySelector('.catalog-dots');

    let frameIndex = 0;

    if (dotsWrap) {
      frames.forEach((_, i) => {
        const dot = document.createElement('button');
        dot.type = 'button';
        dot.className = 'catalog-dot' + (i === 0 ? ' active' : '');
        dot.setAttribute('aria-label', `Цвет ${i + 1}`);
        dot.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          goTo(i);
        });
        dotsWrap.appendChild(dot);
      });
    }

    function setFrame(index) {
      frameIndex = (index + frames.length) % frames.length;
      img.src = frames[frameIndex];
      if (dotsWrap) {
        dotsWrap.querySelectorAll('.catalog-dot').forEach((dot, i) => {
          dot.classList.toggle('active', i === frameIndex);
        });
      }
    }

    function goTo(index) {
      setFrame(index);
    }

    card.addEventListener('mouseleave', () => setFrame(0));

    prevBtn?.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      goTo(frameIndex - 1);
    });
    nextBtn?.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      goTo(frameIndex + 1);
    });
  });
}

let headerObserver = null;
function initHeaderObserver() {
  if (headerObserver) headerObserver.disconnect();
  const heroSection = document.querySelector('#hero');
  if (heroSection) {
    const headerHeight = document.querySelector('.site-header').offsetHeight;
    headerObserver = new IntersectionObserver(([entry]) => {
      document.body.classList.toggle('header-solid', !entry.isIntersecting);
    }, { rootMargin: `-${headerHeight}px 0px 0px 0px`, threshold: 0 });
    headerObserver.observe(heroSection);
  } else {
    document.body.classList.remove('header-solid');
  }
}

function initScrollReveal() {
  const revealObserver = new IntersectionObserver((entries, observer) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('is-visible');
      observer.unobserve(entry.target);
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -40px' });

  // footer lives outside <main> and survives client-side page swaps, so guard
  // it against being re-observed (and re-animated) on every navigation.
  document.querySelectorAll('main > section, footer').forEach((element) => {
    element.classList.add('scroll-reveal');
    if (element.dataset.revealBound) return;
    element.dataset.revealBound = 'true';
    revealObserver.observe(element);
  });
}

if (siteLoader) {
  window.addEventListener('load', () => {
    requestAnimationFrame(() => siteLoader.classList.add('is-active'));
    window.setTimeout(() => {
      siteLoader.classList.add('is-leaving');
      document.body.classList.remove('is-loading');
      window.dispatchEvent(new Event('site-loader-done'));
      window.setTimeout(() => siteLoader.remove(), 150);
    }, 1600);
  });
}

function initFooterLogo() {
  const footerLogo = document.querySelector('.footer-logo');
  if (!footerLogo || footerLogo.dataset.revealBound) return;
  footerLogo.dataset.revealBound = 'true';
  const observeFooterLogo = () => {
    const revealFooterLogo = new IntersectionObserver((entries, observer) => {
      if (!entries[0].isIntersecting) return;
      footerLogo.classList.add('is-visible');
      observer.disconnect();
    }, { threshold: 0.35 });
    revealFooterLogo.observe(footerLogo);
  };
  if (siteLoader) window.addEventListener('site-loader-done', observeFooterLogo, { once: true });
  else observeFooterLogo();
}

// Everything that depends on the current <main> content — re-run after every
// client-side page swap so newly injected markup gets the same behavior.
function initMainContent() {
  initResponsiveImages();
  initCatalogTilt();
  initCatalogVariantCycle();
  initHeaderObserver();
  initScrollReveal();
  initFooterLogo();
  window.initConfigurator?.();
  window.initCatalogCart?.();
  window.initSportFilter?.();
}

initMainContent();

// Client-side navigation: swap <main> instead of doing a full page reload
// when moving between pages.
function stripLoadingClass(className) {
  return className.split(' ').filter((token) => token && token !== 'is-loading').join(' ');
}

async function navigate(url, { push = true } = {}) {
  const target = new URL(url, window.location.href);
  let html;
  try {
    const response = await fetch(target.pathname + target.search, { cache: 'no-store' });
    html = await response.text();
  } catch {
    window.location.href = target.href;
    return;
  }
  const newDoc = new DOMParser().parseFromString(html, 'text/html');
  const newMain = newDoc.querySelector('main');
  const oldMain = document.querySelector('main');
  const newHeader = newDoc.querySelector('.site-header');
  const oldHeader = document.querySelector('.site-header');
  if (!newMain || !oldMain) {
    window.location.href = target.href;
    return;
  }
  document.head.innerHTML = newDoc.head.innerHTML;
  if (newHeader && oldHeader) oldHeader.replaceWith(newHeader);
  oldMain.replaceWith(newMain);
  document.body.className = stripLoadingClass(newDoc.body.className);
  if (push) history.pushState({ url: target.href }, '', target.href);
  if (target.hash) {
    const el = document.getElementById(target.hash.slice(1));
    if (el) el.scrollIntoView({ behavior: 'auto', block: 'start' });
    else window.scrollTo(0, 0);
  } else {
    window.scrollTo(0, 0);
  }
  initMainContent();
  if (window.fbq) window.fbq('track', 'PageView');
  if (typeof window.ym === 'function') window.ym(YANDEX_METRIKA_ID, 'hit', target.href);
  trackViewContentIfCatalog(target.pathname);
}

document.addEventListener('click', (event) => {
  if (event.defaultPrevented || event.button !== 0) return;
  if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
  const link = event.target.closest('a');
  if (!link) return;
  if (link.target && link.target !== '_self') return;
  if (link.hasAttribute('download')) return;
  const href = link.getAttribute('href');
  if (!href) return;
  let url;
  try {
    url = new URL(href, window.location.href);
  } catch {
    return;
  }
  if (url.origin !== window.location.origin) return;
  if (!/\.html?$/.test(url.pathname)) return;
  event.preventDefault();
  navigate(url.href);
});

window.addEventListener('popstate', () => {
  navigate(window.location.href, { push: false });
});

// Local preview: reload the browser whenever one of the page files changes.
if (location.hostname === 'localhost') {
  const previewFiles = ['index.html', 'styles.css', 'app.js', 'catalog.html'];
  let previewSignature = '';

  const getPreviewSignature = async () => {
    const contents = await Promise.all(previewFiles.map(async (file) => {
      const response = await fetch(`${file}?preview=${Date.now()}`, { cache: 'no-store' });
      return response.text();
    }));
    return contents.join(' ');
  };

  getPreviewSignature().then((signature) => {
    previewSignature = signature;
    window.setInterval(async () => {
      try {
        const nextSignature = await getPreviewSignature();
        if (nextSignature !== previewSignature) location.reload();
      } catch {
        // The server may be restarting while files are being saved.
      }
    }, 1200);
  });
}
