// Shared JS for the v2-catalog pages (category index + product grid).
// No SPA show/hide here — these are real multi-page PHP views now, so
// navigation is plain <a href> links. Only the ring-grid's column/row
// sizing (desktop only; mobile scrolls) and the cart icon are shared.

document.getElementById('cartIconBtn')?.addEventListener('click', () => {
  // Cart isn't wired to this catalog yet — next step after the product
  // grid itself. Placeholder so the button isn't silently dead.
  alert('Корзина подключается следующим шагом.');
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

