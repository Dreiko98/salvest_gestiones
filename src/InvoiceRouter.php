<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Orchestrates Classifier's individual resolutions into one routing decision. OpenAI's raw
 * extraction is never a final decision — MySQL resolves community, supplier and service
 * deterministically wherever it can. A supplier is only ever a confirmed row from `suppliers`,
 * linked to the resolved community via `community_suppliers`; the text OpenAI proposed for
 * "proveedor" is kept separately as raw_supplier_name and never promoted to a real supplier
 * on its own. When the community is known but no supplier matches deterministically, a
 * caller-supplied restricted resolver gets one more chance — showing the model the same PDF
 * again together with the closed list of suppliers this community actually has — before
 * giving up and sending the document to manual review.
 */
final class InvoiceRouter
{
    public function __construct(private Classifier $classifier){}

    /**
     * @param array<string,mixed> $invoice
     * @param (callable(list<array{id:int,official_name:string}>,array<string,mixed>):?int)|null $restrictedResolver
     *   Invoked only when the community is known, resolveSupplierInCommunity() found nothing
     *   (not ambiguous), and that community has at least one supplier on file. Must return one
     *   of the candidate ids or null; any other value is treated as null. Worker.php supplies a
     *   closure that makes the real second OpenAI call; tests can supply a plain function.
     * @param (callable(string,string,array<string,mixed>):void)|null $trace Observer for
     *   /Revisar's technical trace, forwarded verbatim into Classifier::classify()/
     *   resolveSupplierInCommunity() — see their docblocks. Never affects which branch this
     *   method takes; null (the normal case) costs nothing.
     * @return array{decision:array,supplier:?array,raw_supplier_name:string,service:string,status:string,message_status:string,imap_destination:string,drive_upload:bool,reason:?string,evidence:array,supplier_ambiguous:bool}
     */
    public function route(array $invoice,string $sender,string $context='',?callable $restrictedResolver=null,?callable $trace=null):array
    {
        $decision=$this->classifier->classify($invoice,$context,$trace);
        $community=$decision['community'];
        $rawSupplierName=trim((string)($invoice['proveedor']??''));

        $supplier=null;$supplierEvidence=null;$relation=null;$reason=null;$supplierAmbiguous=false;

        if($community){
            $resolved=$this->classifier->resolveSupplierInCommunity((int)$community['id'],$invoice,$sender,$trace);
            $supplier=$resolved['supplier'];$supplierEvidence=$resolved['evidence'];
            if($supplier){
                $relation=['category'=>$supplier['category'],'contract_reference'=>$supplier['contract_reference']];
            }elseif($resolved['ambiguous']){
                $supplierAmbiguous=true;
                $reason='Varios proveedores de esta comunidad coinciden con el nombre extraído; revisa manualmente.';
            }else{
                if($restrictedResolver!==null){
                    $candidates=$this->classifier->suppliersForCommunity((int)$community['id']);
                    if($candidates){
                        $chosenId=$restrictedResolver($candidates,$community);
                        if($chosenId!==null){
                            $confirmed=$this->classifier->supplierInCommunity((int)$community['id'],(int)$chosenId);
                            if($confirmed){
                                $supplier=$confirmed;$relation=['category'=>$confirmed['category'],'contract_reference'=>$confirmed['contract_reference']];
                                $supplierEvidence=['field'=>'proveedor','type'=>'restricted_openai_retry'];
                            }
                            // A chosen id outside the candidate list (or not re-confirmed against
                            // the community) is silently discarded — never trusted as a shortcut.
                        }
                    }
                }
                if(!$supplier){
                    // Not found among this community's own suppliers, and the restricted retry
                    // (if any) didn't resolve it either. Check globally only to give a more
                    // specific reason — never to force a supplier this community doesn't have.
                    $global=$this->classifier->resolveSupplier($invoice,$sender);
                    if($global['supplier'])$reason='Proveedor reconocido pero no asociado a esta comunidad.';
                }
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
            'decision'=>$decision,'supplier'=>$supplier,'raw_supplier_name'=>$rawSupplierName,'service'=>$serviceResolution['service'],'reason'=>$reason,
            'evidence'=>['community'=>$decision['evidence'],'supplier'=>$supplierEvidence,'service'=>$serviceResolution['evidence']],
            'status'=>$status,
            'message_status'=>$status==='classified'?'completed':'needs_review',
            'imap_destination'=>$status==='unclassified'?'Facturas/Sin clasificar':($status==='needs_review'?'Facturas/Pendientes de revisión':''),
            'drive_upload'=>$status==='classified',
            'supplier_ambiguous'=>$supplierAmbiguous,
        ];
    }
}
