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
