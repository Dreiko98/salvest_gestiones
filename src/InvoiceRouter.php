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

        $supplier=null;$supplierEvidence=null;$relation=null;$reason=null;$supplierAmbiguous=false;$globalSupplierNeedsService=false;

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
                    // Fase 6: not found among this community's own suppliers, and the restricted
                    // retry (if any) didn't resolve it either — the global, community-unscoped
                    // lookup now gets to actually decide, not just annotate a reason. It's a pure
                    // MySQL lookup (CIF/name/official_name/alias/containment/token/fuzzy, the
                    // exact same fail-safe tiers resolveSupplierInCommunity() uses), never OpenAI,
                    // so this costs zero extra API calls. Wrapped so every tier it tries lands in
                    // the trace under a single 'supplier_global_fallback' step (with the real tier
                    // nested in details['tier']) — never renaming resolveSupplierInCommunity()'s
                    // own tier names above, so nothing here can be confused with an in-community
                    // match, and no existing trace-based test depending on those names changes.
                    $globalTrace=$trace?static function(string $tier,string $outcome,array $details)use($trace):void{
                        $trace('supplier_global_fallback',$outcome,['tier'=>$tier]+$details);
                    }:null;
                    $global=$this->classifier->resolveSupplier($invoice,$sender,$globalTrace);
                    if($global['ambiguous']){
                        // Fails exactly like an in-community ambiguity: no candidate is ever
                        // picked, community_suppliers is never touched (this whole method never
                        // writes to it, in any branch), and the reason makes clear where the
                        // ambiguity was found.
                        $supplierAmbiguous=true;
                        $reason='Proveedor reconocido de forma ambigua a nivel global (sin comunidad); revisa manualmente.';
                    }elseif($global['supplier']){
                        // Unambiguous global match: accepted as the resolved supplier. No
                        // community_suppliers row exists yet for this pair — $relation stays null
                        // on purpose, which is fine: resolveService() already checks the
                        // supplier's own main_service_type_id before ever looking at $relation.
                        $supplier=$global['supplier'];
                        $supplierEvidence=$global['evidence']+['source'=>'global'];
                        // Guard specific to the global fallback (never touches the classic
                        // in-community path, which keeps whatever resolveService() already did —
                        // including its existing 'desconocido' fallback, unchanged): with no
                        // main_service_type_id AND no relation (there isn't one) AND no OpenAI
                        // service hint either, there is genuinely no safe way to pick a service.
                        // The supplier stays recognised (so /Revisar shows it was found) but the
                        // invoice still goes to needs_review — never a silently invented category,
                        // never "Otros".
                        $hasConfiguredService=!empty($supplier['service_type_name']);
                        $hasOpenAiServiceHint=trim((string)($invoice['tipo_servicio']??''))!=='';
                        if(!$hasConfiguredService&&!$hasOpenAiServiceHint){
                            $globalSupplierNeedsService=true;
                            $reason='Proveedor reconocido globalmente, pero no se pudo determinar un servicio seguro.';
                        }
                    }
                    // Else: genuinely unresolved (supplier=null, ambiguous=false) — needs_review,
                    // exactly as before, no reason invented, nothing created.
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

        $status=$community&&$supplier&&!$globalSupplierNeedsService?'classified':($community?'needs_review':'unclassified');
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
