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
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="style.css">
</head>
<body>

<?php include __DIR__ . '/partials/header.php'; ?>

<main>
  <div class="hero">
    <div class="hero-bg" style="background-image:url('images/hero.jpg')"></div>
    <div class="hero-content">
      <p class="eyebrow">Joma Teamwear · Астана</p>
      <h1>Экипировка для спортивных<br>команд <em>на заказ</em></h1>
      <p>Экипировка для команд любого уровня. Соберите свой образ, проверьте наличие по размерам и получите расчёт.</p>
      <a class="btn btn-accent" href="catalog.php">Перейти в каталог →</a>
    </div>
  </div>
</main>

<script src="app.js"></script>
</body>
</html>
