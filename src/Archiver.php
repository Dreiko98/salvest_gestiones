<?php
declare(strict_types=1);

namespace Salvest;

final class Archiver
{
    public function __construct(private string $root) {}

    /** @param array<string,mixed> $invoice */
    public function archive(string $source, string $originalName, array $invoice, ?array $community, string $status): string
    {
        if ($status === 'classified' && $community) {
            $date = (string)($invoice['fecha_factura'] ?? '');
            if (!preg_match('/^(\d{4})-(\d{2})/', $date, $match)) throw new \RuntimeException('Fecha de factura no válida');
            $folder = $this->root . '/comunidades/' . Text::slug((string)$community['official_name']) . '/' . $match[1] . '/' . $match[2];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?: 'pdf');
            // El importe se añade al final, solo cuando la extracción trajo uno — nunca se
            // inventa un "0.00" para una factura sin importe reconocido, se omite el trozo entero.
            $amount = $invoice['importe'] ?? null;
            $amountSuffix = is_numeric($amount) ? '_' . number_format((float)$amount, 2, '.', '') : '';
            $filename = sprintf('%s-%s_%s_%s%s.%s', $match[1], $match[2], Text::slug((string)$invoice['tipo_servicio']), Text::slug((string)$invoice['proveedor']), $amountSuffix, $extension);
        } else {
            $folder = $this->root . '/unclassified';
            $filename = Text::safeFilename($originalName, 1);
        }
        if (!is_dir($folder) && !mkdir($folder, 0770, true) && !is_dir($folder)) throw new \RuntimeException("No se pudo crear $folder");
        $target = $folder . '/' . $filename; $counter = 2;
        while (file_exists($target)) {
            $target = $folder . '/' . pathinfo($filename, PATHINFO_FILENAME) . sprintf('_%02d', $counter++) . '.' . pathinfo($filename, PATHINFO_EXTENSION);
        }
        if (!rename($source, $target)) throw new \RuntimeException("No se pudo archivar $originalName");
        return $target;
    }
}
