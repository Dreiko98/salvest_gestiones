<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Pure HTML rendering for the "click a row to see its details" pattern used
 * in the Comunidades and Proveedores tables. Kept free of Database/Auth
 * concerns so it can be unit-tested directly.
 */
final class RowDetail
{
    /**
     * Wraps the first cell's content in an accessible toggle button.
     * $label is the existing cell markup (e.g. the code/name badge).
     */
    public static function toggle(string $id, string $label): string
    {
        return '<button type="button" class="row-toggle" aria-expanded="false" aria-controls="'.self::e($id).'">'
            .$label.'<span class="row-toggle-icon" aria-hidden="true"></span></button>';
    }

    /**
     * Renders the hidden detail row placed right after the summary row.
     * @param list<array{0:string,1:string}> $pairs label/value pairs, values already safe HTML or plain text
     * @param int $colspan number of columns in the table so the detail cell can span the full width
     */
    public static function row(string $id, array $pairs, int $colspan): string
    {
        $items = '';
        foreach ($pairs as [$label, $value]) {
            $items .= '<div class="detail-item"><span>'.self::e($label).'</span><strong>'.self::e($value !== '' ? $value : '—').'</strong></div>';
        }
        return '<tr id="'.self::e($id).'" class="row-detail" hidden><td colspan="'.$colspan.'"><div class="detail-grid">'.$items.'</div></td></tr>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
