const CONFIGURATOR_PHONE = '77080298284';

const CONFIGURATOR_CATEGORIES = [
  { slug: 'formal', title: 'Парадная форма', tileImage: 'images/product-suit.jpg', models: [
    { name: 'CHAMPIONSHIP VIII', img: 'images/formal/championship-viii.jpg' },
    { name: 'DANUBIO III', img: 'images/formal/danubio-iii.jpg' },
    { name: 'DANUBIO IV', img: 'images/formal/danubio-iv.jpg' },
    { name: 'Heroic', img: 'images/formal/heroic.jpg' },
    { name: 'LIDER', img: 'images/formal/lider.jpg' },
    { name: 'LION II', img: 'images/formal/lion-ii.jpg' },
    { name: 'PHOENIX III', img: 'images/formal/phoenix-iii.jpg' },
    { name: 'TOLEDO', img: 'images/formal/toledo.jpg' },
    { name: 'VICTORY', img: 'images/formal/victory.jpg' },
    { name: 'WINNER IV', img: 'images/formal/winner-iv.jpg' },
  ]},
  { slug: 'training', title: 'Тренировочная кофта', tileImage: 'images/product-training-top.jpg', models: [
    { name: 'CHAMPIONSHIP', img: 'images/training/championship.jpg' },
    { name: 'CHAMPIONSHIP 20', img: 'images/training/championship-20.jpg' },
    { name: 'CHAMPIONSHIP VII', img: 'images/training/championship-vii.jpg' },
    { name: 'CHAMPIONSHIP VIII', img: 'images/training/championship-viii.jpg' },
    { name: 'COMBI PREMIUM', img: 'images/training/combi-premium.jpg' },
    { name: 'DANUBIO IV', img: 'images/training/danubio-iv.jpg' },
    { name: 'FARAON', img: 'images/training/faraon.jpg' },
    { name: 'OLIMPIADA', img: 'images/training/olimpiada.jpg' },
    { name: 'PICASHO', img: 'images/training/picasho.jpg' },
    { name: 'WINNER', img: 'images/training/winner.jpg' },
    { name: 'WINNER III', img: 'images/training/winner-iii.jpg' },
    { name: 'WINNER IV', img: 'images/training/winner-iv.jpg' },
  ]},
  { slug: 'game', title: 'Игровая форма', tileImage: 'images/product-kit.jpg', models: [
    { name: 'AREA', img: 'images/game/area.jpg' },
    { name: 'DANUBIO III', img: 'images/game/danubio-iii.jpg' },
    { name: 'LIDER', img: 'images/game/lider.jpg' },
    { name: 'PHOENIX', img: 'images/game/phoenix.jpg' },
    { name: 'PHOENIX III', img: 'images/game/phoenix-iii.jpg' },
    { name: 'PROLIGA', img: 'images/game/proliga.jpg' },
    { name: 'VICTORY', img: 'images/game/victory.jpg' },
    { name: 'FINAL FOUR', img: 'images/game/final-four.jpg' },
    { name: 'FINAL II', img: 'images/game/final-ii.jpg' },
    { name: 'KANSAS', img: 'images/game/kansas.jpg' },
    { name: 'LIDER BASKET', img: 'images/game/lider-basket.jpg' },
    { name: 'OLIMPIADA BASKET', img: 'images/game/olimpiada-basket.jpg' },
    { name: 'SET ATLANTA', img: 'images/game/set-atlanta.jpg' },
    { name: 'SET PHOENIX ВРАТАРЬ', img: 'images/game/set-phoenix.jpg' },
    { name: 'WINNER GK', img: 'images/game/winner-gk.jpg' },
    { name: 'ZAMORA IX', img: 'images/game/zamora-ix.jpg' },
    { name: 'ZAMORA X GK', img: 'images/game/zamora-x-gk.jpg' },
    { name: 'ZAMORA XI', img: 'images/game/zamora-xi.jpg' },
  ]},
  { slug: 'backpacks', title: 'Рюкзаки и сумки', tileImage: 'images/product-backpack.jpg', models: [
    { name: 'DIAMOND II', img: 'images/backpacks/diamond-ii.jpg' },
    { name: 'ESTADIO III', img: 'images/backpacks/estadio-iii.jpg' },
    { name: 'TEAM', img: 'images/backpacks/team.jpg' },
    { name: 'Детский рюкзак CAMP', img: 'images/backpacks/detskii-ryukzak-camp.jpg' },
    { name: 'Средняя сумка TRAINING III', img: 'images/backpacks/srednyaya-sumka-training-iii.jpg' },
    { name: 'Сумка большая TRAINING III', img: 'images/backpacks/sumka-bolshaya-training-iii.jpg' },
    { name: 'Сумка очень большая TRAINING III', img: 'images/backpacks/sumka-ochen-bolshaya-training-iii.jpg' },
    { name: 'Сумка средняя III', img: 'images/backpacks/sumka-srednyaya-iii.jpg' },
    { name: 'Тренировочный рюкзак III', img: 'images/backpacks/trenirovochnyi-ryukzak-iii.jpg' },
  ]},
  { slug: 'windbreakers', title: 'Ветровки', tileImage: 'images/product-raincoat.jpg', models: [
    { name: 'CERVINO', img: 'images/windbreakers/cervino.jpg' },
    { name: 'CERVINO II', img: 'images/windbreakers/cervino-ii.jpg' },
    { name: 'IRIS', img: 'images/windbreakers/iris.jpg' },
    { name: 'RAIN', img: 'images/windbreakers/rain.jpg' },
    { name: 'TRIVOR', img: 'images/windbreakers/trivor.jpg' },
    { name: 'TRIVOR II', img: 'images/windbreakers/trivor-ii.jpg' },
    { name: 'WIND', img: 'images/windbreakers/wind.jpg' },
  ]},
  { slug: 'jackets', title: 'Куртки', tileImage: 'images/product-jacket.jpg', models: [
    { name: 'ICONO', img: 'images/jackets/icono.jpg' },
    { name: 'TRIVOR', img: 'images/jackets/trivor.jpg' },
    { name: 'TRIVOR II', img: 'images/jackets/trivor-ii.jpg' },
    { name: 'URBAN V', img: 'images/jackets/urban-v.jpg' },
  ]},
  { slug: 'getry', title: 'Гетры', tileImage: 'images/product-getry.jpg', models: [
    { name: 'CLASSIC 2', img: 'images/socks/classic-2.jpg' },
    { name: 'CLASSIC 4', img: 'images/socks/classic-4.jpg' },
    { name: 'COMPRESSION', img: 'images/socks/compression.jpg' },
    { name: 'Calcio', img: 'images/socks/calcio.jpg' },
    { name: 'LEG II', img: 'images/socks/leg-ii.jpg' },
    { name: 'PREMIER', img: 'images/socks/premier.jpg' },
    { name: 'PREMIER II', img: 'images/socks/premier-ii.jpg' },
    { name: 'Professional II', img: 'images/socks/professional-ii.jpg' },
    { name: 'Zebra II', img: 'images/socks/zebra-ii.jpg' },
  ]},
  { slug: 'polo', title: 'Поло', tileImage: 'images/product-hat.jpg', models: [
    { name: 'BALI II', img: 'images/polo/bali-ii.jpg' },
    { name: 'BALI III', img: 'images/polo/bali-iii.jpg' },
    { name: 'HOBBY', img: 'images/polo/hobby.jpg' },
    { name: 'HOBBY II', img: 'images/polo/hobby-ii.jpg' },
  ]},
];

function configuratorBuildMessage(category, model, qty, team) {
  const lines = [
    'Здравствуйте! Хочу заказать командную экипировку.',
    `Категория: ${category.title}`,
    `Модель: ${model.name}`,
    `Количество: ${qty} комплектов`,
  ];
  if (team) lines.push(`Команда: ${team}`);
  lines.push('Пришлите, пожалуйста, расчёт.');
  return lines.join('\n');
}

function initConfigurator() {
  const root = document.querySelector('.configurator-app');
  if (!root) return;

  const stepEls = root.querySelectorAll('.config-step');
  const panels = {
    1: root.querySelector('[data-panel="1"]'),
    2: root.querySelector('[data-panel="2"]'),
    3: root.querySelector('[data-panel="3"]'),
  };
  const categoriesGrid = root.querySelector('.config-grid-categories');
  const modelsGrid = root.querySelector('.config-grid-models');
  const panelTitle = root.querySelector('.config-panel-title');
  const summaryImg = root.querySelector('.config-summary-img');
  const summaryCategory = root.querySelector('.config-summary-category');
  const summaryModel = root.querySelector('.config-summary-model');
  const qtyInput = root.querySelector('.config-qty');
  const teamInput = root.querySelector('.config-team');
  const submitLink = root.querySelector('.config-submit');

  let selectedCategory = null;
  let selectedModel = null;

  function showPanel(n) {
    Object.entries(panels).forEach(([key, el]) => {
      if (!el) return;
      el.hidden = Number(key) !== n;
    });
    stepEls.forEach((el) => {
      el.classList.toggle('is-active', Number(el.dataset.step) === n);
    });
  }

  function renderCategories() {
    categoriesGrid.innerHTML = '';
    CONFIGURATOR_CATEGORIES.forEach((category) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'catalog-item';
      btn.innerHTML = `<img src="${category.tileImage}" alt="${category.title}" loading="lazy"><span>${category.title}</span>`;
      btn.addEventListener('click', () => selectCategory(category));
      categoriesGrid.appendChild(btn);
    });
  }

  function selectCategory(category) {
    selectedCategory = category;
    panelTitle.textContent = category.title;
    modelsGrid.innerHTML = '';
    category.models.forEach((model) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'catalog-item';
      btn.innerHTML = `<img src="${model.img}" alt="${model.name}" loading="lazy"><span>${model.name}</span>`;
      btn.addEventListener('click', () => selectModel(model));
      modelsGrid.appendChild(btn);
    });
    showPanel(2);
    if (typeof trackEvent === 'function') {
      trackEvent('ViewContent', { content_name: category.title, content_category: category.slug });
    }
  }

  function selectModel(model) {
    selectedModel = model;
    summaryImg.src = model.img;
    summaryImg.alt = model.name;
    summaryCategory.textContent = selectedCategory.title;
    summaryModel.textContent = model.name;
    qtyInput.value = 10;
    teamInput.value = '';
    updateSubmitLink();
    showPanel(3);
  }

  function updateSubmitLink() {
    if (!selectedCategory || !selectedModel) return;
    const qty = Math.max(10, Number(qtyInput.value) || 10);
    const message = configuratorBuildMessage(selectedCategory, selectedModel, qty, teamInput.value.trim());
    submitLink.setAttribute('href', `https://wa.me/${CONFIGURATOR_PHONE}?text=${encodeURIComponent(message)}`);
  }

  qtyInput.addEventListener('input', updateSubmitLink);
  teamInput.addEventListener('input', updateSubmitLink);

  submitLink.addEventListener('click', () => {
    if (!selectedCategory || !selectedModel || typeof trackEvent !== 'function') return;
    trackEvent('Lead', {
      content_name: selectedModel.name,
      content_category: selectedCategory.title,
      value: Math.max(10, Number(qtyInput.value) || 10),
    });
  });

  root.querySelectorAll('[data-back]').forEach((btn) => {
    btn.addEventListener('click', () => showPanel(Number(btn.dataset.back)));
  });

  renderCategories();

  const preselectSlug = new URLSearchParams(window.location.search).get('category');
  const preselected = preselectSlug && CONFIGURATOR_CATEGORIES.find((c) => c.slug === preselectSlug);
  if (preselected) {
    selectCategory(preselected);
  } else {
    showPanel(1);
  }
}

window.initConfigurator = initConfigurator;
initConfigurator();
