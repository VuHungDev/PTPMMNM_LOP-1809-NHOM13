<?php
/** Small shared SVG sprite. No external icon service or user-supplied SVG markup. */
function aj_action_icon_paths(): array
{
    return [
        'view' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'hide' => '<path d="m3 3 18 18M10.6 5.1A12.5 12.5 0 0 1 12 5c6.5 0 10 7 10 7a18.4 18.4 0 0 1-3 3.8M6.2 6.2A19.5 19.5 0 0 0 2 12s3.5 7 10 7a12.4 12.4 0 0 0 5.1-1.1M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
        'show' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'edit' => '<path d="m15 5 4 4M4 20l4.5-1L20 7.5a2.8 2.8 0 0 0-4-4L4.5 15 4 20Z"/><path d="M13 20h7"/>',
        'delete' => '<path d="M3 6h18M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M5 6l1 14a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1l1-14M10 10v7M14 10v7"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/>',
        'unlock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 7.5-2M12 14v3"/>',
    ];
}

function aj_action_icon(string $name): string
{
    if (!array_key_exists($name, aj_action_icon_paths())) throw new InvalidArgumentException('Unknown action icon.');
    return '<svg class="aj-action-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><use href="#aj-icon-' . $name . '"></use></svg>';
}

function aj_action_icon_sprite(): string
{
    $symbols = '';
    foreach (aj_action_icon_paths() as $name => $paths) {
        $symbols .= '<symbol id="aj-icon-' . $name . '" viewBox="0 0 24 24">' . $paths . '</symbol>';
    }
    return '<svg class="aj-icon-definitions" xmlns="http://www.w3.org/2000/svg" width="0" height="0" aria-hidden="true" focusable="false">' . $symbols . '</svg>';
}
