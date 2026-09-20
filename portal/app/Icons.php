<?php
declare(strict_types=1);

namespace App;

/**
 * Inline SVG icon set. Minimal, futuristic, single-stroke line icons that
 * inherit the current text color (stroke="currentColor"), so they render as
 * black outlines by default and can be tinted with the highlight color.
 */
class Icons
{
    public static function get(string $name, int $size = 20, string $class = 'icon'): string
    {
        $paths = self::paths();
        $body = $paths[$name] ?? $paths['dot'];
        return sprintf(
            '<svg class="%s" width="%d" height="%d" viewBox="0 0 24 24" fill="none" '
            . 'stroke="currentColor" stroke-width="1.6" stroke-linecap="round" '
            . 'stroke-linejoin="round" aria-hidden="true">%s</svg>',
            e($class),
            $size,
            $size,
            $body
        );
    }

    private static function paths(): array
    {
        return [
            'dot'       => '<circle cx="12" cy="12" r="2"/>',
            'grid'      => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
            'orders'    => '<path d="M4 4h11l5 5v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Z"/><path d="M14 4v5h5"/><path d="M8 13h8M8 17h6"/>',
            'users'     => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 20a5.5 5.5 0 0 1 11 0"/><path d="M16 6.5a3 3 0 0 1 0 5.8"/><path d="M17.5 20a5 5 0 0 0-3-4.6"/>',
            'message'   => '<path d="M20 15a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2Z"/><path d="M8 9h8M8 12.5h5"/>',
            'box'       => '<path d="M12 2 3 6.5v11L12 22l9-4.5v-11Z"/><path d="M3 6.5 12 11l9-4.5"/><path d="M12 11v11"/>',
            'bundle'    => '<path d="M3 8l9-5 9 5-9 5-9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M7.5 10.2v4.3M16.5 10.2v4.3"/>',
            'chart'     => '<path d="M4 4v16h16"/><path d="M8 16v-4M12 16V8M16 16v-6"/>',
            'settings'  => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9 17 7M7 17l-2.1 2.1"/>',
            'search'    => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/>',
            'cart'      => '<path d="M3 4h2l2.2 12.2a1 1 0 0 0 1 .8h8.6a1 1 0 0 0 1-.8L21 8H6"/><circle cx="9.5" cy="20" r="1.3"/><circle cx="17.5" cy="20" r="1.3"/>',
            'plus'      => '<path d="M12 5v14M5 12h14"/>',
            'minus'     => '<path d="M5 12h14"/>',
            'trash'     => '<path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>',
            'edit'      => '<path d="M4 20h4L20 8a2 2 0 0 0-3-3L5 17v3Z"/><path d="M14 6l4 4"/>',
            'check'     => '<path d="m4 12 5 5L20 6"/>',
            'x'         => '<path d="M6 6l12 12M18 6 6 18"/>',
            'logout'    => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 12h10M17 9l3 3-3 3"/><path d="M4 4v16"/>',
            'login'     => '<path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3"/><path d="M14 12H4M11 9l3 3-3 3"/><path d="M20 4v16"/>',
            'send'      => '<path d="M4 12 20 4l-6 16-3-7-7-1Z"/>',
            'print'     => '<path d="M7 8V3h10v5"/><path d="M7 18H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"/><rect x="7" y="14" width="10" height="7" rx="1"/>',
            'truck'     => '<path d="M3 6h11v9H3zM14 9h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17.5" cy="18" r="1.6"/>',
            'clock'     => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
            'phone'     => '<path d="M6 3h3l2 5-2.5 1.5a12 12 0 0 0 5 5L17 14l5 2v3a2 2 0 0 1-2 2A16 16 0 0 1 4 5a2 2 0 0 1 2-2Z"/>',
            'mail'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
            'yarn'      => '<circle cx="12" cy="12" r="9"/><path d="M5 9c4 2 10 2 14 0M4 14c5 2 11 2 16 0M9 3.5C7 7 7 17 9.5 20.5M15 3.5C17 7 17 17 14.5 20.5"/>',
            'sparkle'   => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8Z"/><path d="M18 14l.9 2.4L21 17l-2.1.6L18 20l-.9-2.4L15 17l2.1-.6Z"/>',
            'filter'    => '<path d="M3 5h18l-7 8v6l-4-2v-4Z"/>',
            'columns'   => '<rect x="3" y="4" width="18" height="16" rx="1"/><path d="M9 4v16M15 4v16"/>',
            'archive'   => '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8M10 12h4"/>',
            'menu'      => '<path d="M4 6h16M4 12h16M4 18h16"/>',
            'grip'      => '<circle cx="9" cy="6" r="1.2"/><circle cx="15" cy="6" r="1.2"/><circle cx="9" cy="12" r="1.2"/><circle cx="15" cy="12" r="1.2"/><circle cx="9" cy="18" r="1.2"/><circle cx="15" cy="18" r="1.2"/>',
            'user'      => '<circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',
            'store'     => '<path d="M4 9V6a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v3"/><path d="M3 9h18l-1 3a2.2 2.2 0 0 1-4.3 0 2.2 2.2 0 0 1-4.3 0 2.2 2.2 0 0 1-4.3 0L3 9Z"/><path d="M5 13v7h14v-7"/>',
            'chevron'   => '<path d="m6 9 6 6 6-6"/>',
            'expand'    => '<path d="M8 3H5a2 2 0 0 0-2 2v3M16 3h3a2 2 0 0 1 2 2v3M8 21H5a2 2 0 0 1-2-2v-3M16 21h3a2 2 0 0 0 2-2v-3"/>',
            'shrink'    => '<path d="M9 3v3a2 2 0 0 1-2 2H4M15 3v3a2 2 0 0 0 2 2h3M9 21v-3a2 2 0 0 0-2-2H4M15 21v-3a2 2 0 0 1 2-2h3"/>',
            'copy'      => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
            'refresh'   => '<path d="M21 12a9 9 0 1 1-2.6-6.4M21 3v5h-5"/>',
            'link'      => '<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>',
        ];
    }
}
