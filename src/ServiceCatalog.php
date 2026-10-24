<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Única traducción de "nombre de servicio" a carpeta canónica de Drive. La usan la resolución de
 * servicio, el archivado en Drive, el autolink y la confirmación manual, así que una variación
 * trivial de texto ("Ascensores", "Luz", "EXTINCAS") nunca vuelve a decidir la carpeta por
 * separado en cada sitio.
 *
 * Devuelve null para lo que no tiene carpeta propia (Gas, Seguros, Telecomunicaciones, Otros...):
 * antes caía en silencio en MANTENIMIENTO; ahora la política lo manda a revisión.
 */
final class ServiceCatalog
{
    public const FOLDERS = ['ELECTRICIDAD','AGUA','EXTINTORES','ASCENSOR','LIMPIEZA','JARDINERIA','PISCINA','DESCALCIFICADOR','MANTENIMIENTO'];

    /** Valor normalizado (Text::normalize) → carpeta. Incluye los nombres de service_types, las categorías de community_suppliers y las de la IA. */
    private const SYNONYMS = [
        'electricidad'=>'ELECTRICIDAD','luz'=>'ELECTRICIDAD','energia electrica'=>'ELECTRICIDAD','suministro electrico'=>'ELECTRICIDAD',
        'agua'=>'AGUA','facsa'=>'AGUA','suministro de agua'=>'AGUA','agua facsa'=>'AGUA',
        'extintores'=>'EXTINTORES','extintor'=>'EXTINTORES','extincas'=>'EXTINTORES','extncas'=>'EXTINTORES',
        'proteccion contra incendios'=>'EXTINTORES','contra incendios'=>'EXTINTORES','mantenimiento contra incendios'=>'EXTINTORES',
        'ascensor'=>'ASCENSOR','ascensores'=>'ASCENSOR','elevador'=>'ASCENSOR','elevadores'=>'ASCENSOR',
        'limpieza'=>'LIMPIEZA',
        'jardineria'=>'JARDINERIA','jardin'=>'JARDINERIA',
        'piscina'=>'PISCINA','piscinas'=>'PISCINA',
        'descalcificador'=>'DESCALCIFICADOR','descalcificadores'=>'DESCALCIFICADOR',
        'mantenimiento'=>'MANTENIMIENTO',
    ];

    public static function folderFor(?string $value): ?string
    {
        $normalized = Text::normalize($value);
        if ($normalized === '') return null;
        if (isset(self::SYNONYMS[$normalized])) return self::SYNONYMS[$normalized];
        $upper = strtoupper($normalized);
        return in_array($upper, self::FOLDERS, true) ? $upper : null;
    }
}
