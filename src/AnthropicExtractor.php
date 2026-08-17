<?php
declare(strict_types=1);

namespace Salvest;

final class AnthropicExtractor
{
    public const VERSION='anthropic-messages-v1';
    public const FIELDS=[
        'proveedor','tipo_servicio','direccion','importe','fecha_factura','proveedor_cif',
        'nombre_comunidad','comunidad_cif','codigo_postal','cups','numero_contrato',
        'referencia_cliente','numero_factura','periodo_facturacion','moneda','codigo_comunidad',
    ];
    public int $inputTokens=0;
    public int $outputTokens=0;

    /** @param array<string,mixed> $config */
    public function __construct(private array$config){}

    /** @return array<string,mixed> */
    public function extract(string$path,string$mimeType,string$context):array
    {
        $bytes=file_get_contents($path);if($bytes===false)throw new \RuntimeException('No se pudo leer el documento');
        $encoded=base64_encode($bytes);
        $document=str_starts_with($mimeType,'image/')
            ?['type'=>'image','source'=>['type'=>'base64','media_type'=>$mimeType,'data'=>$encoded]]
            :['type'=>'document','source'=>['type'=>'base64','media_type'=>'application/pdf','data'=>$encoded]];
        $properties=[];
        foreach(self::FIELDS as$field)$properties[$field]=['type'=>$field==='importe'?['number','null']:['string','null']];
        $schema=['type'=>'object','properties'=>$properties,'required'=>self::FIELDS,'additionalProperties'=>false];
        $payload=[
            'model'=>$this->config['model'],'max_tokens'=>(int)($this->config['max_tokens']??2048),
            'system'=>'Extrae datos de facturas sin inventar. Usa null cuando un valor no aparezca. tipo_servicio debe ser una categoría breve en minúsculas; fecha_factura usa AAAA-MM-DD; importe es el total numérico. No propongas IDs internos.',
            'messages'=>[['role'=>'user','content'=>[$document,['type'=>'text','text'=>'Extrae los campos requeridos. Contexto del correo: '.mb_substr($context,0,12000)]]]],
            'output_config'=>['format'=>['type'=>'json_schema','schema'=>$schema]],
        ];
        $curl=curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['x-api-key: '.$this->config['api_key'],'anthropic-version: 2023-06-01','content-type: application/json'],
            CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),CURLOPT_TIMEOUT=>(int)($this->config['timeout_seconds']??120)]);
        $response=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        if($response===false)throw new \RuntimeException('Error de red Anthropic: '.curl_error($curl));
        $decoded=json_decode($response,true,flags:JSON_THROW_ON_ERROR);
        if($status<200||$status>=300)throw new \RuntimeException('Anthropic respondió HTTP '.$status.': '.($decoded['error']['message']??'error desconocido'));
        $this->inputTokens+=(int)($decoded['usage']['input_tokens']??0);$this->outputTokens+=(int)($decoded['usage']['output_tokens']??0);
        $text=null;foreach(($decoded['content']??[])as$content)if(($content['type']??'')==='text'){$text=$content['text']??null;break;}
        if(!is_string($text))throw new \RuntimeException('Anthropic no devolvió una extracción estructurada');
        $invoice=json_decode($text,true,flags:JSON_THROW_ON_ERROR);
        foreach(self::FIELDS as$field)if(!array_key_exists($field,$invoice))throw new \RuntimeException('Anthropic omitió el campo '.$field);
        $invoice['proveedor']=$invoice['proveedor']?:'desconocido';
        $invoice['tipo_servicio']=Text::normalize((string)($invoice['tipo_servicio']?:'desconocido'));
        $invoice['moneda']=$invoice['moneda']?:'EUR';
        return$invoice;
    }
}
