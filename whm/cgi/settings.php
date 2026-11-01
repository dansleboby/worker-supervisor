<?php

declare(strict_types=1);

use WorkerSupervisor\I18n;
use WorkerSupervisor\Issue;
use WorkerSupervisor\ServiceError;
use WorkerSupervisor\Templates;

require __DIR__ . '/_bootstrap.php';

// Server default limits and per-account overrides (spec 6.2). Package values sit in WHM's own
// Edit a Package form (package extension); precedence is override > package > defaults, per field.

const WSV_LIMITS = [
    'max_processes' => 'Max processes (all workers of an account combined)',
    'max_workers' => 'Max workers',
    'max_stopwaitsecs' => 'Max stop wait (seconds)',
];

/** @var array<string, string> field (or "form") => message */
$errors = [];
/** @var ?string defaults or override: the form whose posted values are shown again */
$posted = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = wsv_param($_POST, 'action');
    $user = wsv_param($_POST, 'user');
    try {
        switch ($action) {
            case 'defaults':
                $posted = 'defaults';
                $admin->setDefaults(wsv_posted_limits() + ['templates' => $_POST['templates'] ?? []]);
                wsv_redirect('settings.php', ['saved' => 'defaults']);
            case 'override':
                $posted = 'override';
                $templates = wsv_param($_POST, 'override_templates') === '1' ? ($_POST['templates'] ?? []) : null;
                $admin->setOverride($user, wsv_posted_limits() + ['templates' => $templates]);
                wsv_redirect('settings.php', ['saved' => 'override', 'user' => $user]);
            case 'remove_override':
                $admin->setOverride($user, null);
                wsv_redirect('settings.php', ['saved' => 'removed', 'user' => $user]);
            default:
                $errors['form'] = 'The request was not valid. Reload the page and try again.';
        }
    } catch (ServiceError $e) {
        $unknown = $user === '' ? 'Enter an account name.' : "There is no cPanel account named $user.";
        $errors += match (true) {
            $e->errorCode === 'validation_failed' => wsv_settings_errors($e->issues, $posted === 'override'),
            $e->errorCode === 'account_unknown' && $posted === 'override' => ['user' => $unknown],
            $e->errorCode === 'account_unknown' => ['form' => $unknown],
            default => ['form' => 'The change was refused (' . $e->errorCode . ').'],
        };
    } catch (Throwable $e) {
        $errors['form'] = wsv_internal($e);
    }
}

/** @return array<string, string> the posted limit fields, as typed */
function wsv_posted_limits(): array
{
    $out = [];
    foreach (array_keys(WSV_LIMITS) as $key) {
        $out[$key] = wsv_param($_POST, $key);
    }

    return $out;
}

/**
 * @param Issue[] $issues
 * @return array<string, string> field => message
 */
function wsv_settings_errors(array $issues, bool $override): array
{
    $out = [];
    foreach ($issues as $issue) {
        $out[$issue->field] = match (true) {
            $issue->code === 'template_unknown' => 'Unknown template: ' . $issue->params['template'] . '.',
            $issue->field === 'templates' => 'Choose templates from the list.',
            $override => 'Enter a whole number of at least 1, or leave it blank to keep the package or default value.',
            default => 'Enter a whole number of at least 1.',
        };
    }

    return $out;
}

/** @param array<string, string> $errors */
function wsv_field_error(array $errors, string $field, string $id): void
{
    if (isset($errors[$field])) {
        echo '<div class="wsv-field-error" id="', $id, '-error">', wsv_h($errors[$field]), '</div>';
    }
}

/**
 * @param array<string, string> $errors
 * @param array<string, string> $values
 */
function wsv_limit_inputs(string $form, array $values, array $errors, bool $required): void
{
    foreach (WSV_LIMITS as $key => $label) {
        $id = "wsv-$form-$key";
        $invalid = isset($errors[$key]);
        echo '<div class="form-group"><label for="', $id, '">', wsv_h($label), '</label>',
            // Text, not number: a number input submits "" for "12a", which would read as "not overridden".
            '<input type="text" inputmode="numeric" autocomplete="off" class="form-control" id="', $id, '" name="', $key, '" value="',
            wsv_h($values[$key] ?? ''), '"', $required ? ' required' : ' placeholder="Package or default"',
            $invalid ? ' aria-invalid="true" aria-describedby="' . $id . '-error"' : '', '>';
        wsv_field_error($errors, $key, $id);
        echo '</div>';
    }
}

/**
 * @param string[] $checked
 * @param array<string, string> $errors
 */
function wsv_template_boxes(string $form, array $checked, array $errors, I18n $i18n): void
{
    foreach (Templates::all() as $t) {
        $id = "wsv-$form-tpl-" . $t->id;
        echo '<div class="checkbox"><label for="', $id, '"><input type="checkbox" id="', $id, '" name="templates[]" value="',
            wsv_h($t->id), '"', in_array($t->id, $checked, true) ? ' checked' : '', '> ',
            wsv_h($i18n->t('template.' . $t->id)), '</label></div>';
    }
    wsv_field_error($errors, 'templates', "wsv-$form-templates");
}

function wsv_template_names(array $ids, I18n $i18n): string
{
    return $ids === [] ? 'none' : implode(', ', array_map(static fn (string $id) => $i18n->t('template.' . $id), $ids));
}

/** Posted array values as strings; anything else (a forged request) as nothing. */
function wsv_posted_templates(): array
{
    $value = $_POST['templates'] ?? [];

    return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
}

$settings = $admin->settings();
$defaults = $settings['defaults'];
$overrides = $settings['overrides'];
ksort($overrides);

// The override form edits ?user=, or the account just posted.
$target = $posted === 'override' ? wsv_param($_POST, 'user') : wsv_param($_GET, 'user');
$targetOverride = $overrides[$target] ?? [];
$effective = null;
if ($target !== '' && $posted !== 'override') {
    try {
        $effective = $admin->limits($target);
    } catch (ServiceError) {
        $errors['user'] = "There is no cPanel account named $target.";
    }
}
// A form loaded with one account's values keeps that account, so they never land on another one.
$locked = $posted === 'override' ? wsv_param($_POST, 'locked') === '1' : $effective !== null;

$accounts = array_values(array_filter(
    array_map('basename', glob(dirname($paths->cpuserFile('x')) . '/*') ?: []),
    static fn (string $u) => preg_match('/^[a-z][a-z0-9]{0,15}$/D', $u) === 1,
));

wsv_page_start('settings');

$saved = match (wsv_param($_GET, 'saved')) {
    'defaults' => 'Server defaults saved.',
    'override' => 'Override for ' . wsv_param($_GET, 'user') . ' saved.',
    'removed' => 'Override for ' . wsv_param($_GET, 'user') . ' removed.',
    default => null,
};
if ($saved !== null) {
    wsv_alert('success', $saved);
}
if (isset($errors['form'])) {
    wsv_alert('danger', $errors['form']);
} elseif ($errors !== [] && $posted !== null) {
    wsv_alert('danger', 'Nothing was saved: fix the fields marked below.');
}

// ---- Server defaults
$values = $posted === 'defaults' ? wsv_posted_limits() : array_map('strval', array_intersect_key($defaults, WSV_LIMITS));
$defaultErrors = $posted === 'defaults' ? $errors : [];
?>
<section class="wsv-section" aria-labelledby="wsv-defaults-title">
    <h2 class="wsv-subtitle" id="wsv-defaults-title">Server defaults</h2>
    <p class="wsv-muted">For every account whose package sets no Worker Supervisor values, and for accounts of
        reseller-owned packages. CPU and memory are capped by each account's CloudLinux limits, not here.</p>
    <form method="post" action="settings.php" class="wsv-form" novalidate>
        <input type="hidden" name="action" value="defaults">
        <?php wsv_limit_inputs('defaults', $values, $defaultErrors, true); ?>
        <fieldset class="form-group">
            <legend>Templates allowed</legend>
            <?php wsv_template_boxes('defaults', $posted === 'defaults' ? wsv_posted_templates() : $defaults['templates'], $defaultErrors, $i18n); ?>
            <p class="help-block">Templates simplify the form; they are not a security limit.</p>
        </fieldset>
        <button type="submit" class="btn btn-primary">Save defaults</button>
    </form>
</section>

<section class="wsv-section" id="override" aria-labelledby="wsv-override-title">
    <h2 class="wsv-subtitle" id="wsv-override-title">Account overrides</h2>
    <p class="wsv-muted">An override wins over the package and the server defaults, field by field. Accounts
        already above a lower limit keep their running workers but cannot add or grow any.</p>
<?php if ($overrides === []) : ?>
    <p class="wsv-muted">No account has an override.</p>
<?php else : ?>
    <div class="table-responsive">
        <table class="table wsv-table">
            <thead>
            <tr>
                <th scope="col">Account</th>
                <th scope="col">Max processes</th>
                <th scope="col">Max workers</th>
                <th scope="col">Max stop wait</th>
                <th scope="col">Templates</th>
                <th scope="col">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($overrides as $user => $o) : ?>
                <tr>
                    <td><?= wsv_h((string) $user) ?></td>
                    <?php foreach (array_keys(WSV_LIMITS) as $key) : ?>
                        <td><?= isset($o[$key]) ? wsv_h((string) $o[$key]) : '<span class="wsv-muted">-</span>' ?></td>
                    <?php endforeach; ?>
                    <td><?= isset($o['templates']) ? wsv_h(wsv_template_names($o['templates'], $i18n)) : '<span class="wsv-muted">-</span>' ?></td>
                    <td>
                        <form method="post" action="settings.php" class="wsv-actions">
                            <input type="hidden" name="action" value="remove_override">
                            <input type="hidden" name="user" value="<?= wsv_h((string) $user) ?>">
                            <a class="btn btn-default btn-sm" href="settings.php?user=<?= rawurlencode((string) $user) ?>#override">Edit</a>
                            <button type="submit" class="btn btn-default btn-sm">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="wsv-muted">A dash means the field is not overridden.</p>
<?php endif; ?>

<?php
$overrideErrors = $posted === 'defaults' ? [] : $errors;
$overrideValues = $posted === 'override' ? wsv_posted_limits() : array_map('strval', array_intersect_key($targetOverride, WSV_LIMITS));
$templatesOverridden = $posted === 'override' ? wsv_param($_POST, 'override_templates') === '1' : isset($targetOverride['templates']);
$checkedTemplates = $posted === 'override' ? wsv_posted_templates() : ($targetOverride['templates'] ?? $effective['templates'] ?? $defaults['templates']);
?>
    <h3><?= match (true) {
        isset($overrides[$target]) => wsv_h('Change the override of ' . $target),
        $locked => wsv_h('Add an override for ' . $target),
        default => 'Add an override',
    } ?></h3>
    <form method="post" action="settings.php" class="wsv-form" novalidate>
        <input type="hidden" name="action" value="override">
        <div class="form-group">
            <label for="wsv-override-user">Account</label>
<?php if ($locked) : ?>
            <input type="hidden" name="locked" value="1">
            <input type="text" class="form-control" id="wsv-override-user" name="user" readonly
                   aria-describedby="wsv-override-other" value="<?= wsv_h($target) ?>">
            <p class="help-block" id="wsv-override-other">
                <a href="settings.php#override">Add or change the override of another account</a>
            </p>
<?php else : ?>
            <input type="text" class="form-control" id="wsv-override-user" name="user" list="wsv-accounts" required
                   autocomplete="off" value="<?= wsv_h($target) ?>"<?= isset($overrideErrors['user']) ? ' aria-invalid="true" aria-describedby="wsv-override-user-error"' : '' ?>>
            <datalist id="wsv-accounts">
                <?php foreach ($accounts as $account) : ?>
                    <option value="<?= wsv_h($account) ?>"></option>
                <?php endforeach; ?>
            </datalist>
<?php endif; ?>
            <?php wsv_field_error($overrideErrors, 'user', 'wsv-override-user'); ?>
            <?php if ($effective !== null) : ?>
                <p class="help-block"><?= wsv_h(sprintf(
                    '%s gets now: %d processes, %d workers, %d s stop wait; templates: %s.',
                    $target,
                    $effective['max_processes'],
                    $effective['max_workers'],
                    $effective['max_stopwaitsecs'],
                    wsv_template_names($effective['templates'], $i18n),
                )) ?></p>
            <?php endif; ?>
        </div>
        <?php wsv_limit_inputs('override', $overrideValues, $overrideErrors, false); ?>
        <div class="checkbox">
            <label for="wsv-override-templates">
                <input type="checkbox" id="wsv-override-templates" name="override_templates" value="1"
                       data-wsv="templates-toggle" aria-controls="wsv-override-template-set"<?= $templatesOverridden ? ' checked' : '' ?>>
                Override the templates allowed
            </label>
        </div>
        <fieldset class="form-group wsv-indent" id="wsv-override-template-set" data-wsv="templates-set">
            <legend class="wsv-sr">Templates allowed for this account</legend>
            <?php wsv_template_boxes('override', $checkedTemplates, $overrideErrors, $i18n); ?>
        </fieldset>
        <p class="help-block">Leave a field blank to keep the package or default value.</p>
        <button type="submit" class="btn btn-primary">Save override</button>
    </form>
</section>
<?php
wsv_page_end();
