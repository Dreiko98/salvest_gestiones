<?php
declare(strict_types=1);

namespace Salvest;

/**
 * ¿A qué servicio (y por tanto a qué carpeta de Drive) pertenece la factura? Mismo orden que antes:
 *
 * 1. servicio principal del proveedor (suppliers.main_service_type_id → service_types.name);
 * 2. categoría de la relación comunidad-proveedor (community_suppliers.category);
 * 3. categoría semántica propuesta por la IA;
 * 4. descripción libre del servicio que dio la IA.
 *
 * Gana la primera fuente que ServiceCatalog sabe traducir a una carpeta canónica. Si ninguna se
 * puede traducir, no hay servicio válido y la política manda la factura a revisión en lugar de
 * archivarla en MANTENIMIENTO por descarte.
 */
final class ServiceResolver
{
    /**
     * @param array<string,mixed>|null $supplier fila con service_type_name @param array<string,mixed>|null $relation
     * @param array<string,mixed> $understanding
     * @return array{name:?string,folder:?string,source:?string,tried:list<array{source:string,value:string,folder:?string}>}
     */
    public function resolve(?array $supplier, ?array $relation, array $understanding): array
    {
        $sources = [
            'supplier_main_service' => $supplier['service_type_name'] ?? null,
            'community_supplier_category' => $relation['category'] ?? null,
            'ai_semantic_category' => $understanding['service']['semantic_category'] ?? null,
            'ai_service_text' => $understanding['service']['raw'] ?? null,
        ];
        $tried = [];
        foreach ($sources as $source => $value) {
            if ($value === null || trim((string)$value) === '') continue;
            $folder = ServiceCatalog::folderFor((string)$value);
            $tried[] = ['source'=>$source,'value'=>(string)$value,'folder'=>$folder];
            if ($folder !== null) return ['name'=>(string)$value,'folder'=>$folder,'source'=>$source,'tried'=>$tried];
        }
        return ['name'=>null,'folder'=>null,'source'=>null,'tried'=>$tried];
    }
}
