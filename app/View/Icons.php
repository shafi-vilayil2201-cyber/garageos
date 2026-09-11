<?php

// A small, consistent line-icon set (feather-style: 24x24 viewBox, currentColor
// stroke) so every icon in the app means the same thing everywhere it appears.
// No icon font or CDN dependency — just inline SVG.

function icon(string $name, int $size = 18): string
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'job-card' => '<path d="M9 2h6a1 1 0 0 1 1 1v2H8V3a1 1 0 0 1 1-1z"/><rect x="5" y="4" width="14" height="18" rx="2"/><line x1="9" y1="11" x2="15" y2="11"/><line x1="9" y1="15" x2="15" y2="15"/>',
        'calendar' => '<rect x="3" y="4.5" width="18" height="16.5" rx="2"/><line x1="16" y1="2.5" x2="16" y2="6.5"/><line x1="8" y1="2.5" x2="8" y2="6.5"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'bell' => '<path d="M18 8.5a6 6 0 0 0-12 0c0 6.5-2.5 8.5-2.5 8.5h17S18 15 18 8.5z"/><path d="M13.7 20.5a2 2 0 0 1-3.4 0"/>',
        'box' => '<path d="M21 8.5v7a1.6 1.6 0 0 1-.83 1.4l-7 4a1.6 1.6 0 0 1-1.64 0l-7-4A1.6 1.6 0 0 1 3 15.5v-7a1.6 1.6 0 0 1 .83-1.4l7-4a1.6 1.6 0 0 1 1.64 0l7 4A1.6 1.6 0 0 1 21 8.5z"/><polyline points="3.3 7 12 12 20.7 7"/><line x1="12" y1="22" x2="12" y2="12"/>',
        'person' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20.5a7 7 0 0 1 14 0"/>',
        'car' => '<path d="M4 16v-3.2a1.6 1.6 0 0 1 .1-.5l1.3-3.6A2 2 0 0 1 7.3 7.4h9.4a2 2 0 0 1 1.9 1.3l1.3 3.6a1.6 1.6 0 0 1 .1.5V16"/><path d="M3 16h18v3a1 1 0 0 1-1 1h-1.2a1 1 0 0 1-1-1v-1H6.2v1a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-3z"/><circle cx="7.5" cy="16" r="1.6"/><circle cx="16.5" cy="16" r="1.6"/>',
        'truck' => '<rect x="1.5" y="6.5" width="13" height="10" rx="1"/><path d="M14.5 10.5h4.2a1 1 0 0 1 .8.4l2 2.6a1 1 0 0 1 .2.6v2.4a1 1 0 0 1-1 1h-1.2"/><circle cx="6" cy="18.5" r="2"/><circle cx="17.5" cy="18.5" r="2"/>',
        'warehouse' => '<path d="M4 21V9.3a1.4 1.4 0 0 1 .7-1.2l6.6-3.8a1.4 1.4 0 0 1 1.4 0l6.6 3.8a1.4 1.4 0 0 1 .7 1.2V21"/><path d="M2.5 21h19"/><path d="M9 21v-5.5h6V21"/>',
        'chart' => '<line x1="6" y1="20" x2="6" y2="13"/><line x1="12" y1="20" x2="12" y2="5"/><line x1="18" y1="20" x2="18" y2="10"/><line x1="3" y1="20" x2="21" y2="20"/>',
        'team' => '<circle cx="9" cy="8" r="3.2"/><path d="M2.7 20a6.3 6.3 0 0 1 12.6 0"/><path d="M16 5.3a3.2 3.2 0 0 1 0 6.2"/><path d="M22 20a6.3 6.3 0 0 0-4.8-6.1"/>',
        'settings' => '<circle cx="12" cy="12" r="3.2"/><path d="M19.4 13.8a1.7 1.7 0 0 0 .3 1.9l.1.1a2.1 2.1 0 1 1-3 3l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V20a2.1 2.1 0 0 1-4.2 0v-.1a1.7 1.7 0 0 0-1.1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2.1 2.1 0 1 1-3-3l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.6-1H2a2.1 2.1 0 0 1 0-4.2h.1a1.7 1.7 0 0 0 1.6-1.1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2.1 2.1 0 1 1 3-3l.1.1a1.7 1.7 0 0 0 1.9.3H8.4a1.7 1.7 0 0 0 1-1.6V2a2.1 2.1 0 0 1 4.2 0v.1a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1a2.1 2.1 0 1 1 3 3l-.1.1a1.7 1.7 0 0 0-.3 1.9v.1a1.7 1.7 0 0 0 1.6 1H22a2.1 2.1 0 0 1 0 4.2h-.1a1.7 1.7 0 0 0-1.6 1.1z"/>',
        'logout' => '<path d="M9 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3"/><polyline points="15.5 16.5 20 12 15.5 7.5"/><line x1="20" y1="12" x2="8.5" y2="12"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="7"/><line x1="20.5" y1="20.5" x2="15.7" y2="15.7"/>',
        'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'x' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'check' => '<polyline points="20 6.5 9.5 17 4 11.5"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><polyline points="8 12.5 11 15.5 16 9"/>',
        'alert-triangle' => '<path d="M10.4 3.6 1.9 18.3A1.7 1.7 0 0 0 3.4 21h17.2a1.7 1.7 0 0 0 1.5-2.7L13.6 3.6a1.7 1.7 0 0 0-3.2 0z"/><line x1="12" y1="9.5" x2="12" y2="13.5"/><circle cx="12" cy="16.7" r="0.15" fill="currentColor" stroke-width="1.4"/>',
        'trending-up' => '<polyline points="3 17 9.5 10.5 14 15 21 7.5"/><polyline points="15 7.5 21 7.5 21 13.5"/>',
        'wallet' => '<path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H18a1 1 0 0 1 1 1v2"/><path d="M3 7.5v10A2.5 2.5 0 0 0 5.5 20H19a1 1 0 0 0 1-1v-3.5"/><rect x="14.5" y="10.5" width="7" height="5" rx="1.2"/><circle cx="17" cy="13" r="0.9" fill="currentColor" stroke-width="0"/>',
        'receipt' => '<path d="M6 3h12a1 1 0 0 1 1 1v17l-2.5-1.5L14 21l-2-1.5L10 21l-2.5-1.5L5 21V4a1 1 0 0 1 1-1z"/><line x1="8.5" y1="8" x2="15.5" y2="8"/><line x1="8.5" y1="12" x2="15.5" y2="12"/>',
        'building' => '<rect x="4" y="2.5" width="10" height="19" rx="1"/><rect x="14" y="9" width="6" height="12.5" rx="1"/><line x1="7" y1="6" x2="7" y2="6.01"/><line x1="10.5" y1="6" x2="10.5" y2="6.01"/><line x1="7" y1="10" x2="7" y2="10.01"/><line x1="10.5" y1="10" x2="10.5" y2="10.01"/><line x1="7" y1="14" x2="7" y2="14.01"/><line x1="10.5" y1="14" x2="10.5" y2="14.01"/>',
        'phone' => '<path d="M4.5 3.5h3.2l1.3 4.2-2 1.7a12.5 12.5 0 0 0 5.6 5.6l1.7-2 4.2 1.3v3.2a1.3 1.3 0 0 1-1.4 1.3A17 17 0 0 1 3.2 4.9a1.3 1.3 0 0 1 1.3-1.4z"/>',
        'clipboard-list' => '<path d="M9 3h6a1 1 0 0 1 1 1v1.5H8V4a1 1 0 0 1 1-1z"/><rect x="4.5" y="4.5" width="15" height="17" rx="2"/><line x1="8.5" y1="11" x2="8.51" y2="11"/><line x1="12" y1="11" x2="15.5" y2="11"/><line x1="8.5" y1="15.5" x2="8.51" y2="15.5"/><line x1="12" y1="15.5" x2="15.5" y2="15.5"/>',
        'sparkle' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M18 6l-2.5 2.5M8.5 15.5 6 18"/>',
        'message' => '<path d="M20.5 11.5a8 8 0 0 1-8.4 8 8.4 8.4 0 0 1-3.6-.8L3.5 20l1.3-4.5a8.4 8.4 0 0 1-.8-3.6 8 8 0 0 1 8-8h.2a8 8 0 0 1 7.8 7.8z"/>',
        'pause' => '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>',
        'arrow-left' => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="11 18.5 4.5 12 11 5.5"/>',
        'chevron-left' => '<polyline points="15 18 9 12 15 6"/>',
        'chevron-right' => '<polyline points="9 18 15 12 9 6"/>',
    ];

    $body = $paths[$name] ?? $paths['box'];

    return sprintf(
        '<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%2$s</svg>',
        $size,
        $body
    );
}
