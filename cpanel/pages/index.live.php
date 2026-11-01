<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

wsv_page_start($cpanel, $i18n, $i18n->t('app.title'));
?>
<div class="wsv" id="wsv-list">
    <div class="wsv-toolbar">
        <div class="wsv-usage" data-wsv="usage" hidden></div>
        <a class="btn btn-primary" data-wsv="new" href="edit.live.php"><?= wsv_h($i18n->t('list.new_worker')) ?></a>
    </div>
    <p class="text-warning" data-wsv="limit-reached" hidden><?= wsv_h($i18n->t('list.limit_reached')) ?></p>
    <div class="alert alert-warning" data-wsv="over-limit" hidden>
        <span class="glyphicon glyphicon-exclamation-sign"></span>
        <div class="alert-message"><?= wsv_h($i18n->t('list.over_limit')) ?></div>
    </div>
    <div data-wsv="alerts" role="alert"></div>
    <div class="table-responsive">
        <table class="table wsv-table">
            <thead>
            <tr>
                <th scope="col"><?= wsv_h($i18n->t('list.col_name')) ?></th>
                <th scope="col"><?= wsv_h($i18n->t('list.col_status')) ?></th>
                <th scope="col"><?= wsv_h($i18n->t('list.col_processes')) ?></th>
                <th scope="col"><?= wsv_h($i18n->t('list.col_since')) ?></th>
                <th scope="col"><?= wsv_h($i18n->t('list.col_actions')) ?></th>
            </tr>
            </thead>
            <tbody data-wsv="rows">
            <tr><td colspan="5" class="wsv-muted"><?= wsv_h($i18n->t('common.loading')) ?></td></tr>
            </tbody>
        </table>
    </div>
</div>
<script src="<?= wsv_h(wsv_asset('list.js')) ?>"></script>
<?php
wsv_page_end($cpanel, $i18n);
