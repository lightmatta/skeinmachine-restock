<?php
declare(strict_types=1);

namespace App;

/**
 * Very small view renderer. Templates are plain PHP files under app/Views.
 */
class View
{
    /** Render a view inside the main layout and echo it. */
    public static function render(string $template, array $data = [], string $layout = 'layouts/app'): void
    {
        $content = self::capture($template, $data);
        $data['content'] = $content;
        echo self::capture($layout, $data);
    }

    /** Render a view without a layout (e.g. print pages, partials). */
    public static function renderRaw(string $template, array $data = []): void
    {
        echo self::capture($template, $data);
    }

    public static function capture(string $template, array $data = []): string
    {
        $file = APP_ROOT . '/app/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string)ob_get_clean();
    }
}
