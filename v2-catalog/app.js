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
document.getElementById('cartSubmitBtn')?.addEventListener('click', () => {
  if (typeof JomaCart !== 'undefined') JomaCart.submitViaWhatsApp();
});
window.addEventListener('jomacart:change', () => {
  if (typeof JomaCart !== 'undefined') JomaCart.renderPanel();
});
if (typeof JomaCart !== 'undefined') JomaCart.renderPanel();

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

