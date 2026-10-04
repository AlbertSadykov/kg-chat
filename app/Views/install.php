<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Установка Кезик</title><link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>"></head>
<body><main class="install panel"><img class="logo" src="<?= e(url('assets/logo.svg')) ?>" alt="Кезик"><h1>Установка • <?= (int) $step ?>/5</h1><p class="muted">PHP + MySQL. Без внешних сервисов.</p>
<?php if ($error): ?><p class="notice danger" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if ($step === 6): ?>
<h2>Готово!</h2><p>Обязательно удалите папку <b>install/</b> через файловый менеджер. Установка заблокирована файлом storage/installed.lock.</p><p>Перед публикацией заполните сведения об операторе и хостинге в админке.</p><a class="button" href="<?= e(url()) ?>">Открыть чат</a><a class="button secondary" href="<?= e(url('admin')) ?>">Админка</a>
<?php else: ?><form method="post"><?= csrfField() ?>
<?php if ($step === 1): ?>
<ul class="checks"><?php foreach ($checks as $name => $ok): ?><li><?= $ok ? '✓' : '✗' ?> <?= e($name) ?></li><?php endforeach; ?></ul><p>Запускайте мастер на закрытом от посторонних домене или временно защитите сайт паролем в панели хостинга.</p>
<?php elseif ($step === 2): ?>
<?php foreach (['host' => 'Хост БД', 'name' => 'Имя БД', 'user' => 'Пользователь', 'pass' => 'Пароль БД', 'prefix' => 'Префикс таблиц'] as $key => $label): ?><label><?= e($label) ?><input name="<?= e($key) ?>" type="<?= $key === 'pass' ? 'password' : 'text' ?>" value="<?= e($key === 'host' ? 'localhost' : ($key === 'prefix' ? 'kg_' : '')) ?>" <?= $key === 'pass' ? '' : 'required' ?> autocomplete="off"></label><?php endforeach; ?>
<?php elseif ($step === 3): ?>
<label>Логин администратора<input name="login" required minlength="3" maxlength="64" autocomplete="username"></label><label>Пароль (от 12 символов)<input name="password" type="password" required minlength="12" maxlength="128" autocomplete="new-password"></label><label>Email<input name="email" type="email" required></label>
<?php elseif ($step === 4): ?>
<label>Название сайта<input name="site_name" value="Кезик • Анонимный чат" required maxlength="120"></label><label>URL сайта, без /public<input name="url" type="url" placeholder="https://example.com" required></label><label>Язык<select name="language"><option value="ru">Русский</option><option value="ky">Кыргызча</option></select></label><label>Email поддержки<input name="support_email" type="email" required></label>
<?php elseif ($step === 5): ?><p>Соединение проверено. Создадим таблицы, справочник городов, администратора, настройки и конфигурацию.</p><p>Повторная установка не удаляет существующие таблицы. Для новой установки нужен свободный префикс.</p>
<?php endif; ?><button type="submit" <?= $step === 1 && in_array(false, $checks, true) ? 'disabled' : '' ?>><?= $step === 5 ? 'Установить' : 'Продолжить' ?></button><?php if ($step > 1): ?><button type="submit" name="back" value="1" class="secondary" formnovalidate>Назад</button><?php endif; ?></form><?php endif; ?>
</main></body></html>
