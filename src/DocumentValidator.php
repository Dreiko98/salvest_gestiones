<?php
declare(strict_types=1);

namespace Salvest;

final class DocumentValidator
{
    /** @param array<string,mixed> $attachment */
    public static function validate(array $attachment, int $maximumBytes): void
    {
        $payload=(string)($attachment['payload']??'');
        $size=strlen($payload);
        if($size===0)throw new \RuntimeException('El adjunto está vacío');
        if($size>$maximumBytes)throw new \RuntimeException("Adjunto demasiado grande ($size bytes; máximo $maximumBytes)");
        $mime=mb_strtolower((string)($attachment['mime_type']??''));
        $name=mb_strtolower((string)($attachment['original_filename']??''));
        if($mime==='application/pdf'||str_ends_with($name,'.pdf')){
            if(!str_starts_with($payload,'%PDF-'))throw new \RuntimeException('El adjunto declara PDF pero su contenido no tiene firma PDF');
            return;
        }
        if(str_starts_with($mime,'image/')||preg_match('/\.(jpe?g|png|tiff?|webp)$/i',$name)){
            if(@getimagesizefromstring($payload)===false)throw new \RuntimeException('La imagen adjunta está corrupta o su MIME no coincide');
            return;
        }
        throw new \RuntimeException('Tipo de documento no permitido');
    }
}
