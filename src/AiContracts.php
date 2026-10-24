<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Fuente única de los esquemas y las instrucciones que se envían a la IA. Los adaptadores
 * (ClaudeClient, OpenAIClient) solo transportan; DocumentIntelligence valida lo que vuelve.
 *
 * Los esquemas cumplen el modo estricto de OpenAI: todo objeto lista todas sus propiedades como
 * obligatorias, `additionalProperties: false`, y los valores ausentes son `null`, nunca omitidos.
 */
final class AiContracts
{
    public const UNDERSTAND_TASK = 'comprender_documento';
    public const COMMUNITY_TASK = 'elegir_comunidad';
    public const SUPPLIER_TASK = 'elegir_proveedor';

    /** Tipos de documento. Solo los dos primeros se procesan como factura. */
    public const DOCUMENT_TYPES = ['invoice','credit_note','receipt','budget','technical_report','report','certificate','contract','other'];
    public const INVOICE_TYPES = ['invoice','credit_note'];

    /** Categorías de servicio que la IA puede proponer: coinciden con las carpetas de Drive (ServiceCatalog), más "otro". */
    public const SERVICE_CATEGORIES = ['electricidad','agua','extintores','ascensor','limpieza','jardineria','piscina','descalcificador','mantenimiento','otro'];

    /** Rúbrica común de confianza: la política usa estos mismos tramos (ClassificationPolicy). */
    private const CONFIDENCE_RUBRIC = "Escala de confianza (número entre 0 y 1), úsala de forma literal:\n"
        ."- 0.90 a 1.00: el propio documento lo dice de forma explícita e inequívoca.\n"
        ."- 0.70 a 0.89: evidencia fuerte pero indirecta (sinónimos, abreviaturas, datos parciales) y ninguna alternativa encaja igual de bien.\n"
        ."- menos de 0.70: evidencia débil, incompleta o contradictoria.";

    /** @return array<string,mixed> */
    public static function understandingSchema(): array
    {
        $string = ['type'=>['string','null']];
        $object = static fn(array $properties): array => ['type'=>'object','properties'=>$properties,'required'=>array_keys($properties),'additionalProperties'=>false];
        return $object([
            'document_type' => ['type'=>'string','enum'=>self::DOCUMENT_TYPES],
            'should_process_as_invoice' => ['type'=>'boolean'],
            'document_confidence' => ['type'=>'number'],
            'document_reason' => ['type'=>'string'],
            'issuer' => $object(['name'=>$string,'tax_id'=>$string]),
            'customer' => $object(['name'=>$string,'tax_id'=>$string,'address'=>$string,'postal_code'=>$string]),
            'invoice' => $object(['number'=>$string,'date'=>$string,'total_amount'=>['type'=>['number','null']],'currency'=>$string,'billing_period'=>$string]),
            'service' => $object(['raw'=>$string,'semantic_category'=>['type'=>['string','null'],'enum'=>array_merge(self::SERVICE_CATEGORIES,[null])]]),
            'supply_identifiers' => $object(['cups'=>$string,'contract_number'=>$string,'customer_reference'=>$string]),
        ]);
    }

    public static function understandingInstructions(): string
    {
        return "Eres el lector de documentos de una administración de fincas. Recibes un PDF que ha llegado por correo a la administración y tienes que decir qué es y, si es una factura, transcribir sus datos. Nunca inventes: si un dato no aparece en el documento, usa null.\n\n"
            ."1. QUÉ DOCUMENTO ES (document_type). Decide por el contenido y la estructura del propio documento, no por un único campo. Tipos:\n"
            ."- invoice: cobra un servicio o suministro ya prestado. Incluye facturas, facturas simplificadas y los recibos de suministros (agua, luz, gas) que cobran un consumo con su importe.\n"
            ."- credit_note: factura rectificativa o abono que corrige una factura anterior.\n"
            ."- receipt: justificante de que un pago ya se hizo (transferencia, carta de pago, recibo bancario de algo ya facturado). No es la factura.\n"
            ."- budget: presupuesto, oferta, proforma u hoja de aceptación de presupuesto: propone un precio para un trabajo aún no hecho, aunque tenga número, importe y cliente.\n"
            ."- technical_report: parte de trabajo, detalle de visita, informe de revisión o de inspección, hoja de mantenimiento.\n"
            ."- report: informe económico, estado de cuenta, extracto, liquidación o listado de movimientos.\n"
            ."- certificate: certificado (de revisión, de idoneidad, de inspección...).\n"
            ."- contract: contrato, póliza, condiciones generales o particulares.\n"
            ."- other: cualquier otra cosa (avisos legales, folletos, tarjetas, documentos personales...).\n"
            ."Un documento con tabla de importes no es factura solo por tener importes: fíjate en el título, en quién cobra a quién y en si reclama un pago por algo ya prestado.\n"
            ."should_process_as_invoice es true solo si document_type es invoice o credit_note.\n"
            ."document_confidence mide tu seguridad sobre document_type y should_process_as_invoice.\n"
            .self::CONFIDENCE_RUBRIC."\n"
            ."document_reason: una o dos frases con las evidencias del propio documento que justifican el tipo.\n\n"
            ."2. EMISOR (issuer): quien emite el documento y cobra. issuer.tax_id es su CIF/NIF; si no aparece, null.\n\n"
            ."3. CLIENTE (customer): el destinatario, normalmente una comunidad de propietarios. Estos datos salen ÚNICAMENTE de lo impreso en el documento, nunca del asunto ni del cuerpo del correo, aunque mencionen una comunidad. customer.tax_id es el CIF/NIF del titular o cliente: en suministros suele aparecer como \"CIF titular\", \"NIF titular\", en un bloque \"DATOS TITULAR\" o en información adicional; búscalo también ahí. El NIF de un bloque \"Titular\" o \"Cliente\" es siempre del cliente, nunca del emisor, aunque sea el único NIF visible. customer.address es la dirección del cliente o del punto de suministro tal como aparece.\n\n"
            ."4. FACTURA (invoice): number tal cual aparece; date en formato AAAA-MM-DD (fecha de emisión); total_amount es el total a pagar como número, con impuestos; currency en código ISO (EUR); billing_period el periodo facturado si aparece. Rellena estos campos aunque el documento no sea una factura, si existen.\n\n"
            ."5. SERVICIO (service): raw es una descripción breve en minúsculas de lo que se cobra; semantic_category es la categoría que mejor encaja de la lista, u \"otro\" si ninguna encaja, o null si el documento no permite saberlo.\n\n"
            ."6. supply_identifiers: CUPS, número de contrato y referencia de cliente si aparecen.";
    }

    public static function understandingUserText(string $emailContext): string
    {
        return "Analiza el documento adjunto. Contexto del correo (solo como apoyo; los datos del cliente salen del documento):\n".mb_substr($emailContext, 0, 12000);
    }

    /** @param list<int> $ids @return array<string,mixed> */
    public static function choiceSchema(string $idField, array $ids): array
    {
        return ['type'=>'object','properties'=>[
            $idField => ['type'=>['integer','null'],'enum'=>array_merge($ids,[null])],
            'confidence' => ['type'=>'number'],
            'reason' => ['type'=>'string'],
        ],'required'=>[$idField,'confidence','reason'],'additionalProperties'=>false];
    }

    public static function communityInstructions(): string
    {
        return "Una administración de fincas ha recibido una factura y debe saber a cuál de sus comunidades de propietarios va dirigida. Recibes el documento, los datos que ya se extrajeron de él y una lista CERRADA de comunidades candidatas.\n"
            ."Elige la candidata que es el cliente o destinatario de la factura. Compara el nombre del cliente, la dirección del cliente o del suministro y el NIF con los nombres, direcciones y alias de cada candidata. Ten en cuenta sinónimos, abreviaturas, el orden distinto de las palabras y las variantes en castellano y valenciano.\n"
            ."Devuelve community_id con el id exacto de una candidata, o null si ninguna encaja o si dos o más encajan igual de bien. No propongas ninguna comunidad fuera de la lista.\n"
            .self::CONFIDENCE_RUBRIC."\n"
            ."reason: una o dos frases con la evidencia concreta del documento.";
    }

    public static function supplierInstructions(): string
    {
        return "Una administración de fincas ha recibido una factura y debe saber qué proveedor de su maestro la emite. Recibes el documento, los datos que ya se extrajeron de él, la comunidad a la que va dirigida y una lista CERRADA de proveedores candidatos.\n"
            ."Elige el proveedor que emite y cobra la factura. Compara el nombre y el CIF del emisor, logotipos, sellos, cabeceras y pies con el nombre comercial, la razón social, el CIF y los alias de cada candidato. Que un candidato ya trabaje con esta comunidad es un indicio, nunca una prueba: si el documento muestra otro emisor, no lo elijas.\n"
            ."Si el documento muestra un CIF del emisor y el de un candidato es distinto, ese candidato no es el emisor.\n"
            ."Devuelve supplier_id con el id exacto de un candidato, o null si ninguno encaja o si dos o más encajan igual de bien. No propongas ningún proveedor fuera de la lista.\n"
            .self::CONFIDENCE_RUBRIC."\n"
            ."reason: una o dos frases con la evidencia concreta del documento.";
    }

    /** @param array<string,mixed> $understanding @param list<array<string,mixed>> $candidates */
    public static function choiceUserText(string $label, array $understanding, array $candidates, ?string $communityName = null): string
    {
        $extracted = ['emisor'=>$understanding['issuer'] ?? null,'cliente'=>$understanding['customer'] ?? null,'servicio'=>$understanding['service'] ?? null];
        return "Datos extraídos del documento:\n".json_encode($extracted, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n\n"
            .($communityName !== null ? "Comunidad destinataria ya confirmada: $communityName\n\n" : '')
            ."$label candidatos (lista cerrada):\n".json_encode($candidates, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    }
}
