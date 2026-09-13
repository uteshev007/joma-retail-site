function initSportFilter() {
  const filter = document.querySelector('[data-sport-filter]');
  const groupsWrap = document.querySelector('.sport-groups');
  if (!filter || !groupsWrap) return;

  const choices = filter.querySelectorAll('.sport-filter-btn');
  const groups = groupsWrap.querySelectorAll('.catalog-category');
  const backBtn = groupsWrap.querySelector('.sport-back-btn');

  function showSport(sport) {
    filter.hidden = true;
    groupsWrap.hidden = false;
    groups.forEach((group) => {
      group.hidden = group.dataset.sport !== sport;
    });
    groupsWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function reset() {
    filter.hidden = false;
    groupsWrap.hidden = true;
  }

  choices.forEach((btn) => {
    btn.addEventListener('click', () => showSport(btn.dataset.sport));
  });
  if (backBtn) backBtn.addEventListener('click', reset);

  reset();
}

window.initSportFilter = initSportFilter;
initSportFilter();
