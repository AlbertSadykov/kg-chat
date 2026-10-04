<?php
if (setting('analytics_id') !== '') {
    db()->run('INSERT INTO ' . db()->table('settings') . '(name,value) VALUES (?,1) ON DUPLICATE KEY UPDATE value=CAST(value AS UNSIGNED)+1', ['pv_' . gmdate('Y-m-d')]);
}
require ROOT . '/app/Views/header.php';
$client = ['base' => base(), 'csrf' => $_SESSION['csrf'], 'lang' => lang(), 'words' => $GLOBALS['dict'], 'transport' => setting('transport'), 'poll' => (int) setting('poll_seconds'), 'minAge' => max(18, (int) setting('min_age')), 'maxAge' => (int) setting('max_age'), 'maxLength' => (int) setting('message_length')];
$hasSession = !empty($_SESSION['sid']);
$loadingLabel = lang() === 'ky' ? 'Жүктөлүүдө…' : 'Загрузка…';
?>
<main id="app" class="wrap" data-config="<?= e(json_encode($client, JSON_UNESCAPED_UNICODE)) ?>">
    <div id="notice" class="notice" role="alert" hidden></div>
    <p id="session-loading" class="muted" role="status" <?= $hasSession ? '' : 'hidden' ?>><?= e($loadingLabel) ?></p>
    <section id="entry" class="entry" <?= $hasSession ? 'hidden' : '' ?>>
        <div class="hero"><span class="pill">18+ · RU / KY</span>
            <h1><?= e(t('tagline')) ?></h1>
            <p><?= e(t('intro')) ?></p>
            <div class="hero-art" aria-hidden="true"><span class="art-bubble">Салам 👋</span><span
                    class="art-bubble second">Привет!</span></div>
            <p class="safety"><?= e(t('safety')) ?></p>
        </div>
        <form id="entry-form" class="panel">
            <h2><?= e(t('profile')) ?></h2><label><?= e(t('nickname')) ?><input name="nickname" maxlength="40"
                    placeholder="<?= e(t('anonymous')) ?>" autocomplete="off"></label>
            <div class="form-grid"><label><?= e(t('gender')) ?><select name="gender" required>
                        <option value="">—</option>
                        <option value="m"><?= e(t('m')) ?></option>
                        <option value="f"><?= e(t('f')) ?></option>
                    </select></label><label><?= e(t('age')) ?><input name="age" type="number"
                        min="<?= $client['minAge'] ?>" max="<?= $client['maxAge'] ?>" step="1" required
                        inputmode="numeric"></label></div><label><?= e(t('city')) ?><select name="city" required>
                    <option value="">—</option><?php foreach ($cities as $city): ?>
                        <option value="<?= (int) $city['id'] ?>"><?= e($city['name_' . lang()]) ?></option>
                    <?php endforeach; ?>
                </select></label><label class="check"><input type="checkbox" name="adult"
                    required><span><?= e(t('adult_check')) ?></span></label><label class="check"><input type="checkbox"
                    name="rules" required><span><?= e(t('rules_check')) ?> · <a href="<?= e(url('rules')) ?>"
                        target="_blank" rel="noopener"><?= e(t('rules')) ?></a> · <a href="<?= e(url('privacy')) ?>"
                        target="_blank" rel="noopener"><?= e(t('privacy')) ?></a></span></label><button
                class="wide auth-button" type="submit"><?= e(t('enter')) ?></button>
            <p class="muted small"><?= e(t('adult_text')) ?></p>
        </form>
    </section>
    <section id="workspace" class="workspace" hidden>
        <aside class="panel filters">
            <h2><?= e(t('filters')) ?></h2>
            <p id="me" class="muted"></p>
            <form id="filter-form"><label><?= e(t('peer_city')) ?><select name="city">
                        <option value="0"><?= e(t('any')) ?></option><?php foreach ($cities as $city): ?>
                            <option value="<?= (int) $city['id'] ?>"><?= e($city['name_' . lang()]) ?></option>
                        <?php endforeach; ?>
                    </select></label><label><?= e(t('peer_gender')) ?><select name="gender">
                        <option value=""><?= e(t('any')) ?></option>
                        <option value="m"><?= e(t('m')) ?></option>
                        <option value="f"><?= e(t('f')) ?></option>
                    </select></label><label><?= e(t('age_range')) ?><span class="form-grid"><input name="min_age"
                            type="number" min="<?= $client['minAge'] ?>" max="<?= $client['maxAge'] ?>"
                            value="<?= $client['minAge'] ?>" required aria-label="<?= e(t('min_age')) ?>"><input
                            name="max_age" type="number" min="<?= $client['minAge'] ?>" max="<?= $client['maxAge'] ?>"
                            value="<?= $client['maxAge'] ?>" required
                            aria-label="<?= e(t('max_age')) ?>"></span></label><button class="wide" id="search"
                    type="submit"><?= e(t('search')) ?></button></form><button id="cancel" class="secondary wide"
                hidden><?= e(t('cancel')) ?></button><button id="expand" class="secondary wide"
                hidden><?= e(t('expand')) ?></button>
            <div class="stats-line"><span><?= e(t('online')) ?> <b id="online">0</b></span><span><?= e(t('queued')) ?>
                    <b id="queued">0</b></span></div>
            <button id="forget" class="text-button danger-text forget-button"><?= e(t('forget')) ?></button>
        </aside>
        <div class="chat-panel panel">
            <div id="chat-header" class="chat-header">
                <div><span id="state" class="pill"><?= e(t('idle')) ?></span>
                    <div class="peer-line"><span id="live-dot" class="live-dot" aria-hidden="true" hidden></span><span id="search-indicator" class="search-indicator" aria-hidden="true" hidden><span></span><span></span><span></span></span>
                        <h2 id="peer"><?= e(t('search')) ?></h2>
                    </div>
                </div>
            </div>
            <div id="messages" class="messages" role="log" aria-live="polite" aria-label="<?= e(t('chats')) ?>">
                <p class="empty-message"><?= e(t('safety')) ?></p>
            </div>
            <p id="typing" class="typing" aria-live="polite"></p>
            <div id="emojis" class="emojis" hidden>
                <?php foreach (['👋', '🙂', '😊', '😂', '❤️', '👍', '🙌', '🤔', '🔥', '🎉', '🇰🇬', '✨'] as $emoji): ?><button
                        type="button" class="emoji-choice"><?= e($emoji) ?></button><?php endforeach; ?>
            </div>
            <div id="reply-preview" class="reply-preview" hidden><div class="reply-preview-copy"><strong id="reply-title"></strong><span id="reply-text"></span></div><button id="reply-cancel" type="button" class="reply-cancel" aria-label="<?= e(t('cancel_reply')) ?>">×</button></div>
            <form id="send-form" class="composer"><button id="emoji" type="button" class="icon-button"
                    aria-label="<?= e(t('emoji')) ?>">☺</button><textarea id="message" rows="1"
                    maxlength="<?= $client['maxLength'] ?>" placeholder="<?= e(t('message')) ?>"
                    aria-label="<?= e(t('message')) ?>" disabled></textarea><button id="send" type="submit"
                    aria-label="<?= e(t('send')) ?>" disabled><svg width="22" height="22" aria-hidden="true">
                        <use href="<?= e(url('assets/icons.svg')) ?>#send"></use>
                    </svg></button></form>
            <div class="chat-actions"><button id="next" disabled><?= e(t('next')) ?></button><button id="end"
                    class="secondary" disabled><?= e(t('end')) ?></button><button id="report"
                    class="text-button danger-text" disabled><?= e(t('report')) ?></button></div>
        </div>
    </section>
    <dialog id="report-dialog">
        <form id="report-form">
            <h2><?= e(t('report')) ?></h2><label><?= e(t('reason')) ?><select
                    name="reason"><?php foreach (['spam', 'abuse', 'advertising', 'explicit', 'minor', 'fraud', 'other'] as $reason): ?>
                        <option value="<?= e($reason) ?>"><?= e(t($reason)) ?></option><?php endforeach; ?>
                </select></label><label><?= e(t('detail')) ?><textarea name="detail" maxlength="500"
                    rows="3"></textarea></label>
            <div class="button-row"><button type="submit"><?= e(t('send')) ?></button><button type="button"
                    class="secondary" data-close="report-dialog"><?= e(t('cancel')) ?></button></div>
        </form>
    </dialog>
    <dialog id="captcha-dialog">
        <form id="captcha-form">
            <h2><?= e(t('captcha')) ?></h2><label><span id="captcha-question"></span><input id="captcha-answer"
                    inputmode="numeric" autocomplete="off" required></label>
            <div class="button-row"><button type="submit"><?= e(t('send')) ?></button><button type="button"
                    class="secondary" data-close="captcha-dialog"><?= e(t('cancel')) ?></button></div>
        </form>
    </dialog>
    <noscript>
        <p><?= e(t('invalid')) ?> JavaScript required.</p>
    </noscript>
</main>
<script src="<?= e(url('assets/chat.js')) ?>?v=<?= (int) filemtime(ROOT . '/public/assets/chat.js') ?>" defer></script>
<?php require ROOT . '/app/Views/blog-home.php'; ?>
<?php require ROOT . '/app/Views/footer.php'; ?>