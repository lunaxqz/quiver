<?php
declare(strict_types=1);

/**
 * One text field: label, inset clay input well, optional Show/Hide button for passwords,
 * optional hint, and an error shown right beside it.
 * $o keys: value, error, hint, autocomplete, maxlength, required (bool), name, placeholder, disabled (bool), min, max, nocaps (bool)
 * Password fields are never refilled after an error.
 */
function field(string $id, string $label, string $type = 'text', array $o = []): string
{
    $name   = $o['name'] ?? $id;
    $value  = $type === 'password' ? '' : (string) ($o['value'] ?? '');
    $error  = (string) ($o['error'] ?? '');
    $hint   = (string) ($o['hint'] ?? '');
    $described = [];
    if ($hint !== '')  { $described[] = $id . '-hint'; }
    if ($error !== '') { $described[] = $id . '-error'; }

    $attrs  = ' id="' . e($id) . '" name="' . e($name) . '" type="' . e($type) . '" class="input"';
    $attrs .= ' value="' . e($value) . '"';
    if (!empty($o['autocomplete'])) { $attrs .= ' autocomplete="' . e((string) $o['autocomplete']) . '"'; }
    if (!empty($o['maxlength']))    { $attrs .= ' maxlength="' . (int) $o['maxlength'] . '"'; }
    if (isset($o['min']) && $o['min'] !== '') { $attrs .= ' min="' . e((string) $o['min']) . '"'; }
    if (isset($o['max']) && $o['max'] !== '') { $attrs .= ' max="' . e((string) $o['max']) . '"'; }
    if (!empty($o['placeholder']))  { $attrs .= ' placeholder="' . e((string) $o['placeholder']) . '"'; }
    if (!empty($o['required']))     { $attrs .= ' required'; }
    if (!empty($o['disabled']))     { $attrs .= ' disabled'; }
    if ($type === 'text' && !empty($o['nocaps'])) { $attrs .= ' autocapitalize="none" spellcheck="false"'; }
    if ($error !== '')              { $attrs .= ' aria-invalid="true"'; }
    if ($described)                 { $attrs .= ' aria-describedby="' . e(implode(' ', $described)) . '"'; }

    $html = '<div class="field"><label class="field__label" for="' . e($id) . '">' . e($label) . '</label>';
    if ($type === 'password') {
        $html .= '<div class="pw-wrap"><input' . $attrs . '>'
              .  '<button class="pw-toggle" type="button" data-toggle-pw="' . e($id) . '" aria-pressed="false">Show</button></div>';
    } else {
        $html .= '<input' . $attrs . '>';
    }
    if ($hint !== '')  { $html .= '<p class="field__hint" id="' . e($id) . '-hint">' . e($hint) . '</p>'; }
    if ($error !== '') { $html .= '<p class="field__error" id="' . e($id) . '-error">' . e($error) . '</p>'; }
    return $html . '</div>';
}

/** The friendly "please check these" box shown above a form. $errors is [fieldId => message]. */
function error_summary(array $errors): string
{
    if (!$errors) {
        return '';
    }
    $n = count($errors);
    $html = '<div class="alert alert--error" role="alert"><strong>'
          . ($n === 1 ? 'Please check 1 detail below:' : "Please check these $n details:") . '</strong><ul>';
    foreach ($errors as $id => $msg) {
        $html .= '<li><a href="#' . e((string) $id) . '">' . e($msg) . '</a></li>';
    }
    return $html . '</ul></div>';
}
