<?php
// Установщик создаёт config.php автоматически. Этот файл не подключается.
return [
    'db' => ['host' => 'localhost', 'name' => 'kg_chat', 'user' => 'kg_chat', 'pass' => '', 'prefix' => 'kg_'],
    'secret' => 'Сгенерировать 64 случайных шестнадцатеричных символа',
    'url' => 'https://example.com',
    // Включать только если TLS завершается на доверенном прокси хостинга.
    'https' => false,
];
