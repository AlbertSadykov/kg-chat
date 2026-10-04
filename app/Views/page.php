<?php $title = t($page) . ' · ' . setting('site_name');
require ROOT . '/app/Views/header.php'; ?>
<main class="wrap legal panel">
    <h1><?= e(t($page)) ?></h1>
    <?php if ($page === 'rules'): ?>
        <p class="prewrap"><?= e(setting('rules_' . lang())) ?></p>
    <?php elseif ($page === 'privacy'): ?>
        <h2><?= e(t('privacy_title')) ?></h2>
        <p><b><?= e(t('operator')) ?>:</b>
            <?= e(setting('operator_name') ?: t('operator_missing')) ?><br><?= e(setting('operator_address')) ?></p>
        <p><b><?= e(t('hosting')) ?>:</b> <?= e(setting('hosting_country') ?: t('operator_missing')) ?></p>
        <?php foreach (['privacy_data', 'privacy_purpose', 'privacy_access', 'privacy_rights', 'privacy_cookies'] as $paragraph): ?>
            <p><?= e(t($paragraph)) ?></p><?php endforeach; ?>
        <h2><?= e(t('retention')) ?></h2>
        <ul><?php foreach (['identity_days', 'session_days', 'report_days', 'audit_days'] as $key): ?>
                <li><?= e(t($key)) ?>: <?= (int) setting($key) ?>         <?= e(t('days')) ?></li><?php endforeach; ?>
            <li><?= setting('log_messages') === '1' ? e(t('message_days')) . ': ' . (int) setting('message_days') . ' ' . e(t('days')) : e(t('ephemeral')) ?>
            </li>
        </ul>
        <p><?= e(t('retention_cleanup')) ?></p>
        <p><a href="https://dpa.gov.kg/" rel="noopener noreferrer">dpa.gov.kg</a> · <a
                href="https://cbd.minjust.gov.kg/3-48/edition/35412/ru" rel="noopener noreferrer">Цифровой кодекс КР
                №178</a></p>
    <?php elseif ($page === 'contacts'): ?>
        <p><?= e(t('operator')) ?>: <?= e(setting('operator_name') ?: t('operator_missing')) ?></p>
        <p><?= e(setting('operator_address')) ?></p>
        <p><?= e(t('support')) ?>: <a
                href="mailto:<?= e(setting('support_email')) ?>"><?= e(setting('support_email')) ?></a></p>
        <p><?= e(t('my_id')) ?>: <?= (int) ($_SESSION['sid'] ?? 0) ?></p>
    <?php elseif ($page === '18'): ?>
        <p><?= e(t('adult_text')) ?></p>
    <?php endif; ?>
    <p><a class="button secondary" href="<?= e(url()) ?>"><?= e(t('home')) ?></a></p>
</main>
<?php require ROOT . '/app/Views/footer.php'; ?>