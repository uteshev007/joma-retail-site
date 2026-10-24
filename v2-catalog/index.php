<?php
declare(strict_types=1);
$headerOnHero = true;
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Joma Teamwear — экипировка для команд</title>
<meta name="description" content="Экипировка для спортивных команд на заказ — каталог Joma Teamwear.">
<!-- Hero-фото — LCP-элемент этой страницы (PageSpeed: LCP 8.6с на
     мобильном). Оно фоновое (CSS background-image), поэтому браузер
     обычно узнаёт о нём только после разбора CSS/вёрстки — preload
     даёт команду начать качать сразу при разборе <head>, не дожидаясь
     этого. -->
<link rel="preload" as="image" href="images/hero.webp" fetchpriority="high">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="style.css">
<link rel="icon" href="/logo/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/logo/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/logo/favicon-16.png">
<link rel="apple-touch-icon" sizes="180x180" href="/logo/favicon-180.png">
</head>
<body>

<?php include __DIR__ . '/partials/header.php'; ?>

<main>
  <div class="hero">
    <div class="hero-bg" style="background-image:url('images/hero.webp')"></div>
    <div class="hero-content">
      <p class="eyebrow">Joma Teamwear · Астана</p>
      <h1>Экипировка для спортивных<br>команд <em>на заказ</em></h1>
      <p>Экипировка для команд любого уровня. Соберите свой образ, проверьте наличие по размерам и получите расчёт.</p>
      <a class="btn btn-accent" href="catalog.php">Перейти в каталог →</a>
    </div>
  </div>
</main>

<?php include __DIR__ . '/partials/footer.php'; ?>

<script src="app.js"></script>
</body>
</html>
