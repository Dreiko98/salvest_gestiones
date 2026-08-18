<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Pure HTML rendering for the Google Drive folder/file explorer shown in
 * "Almacenamiento". Kept free of network and WebApp concerns so it can be
 * unit-tested with plain arrays shaped like the Drive v3 files.list response.
 */
final class DriveTree
{
    private const FOLDER_MIME = 'application/vnd.google-apps.folder';

    /**
     * Top-level render used for the root (communities) listing.
     * @param list<array<string,mixed>> $items
     */
    public static function renderRoot(array $items): string
    {
        if (!$items) {
            return '<section class="empty-state storage-empty"><span class="empty-ring"><i></i></span>'
                .'<h2>No hay carpetas de comunidad</h2><p>Se crearán cuando se archive la primera factura.</p></section>';
        }
        return '<div class="folder-tree">'.self::renderNodes($items, 1).'</div>';
    }

    /**
     * Renders one level of nodes (folders, expandable, followed by files, as leaves).
     * Returns the empty-state markup for a genuinely empty folder — never the
     * misleading "no subfolders" message when the folder actually contains files.
     * @param list<array<string,mixed>> $items
     */
    public static function renderNodes(array $items, int $level): string
    {
        if (!$items) {
            return '<div class="folder-empty">Carpeta vacía.</div>';
        }
        $html = '';
        foreach ($items as $item) {
            $html .= self::isFolder($item)
                ? self::folderNode((string)($item['id'] ?? ''), (string)($item['name'] ?? ''), $level)
                : self::fileNode((string)($item['name'] ?? ''), self::stringOrNull($item['webViewLink'] ?? null));
        }
        return $html;
    }

    /** @param array<string,mixed> $item */
    public static function isFolder(array $item): bool
    {
        return ($item['mimeType'] ?? '') === self::FOLDER_MIME;
    }

    public static function folderNode(string $id, string $name, int $level): string
    {
        $level = max(1, min(5, $level));
        return '<details class="folder-node level-'.$level.'" data-folder-id="'.self::e($id).'" data-level="'.$level.'">'
            .'<summary><span class="folder-icon"></span>'.self::name($name).'</summary>'
            .'<div class="folder-children" data-folder-children><div class="folder-loading">Cargando...</div></div>'
            .'</details>';
    }

    public static function fileNode(string $name, ?string $webViewLink): string
    {
        $label = '<span class="file-icon"></span>'.self::name($name);
        $content = $webViewLink !== null && $webViewLink !== ''
            ? '<a class="node-link" href="'.self::e($webViewLink).'" target="_blank" rel="noopener noreferrer">'.$label.'</a>'
            : '<span class="node-link">'.$label.'</span>';
        return '<div class="folder-leaf">'.$content.'</div>';
    }

    /** Truncated, title-tooltipped label so long invoice filenames never overflow the card. */
    private static function name(string $value): string
    {
        return '<span class="node-name" title="'.self::e($value).'">'.self::e($value).'</span>';
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string)$value;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
