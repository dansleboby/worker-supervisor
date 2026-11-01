<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

// The worker name itself never reaches the heading: it is untrusted query text.
$editing = is_string($_GET['name'] ?? null) && $_GET['name'] !== '';
wsv_page_start($cpanel, $i18n, $i18n->t($editing ? 'edit.title_edit' : 'edit.title_new'));
$t = static fn (string $key): string => wsv_h($i18n->t($key));
?>
<div class="wsv wsv-edit" id="wsv-edit">
    <div data-wsv="alerts" role="alert"></div>
    <p class="wsv-muted" data-wsv="loading"><?= $t('common.loading') ?></p>
    <p data-wsv="back" hidden><a href="index.live.php"><?= $t('edit.back') ?></a></p>

    <form data-wsv="form" novalidate hidden>
        <fieldset data-wsv="fields">
            <div class="wsv-tabs" role="tablist" aria-label="<?= $t('edit.tabs_label') ?>">
                <button type="button" class="wsv-tab" role="tab" id="wsv-tab-form" data-mode="form"
                        aria-controls="wsv-panel-form" aria-selected="true"><?= $t('edit.tab_form') ?></button>
                <button type="button" class="wsv-tab" role="tab" id="wsv-tab-expert" data-mode="expert"
                        aria-controls="wsv-panel-expert" aria-selected="false" tabindex="-1"><?= $t('edit.tab_expert') ?></button>
            </div>

            <div role="tabpanel" id="wsv-panel-form" aria-labelledby="wsv-tab-form">
                <div class="form-group">
                    <label for="wsv-f-template"><?= $t('edit.template') ?></label>
                    <select class="form-control" id="wsv-f-template" aria-describedby="wsv-deploy-hint"></select>
                    <p class="help-block" id="wsv-deploy-hint" data-wsv="deploy-hint"></p>
                </div>
                <div class="wsv-row">
                    <div class="form-group wsv-grow">
                        <label for="wsv-f-name"><?= $t('edit.name') ?></label>
                        <input class="form-control" id="wsv-f-name" type="text" autocomplete="off" spellcheck="false"
                               autocapitalize="none">
                    </div>
                    <div class="form-group">
                        <label for="wsv-f-processes"><?= $t('edit.processes') ?></label>
                        <input class="form-control" id="wsv-f-processes" type="number" min="1" step="1"
                               inputmode="numeric" aria-describedby="wsv-processes-help">
                        <p class="help-block" id="wsv-processes-help" data-wsv="processes-help"></p>
                    </div>
                </div>
                <div class="form-group">
                    <label for="wsv-f-folder"><?= $t('edit.folder') ?></label>
                    <input class="form-control wsv-code" id="wsv-f-folder" type="text" autocomplete="off" spellcheck="false"
                           autocapitalize="none">
                </div>
                <div class="form-group">
                    <label for="wsv-f-command"><?= $t('edit.command') ?></label>
                    <textarea class="form-control wsv-code" id="wsv-f-command" rows="2" spellcheck="false" autocomplete="off"
                              autocapitalize="none" aria-describedby="wsv-command-hint"></textarea>
                    <p class="help-block" id="wsv-command-hint"><?= $t('hint.single_command') ?></p>
                </div>

                <details class="wsv-advanced" data-wsv="advanced">
                    <summary><?= $t('edit.advanced') ?></summary>
                    <div class="wsv-row">
                        <div class="form-group wsv-check">
                            <div class="checkbox">
                                <label><input type="checkbox" id="wsv-f-autostart"> <?= $t('edit.autostart') ?></label>
                            </div>
                        </div>
                        <div class="form-group wsv-grow">
                            <label for="wsv-f-autorestart"><?= $t('edit.autorestart') ?></label>
                            <select class="form-control" id="wsv-f-autorestart">
                                <option value="true"><?= $t('edit.autorestart_true') ?></option>
                                <option value="unexpected"><?= $t('edit.autorestart_unexpected') ?></option>
                                <option value="false"><?= $t('edit.autorestart_false') ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="wsv-row">
                        <div class="form-group">
                            <label for="wsv-f-startsecs"><?= $t('edit.startsecs') ?></label>
                            <input class="form-control" id="wsv-f-startsecs" type="number" min="0" max="3600" step="1"
                                   inputmode="numeric">
                        </div>
                        <div class="form-group">
                            <label for="wsv-f-startretries"><?= $t('edit.startretries') ?></label>
                            <input class="form-control" id="wsv-f-startretries" type="number" min="0" max="100" step="1"
                                   inputmode="numeric">
                        </div>
                        <div class="form-group">
                            <label for="wsv-f-stopwaitsecs"><?= $t('edit.stopwaitsecs') ?></label>
                            <input class="form-control" id="wsv-f-stopwaitsecs" type="number" min="1" step="1"
                                   inputmode="numeric" aria-describedby="wsv-stopwait-help">
                            <p class="help-block" id="wsv-stopwait-help" data-wsv="stopwait-help"></p>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="checkbox">
                            <label><input type="checkbox" id="wsv-f-redirect_stderr"> <?= $t('edit.redirect_stderr') ?></label>
                        </div>
                    </div>
                </details>
            </div>

            <div role="tabpanel" id="wsv-panel-expert" aria-labelledby="wsv-tab-expert" hidden>
                <p class="wsv-muted" data-wsv="expert-template"></p>
                <div class="form-group">
                    <label for="wsv-f-expert"><?= $t('edit.expert_text') ?></label>
                    <textarea class="form-control wsv-code" id="wsv-f-expert" rows="12" wrap="off" spellcheck="false"
                              autocomplete="off" autocapitalize="none" aria-describedby="wsv-expert-owned"></textarea>
                    <p class="wsv-owned" id="wsv-expert-owned"><?= $t('edit.expert_owned') ?></p>
                </div>
            </div>

            <div class="wsv-checks" data-wsv="checks" aria-live="polite"></div>

            <div class="wsv-form-actions">
                <button type="submit" class="btn btn-primary" data-wsv="save" disabled><?= $t('edit.save') ?></button>
                <a class="btn btn-default" href="index.live.php"><?= $t('common.cancel') ?></a>
                <span class="wsv-muted" data-wsv="busy" aria-hidden="true"></span>
            </div>
        </fieldset>
    </form>
</div>
<script src="<?= wsv_h(wsv_asset('edit.js')) ?>"></script>
<?php
wsv_page_end($cpanel, $i18n);
