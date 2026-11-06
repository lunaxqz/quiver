<?php
declare(strict_types=1);

/**
 * Draws one status mark: an icon + word + soft pastel colour pill.
 * $state must be one of: neutral, scheduled, active, attention, complete, problem.
 * Use it everywhere a state is shown, so every screen speaks the same language.
 */
function status_mark(string $state, string $label): string
{
    $allowed = ['neutral', 'scheduled', 'active', 'attention', 'complete', 'problem'];
    if (!in_array($state, $allowed, true)) {
        $state = 'neutral';
    }
    $label = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<span class="mark" data-state="' . $state . '">'
         . '<span class="mark__ring" aria-hidden="true"></span>'
         . '<span class="mark__label">' . $label . '</span>'
         . '</span>';
}

/**
 * Draws a compact, plain-language status legend wherever status badges are introduced.
 * $items is a list of [$state, $label] pairs, e.g. [['active', 'Open now'], ['complete', 'Done']].
 */
function status_legend(array $items, string $title = 'What statuses mean:'): string
{
    $t = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<div class="status-legend" role="note" aria-label="Status guide">'
          . '<span class="status-legend__title">' . $t . '</span>';
    foreach ($items as [$state, $label]) {
        $html .= status_mark((string) $state, (string) $label);
    }
    return $html . '</div>';
}
