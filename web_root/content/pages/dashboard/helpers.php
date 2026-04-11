<?php
declare(strict_types=1);

function dashboard_focus_options(): array
{
    return [
        'architecture' => 'Architecture',
        'cards' => 'Cards',
        'ajax' => 'AJAX',
    ];
}

function dashboard_normalise_focus(string $focus): string
{
    $focus = strtolower(trim($focus));

    return array_key_exists($focus, dashboard_focus_options()) ? $focus : 'architecture';
}

function dashboard_focus_label(string $focus): string
{
    $options = dashboard_focus_options();

    return $options[$focus] ?? $options['architecture'];
}
