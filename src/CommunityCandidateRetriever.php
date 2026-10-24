<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Propone comunidades plausibles para un documento cuyo NIF no identifica ninguna. Nunca decide:
 * su salida es una lista corta de candidatas que la IA desambigua después. Por eso prima la
 * exhaustividad sobre la precisión: incluye una comunidad si cualquiera de estas señales aparece:
 *
 * - contención: un nombre, dirección o alias de la comunidad aparece entero, como palabras
 *   completas, en el nombre o la dirección del cliente (también sin su primera palabra de tipo de
 *   vía, igual que la Fase 16);
 * - palabra rara compartida: una palabra que aparece en pocas comunidades (p. ej. "germanies",
 *   "llombai") está también en el documento;
 * - similitud global (Text::similarity) de al menos RETRIEVAL_MIN_SIMILARITY.
 *
 * El orden final es: contención, palabras raras, código postal, similitud. Se devuelven como
 * mucho MAX_CANDIDATES.
 */
final class CommunityCandidateRetriever
{
    public const MAX_CANDIDATES = 8;
    /** Bastante por debajo del antiguo umbral de decisión (92): aquí solo se propone, la IA decide. */
    public const RETRIEVAL_MIN_SIMILARITY = 60.0;
    /** Una palabra es "rara" si la comparten como mucho estas comunidades. */
    private const RARE_WORD_MAX_COMMUNITIES = 6;
    /** Palabras que no identifican a ninguna comunidad concreta. */
    private const GENERIC_WORDS = ['calle','avenida','plaza','paseo','ronda','carretera','camino','cami','via','grupo','urbanizacion',
        'comunidad','propietarios','com','prop','cdad','cp','c','cl','ctra','rd','edificio','edifici','garaje','parking','pk','bloque',
        'de','del','la','las','el','los','y','i','en','burriana','castellon','borriana','valencia','escalera','esc','bajo','numero'];

    public function __construct(private Database $db) {}

    /**
     * @param array<string,mixed> $customer comprensión: name, address, postal_code
     * @param array<string,string> $folderNames código externo → nombre real de la carpeta de Drive
     * @return list<array{community:array<string,mixed>,signals:list<string>,similarity:float}>
     */
    public function candidates(array $customer, array $folderNames = []): array
    {
        $queries = array_values(array_filter([Text::normalize($customer['name'] ?? null), Text::normalize($customer['address'] ?? null)], static fn(string $q): bool => $q !== ''));
        if (!$queries) return [];
        $postalCode = trim((string)($customer['postal_code'] ?? ''));
        $communities = $this->db->all('SELECT * FROM communities WHERE active=1');
        $aliases = [];
        foreach ($this->db->all('SELECT community_id,value FROM community_aliases WHERE active=1') as $alias) $aliases[(int)$alias['community_id']][] = (string)$alias['value'];

        $textsById = [];
        foreach ($communities as $community) {
            $texts = [(string)$community['official_name'], (string)$community['main_address']];
            foreach ($aliases[(int)$community['id']] ?? [] as $alias) $texts[] = $alias;
            $folder = $folderNames[(string)$community['external_code']] ?? null;
            if ($folder !== null) $texts[] = self::withoutLeadingCode($folder);
            $textsById[(int)$community['id']] = array_values(array_filter(array_map([Text::class, 'normalize'], $texts), static fn(string $t): bool => $t !== ''));
        }
        $frequency = [];
        foreach ($textsById as $texts) {
            foreach (array_unique(self::distinctiveWords($texts)) as $word) $frequency[$word] = ($frequency[$word] ?? 0) + 1;
        }
        $queryWords = array_flip(self::distinctiveWords($queries));

        $found = [];
        foreach ($communities as $community) {
            $texts = $textsById[(int)$community['id']];
            $signals = [];
            foreach ($texts as $text) {
                $stripped = Text::stripLeadingAddressWord($text);
                foreach ($queries as $query) {
                    if (Text::containsWholeWords($query, $text) || ($stripped !== $text && Text::containsWholeWords($query, $stripped))) { $signals['containment'] = true; break 2; }
                }
            }
            foreach (array_unique(self::distinctiveWords($texts)) as $word) {
                if (isset($queryWords[$word]) && ($frequency[$word] ?? 99) <= self::RARE_WORD_MAX_COMMUNITIES) $signals['rare_word:'.$word] = true;
            }
            if ($postalCode !== '' && trim((string)($community['postal_code'] ?? '')) === $postalCode) $signals['postal_code'] = true;
            $similarity = 0.0;
            foreach ($texts as $text) foreach ($queries as $query) $similarity = max($similarity, Text::similarity($query, $text));
            if ($similarity >= self::RETRIEVAL_MIN_SIMILARITY) $signals['similarity'] = true;
            $strong = isset($signals['containment']) || isset($signals['similarity']) || array_filter(array_keys($signals), static fn(string $s): bool => str_starts_with($s, 'rare_word:'));
            if (!$strong) continue;
            $found[] = ['community'=>$community,'signals'=>array_keys($signals),'similarity'=>$similarity];
        }
        usort($found, static function (array $a, array $b): int {
            $rank = static fn(array $c): array => [
                in_array('containment', $c['signals'], true) ? 1 : 0,
                count(array_filter($c['signals'], static fn(string $s): bool => str_starts_with($s, 'rare_word:'))),
                in_array('postal_code', $c['signals'], true) ? 1 : 0,
                $c['similarity'],
            ];
            return $rank($b) <=> $rank($a);
        });
        return array_slice($found, 0, self::MAX_CANDIDATES);
    }

    /** "66-PK GERMANIAS" → "PK GERMANIAS". */
    public static function withoutLeadingCode(string $folderName): string
    {
        return trim((string)preg_replace('/^\s*\d+\s*[-–.]?\s*/u', '', $folderName));
    }

    /** @param list<string> $texts ya normalizados @return list<string> */
    private static function distinctiveWords(array $texts): array
    {
        $words = [];
        foreach ($texts as $text) {
            foreach (explode(' ', $text) as $word) {
                if (strlen($word) < 3 || ctype_digit($word) || in_array($word, self::GENERIC_WORDS, true)) continue;
                $words[] = $word;
            }
        }
        return $words;
    }
}
