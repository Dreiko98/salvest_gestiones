<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Orchestrates Classifier's individual resolutions into one routing decision. OpenAI's
 * extraction is the input; every field that MySQL can resolve deterministically (community
 * by holder CIF/identifier, supplier from that community's own master data, service by the
 * supplier's configured type) overrides whatever OpenAI proposed. Once a community is known,
 * supplier resolution happens exclusively among that community's own suppliers — CIF is an
 * excellent signal when present, never a requirement, since most suppliers have none on file.
 */
final class InvoiceRouter
{
    public function __construct(private Classifier $classifier){}

    /** @param array<string,mixed> $invoice
     * @return array{decision:array,supplier:?array,service:string,status:string,message_status:string,imap_destination:string,drive_upload:bool,reason:?string,evidence:array} */
    public function route(array $invoice,string $sender,string $context=''):array
    {
        $decision=$this->classifier->classify($invoice,$context);
        $community=$decision['community'];

        $supplier=null;$supplierEvidence=null;$relation=null;$reason=null;

        if($community){
            $resolved=$this->classifier->resolveSupplierInCommunity((int)$community['id'],$invoice,$sender);
            $supplier=$resolved['supplier'];$supplierEvidence=$resolved['evidence'];
            if($supplier){
                $relation=['category'=>$supplier['category'],'contract_reference'=>$supplier['contract_reference']];
            }elseif($resolved['ambiguous']){
                $reason='Varios proveedores de esta comunidad coinciden con el nombre extraído; revisa manualmente.';
            }else{
                // Not found among this community's own suppliers. Check globally only to give
                // a more specific reason — never to force a supplier this community doesn't have.
                $global=$this->classifier->resolveSupplier($invoice,$sender);
                if($global['supplier'])$reason='Proveedor reconocido pero no asociado a esta comunidad.';
            }
        }else{
            $global=$this->classifier->resolveSupplier($invoice,$sender);
            $supplier=$global['supplier'];$supplierEvidence=$global['evidence'];
        }

        $openAiService=Text::normalize((string)($invoice['tipo_servicio']??''));
        $serviceResolution=$supplier
            ?$this->classifier->resolveService($supplier,$relation,$openAiService)
            :['service'=>$openAiService!==''?$openAiService:'desconocido','evidence'=>['field'=>'tipo_servicio','type'=>'openai_suggestion']];

        $status=$community&&$supplier?'classified':($community?'needs_review':'unclassified');
        return[
            'decision'=>$decision,'supplier'=>$supplier,'service'=>$serviceResolution['service'],'reason'=>$reason,
            'evidence'=>['community'=>$decision['evidence'],'supplier'=>$supplierEvidence,'service'=>$serviceResolution['evidence']],
            'status'=>$status,
            'message_status'=>$status==='classified'?'completed':'needs_review',
            'imap_destination'=>$status==='unclassified'?'Facturas/Sin clasificar':($status==='needs_review'?'Facturas/Pendientes de revisión':''),
            'drive_upload'=>$status==='classified',
        ];
    }
}
