<?php
$presenceConfig = [
    'base' => base(),
    'csrf' => $_SESSION['csrf'],
    'lang' => lang(),
    'words' => $GLOBALS['dict'],
    'poll' => (int) setting('poll_seconds'),
    'maxLength' => (int) setting('message_length'),
    'possibleSession' => !empty($_SESSION['sid']),
    'site' => setting('site_name'),
];
$presenceScript = ROOT . '/public/assets/chat-presence.js';
?>
<script id="kg-chat-presence" type="application/json" data-config="<?= e(json_encode($presenceConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"></script>
<script src="<?= e(url('assets/chat-presence.js?v=' . (string) filemtime($presenceScript))) ?>" defer></script>
