<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Orchestrates Classifier's individual resolutions into one routing decision. OpenAI's
 * extraction is the input; every field that MySQL can resolve deterministically (community
 * by holder CIF/identifier, supplier by CIF, service by the supplier's configured type)
 * overrides whatever OpenAI proposed. A supplier recognized globally but not linked to the
 * resolved community is never silently forced through — it goes to review instead.
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

        $resolved=$this->classifier->resolveSupplier($invoice,$sender);
        $supplier=$resolved['supplier'];$supplierEvidence=$resolved['evidence'];
        $relation=null;$reason=null;

        if($community&&$supplier){
            $relation=$this->classifier->communitySupplierRelation((int)$community['id'],(int)$supplier['id']);
            if(!$relation){
                // A real, identifiable supplier — just not one this community has on file.
                // Forcing it through would risk archiving under the wrong community; a human
                // decides instead.
                $reason='Proveedor reconocido pero no asociado a esta comunidad.';
                $supplier=null;
            }
        }elseif($community&&!$supplier){
            $fallback=$this->classifier->resolveCommunitySupplier((int)$community['id'],$invoice,$sender);
            if($fallback){
                $supplier=$fallback;$relation=['category'=>$fallback['category'],'contract_reference'=>$fallback['contract_reference']];
                $supplierEvidence=['field'=>'proveedor','type'=>'fuzzy_within_community'];
            }
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
