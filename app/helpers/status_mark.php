<?php
declare(strict_types=1);

/**
 * Draws one status mark: a ring + glyph (drawn by CSS) + a visible text label.
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
