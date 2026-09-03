<?php
declare(strict_types=1);

namespace Salvest;

final class Text
{
    public static function normalize(?string $value): string
    {
        $value = trim((string)$value);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $ascii = mb_strtolower($ascii, 'UTF-8');
        $words = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $replacements = ['avinguda'=>'avenida','avda'=>'avenida','carrer'=>'calle','placa'=>'plaza'];
        return implode(' ', array_map(static fn(string $word): string => $replacements[$word] ?? $word, $words));
    }

    /**
     * For CIF/NIF and other fiscal identifiers only — NOT a replacement for normalize().
     * normalize() deliberately turns separators into a single space (it's built for matching
     * addresses/names as sequences of words), so "H-12815601" -> "h 12815601" while
     * "H12815601" -> "h12815601": two representations of the exact same identifier end up
     * different. Identifiers have no words to preserve, so every separator is just dropped:
     * "H-12815601", "H12815601", "H 12815601" and "h12815601" all become "h12815601".
     */
    public static function normalizeIdentifier(?string $value): string
    {
        $value = trim((string)$value);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        return mb_strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '', $ascii), 'UTF-8');
    }

    /** Common Spanish legal-form suffixes, longest first so "s a u" strips before a
     * shorter form could partially match. Used only for comparing supplier/company
     * names — general normalize() must stay untouched for addresses etc. */
    private const LEGAL_FORMS = [
        'sociedad anonima unipersonal','sociedad limitada unipersonal','sociedad cooperativa',
        'sociedad anonima','sociedad limitada','s a u','s l u','sdad coop','s coop',
        'sau','slu','scoop','s c','s a','s l','sa','sl','sc',
    ];

    /** normalize() plus stripping one trailing legal-form suffix (S.A., S.A.U., S.L., S.L.U., S. Coop...),
     * so "IBERDROLA CLIENTES, S.A.U." and "IBERDROLA" compare on their actual commercial name. */
    public static function normalizeCompanyName(?string $value): string
    {
        $normalized = self::normalize($value);
        foreach (self::LEGAL_FORMS as $form) {
            $stripped = preg_replace('/\s+'.preg_quote($form, '/').'$/', '', $normalized);
            if ($stripped !== null && $stripped !== $normalized && trim($stripped) !== '') return trim($stripped);
        }
        return $normalized;
    }

    /** Does $needle appear as a contiguous run of whole words inside $haystack? Both must already be normalized (space-separated words). */
    public static function containsWholeWords(string $haystack, string $needle): bool
    {
        if ($needle === '' || $haystack === '') return false;
        $haystackWords = explode(' ', $haystack);
        $needleWords = explode(' ', $needle);
        $needleCount = count($needleWords);
        for ($i = 0, $limit = count($haystackWords) - $needleCount; $i <= $limit; $i++) {
            if (array_slice($haystackWords, $i, $needleCount) === $needleWords) return true;
        }
        return false;
    }

    /** Palabras genéricas de tipo de vía al principio de una dirección normalizada — de sobra
     * conocidas, no aportan nada para identificar UNA comunidad en concreto. Muchos proveedores
     * las omiten al escribir la dirección del cliente aunque la comunidad esté dada de alta con
     * ese prefijo ("Calle Encarnacion 35" en el maestro, "Encarnacion 35" en la factura) — o al
     * revés. Fase 16: quita como mucho UNA palabra genérica, y solo si está al principio, nunca
     * en medio ni más de una — así dos direcciones que solo difieren en ese prefijo cuentan como
     * la misma, sin arriesgarse a recortar una palabra que sí distinga una comunidad de otra. */
    private const GENERIC_ADDRESS_WORDS = ['calle','avenida','plaza','paseo','ronda','carretera','camino','via','grupo','urbanizacion'];
    public static function stripLeadingAddressWord(string $normalized): string
    {
        $words = explode(' ', $normalized);
        if (count($words) > 1 && in_array($words[0], self::GENERIC_ADDRESS_WORDS, true)) {
            array_shift($words);
            return implode(' ', $words);
        }
        return $normalized;
    }

    /** Does every word of $needle appear somewhere among $haystack's words, in any order? */
    public static function containsAllWords(string $haystack, string $needle): bool
    {
        if ($needle === '' || $haystack === '') return false;
        $haystackWords = array_flip(explode(' ', $haystack));
        foreach (explode(' ', $needle) as $word) {
            if ($word === '' || !isset($haystackWords[$word])) return false;
        }
        return true;
    }

    public static function slug(?string $value): string
    {
        return trim((string)preg_replace('/[^a-z0-9]+/', '-', self::normalize($value)), '-') ?: 'desconocido';
    }

    public static function similarity(string $left, string $right): float
    {
        $left = self::normalize($left); $right = self::normalize($right);
        if ($left === '' || $right === '') return 0.0;
        similar_text($left, $right, $characterScore);
        $a = array_unique(explode(' ', $left)); $b = array_unique(explode(' ', $right));
        $intersection = count(array_intersect($a, $b));
        $tokenScore = (2 * $intersection / max(1, count($a) + count($b))) * 100;
        return round(0.65 * $characterScore + 0.35 * $tokenScore, 2);
    }

    public static function safeFilename(string $name, int $index, string $extension = '.pdf'): string
    {
        $name = basename(str_replace('\\', '/', trim($name))) ?: "adjunto-sin-nombre-$index$extension";
        $safe = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $name), '._');
        if ($safe === '') $safe = "adjunto-$index$extension";
        if (!preg_match('/\.(pdf|jpe?g|png|tiff?|webp)$/i', $safe)) $safe .= $extension;
        return $safe;
    }
}
