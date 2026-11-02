<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

// The worker name is untrusted query text: it only ever reaches the page through textContent.
wsv_page_start($cpanel, $i18n, $i18n->t('action.logs'));
$t = static fn (string $key): string => wsv_h($i18n->t($key));
?>
<div class="wsv wsv-logs" id="wsv-logs">
    <p><a href="index.live.php"><?= $t('logs.back') ?></a></p>
    <h2 class="wsv-logs-title" data-wsv="heading"></h2>
    <div data-wsv="alerts" role="alert"></div>
    <p class="wsv-muted" data-wsv="loading"><?= $t('common.loading') ?></p>

    <div data-wsv="viewer" hidden>
        <div class="wsv-logs-bar">
            <div class="wsv-tabs wsv-logs-tabs" data-wsv="processes" role="group" aria-label="<?= $t('logs.processes_label') ?>"></div>
            <div class="btn-group wsv-logs-streams" data-wsv="streams" role="group" aria-label="<?= $t('logs.streams_label') ?>" hidden></div>
        </div>
        <div class="wsv-logs-bar">
            <label class="wsv-logs-lines">
                <?= $t('logs.lines') ?>
                <select class="form-control input-sm" data-wsv="lines">
                    <option value="200">200</option>
                    <option value="1000">1000</option>
                    <option value="2000">2000</option>
                </select>
            </label>
            <label class="wsv-logs-auto">
                <input type="checkbox" data-wsv="auto" checked> <?= $t('logs.auto_refresh') ?>
            </label>
            <span class="wsv-logs-buttons" data-wsv="buttons">
                <button type="button" class="btn btn-default btn-sm" data-wsv="refresh"><?= $t('logs.refresh') ?></button>
                <button type="button" class="btn btn-default btn-sm" data-wsv="download"><?= $t('logs.download') ?></button>
                <button type="button" class="btn btn-default btn-sm" data-wsv="clear"><?= $t('logs.clear') ?></button>
            </span>
            <span class="wsv-confirm" data-wsv="confirm" role="group" hidden>
                <span data-wsv="confirm-text"></span>
                <button type="button" class="btn btn-danger btn-sm" data-wsv="confirm-yes"><?= $t('logs.clear') ?></button>
                <button type="button" class="btn btn-default btn-sm" data-wsv="confirm-no"><?= $t('common.cancel') ?></button>
            </span>
        </div>
        <pre class="wsv-logs-text" data-wsv="text" tabindex="0" role="log"></pre>
        <p class="wsv-muted wsv-logs-home"><?= $t('logs.home_hint') ?></p>
    </div>
</div>
<script src="<?= wsv_h(wsv_asset('logs.js')) ?>"></script>
<?php
wsv_page_end($cpanel, $i18n);
