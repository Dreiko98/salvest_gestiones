<?php
declare(strict_types=1);

namespace Salvest;

final class InvoiceRouter
{
    public function __construct(private Classifier $classifier){}

    /** @param array<string,mixed> $invoice
     * @return array{decision:array,supplier:?array,status:string,message_status:string,imap_destination:string,drive_upload:bool} */
    public function route(array $invoice,string $sender,string $context=''):array
    {
        $decision=$this->classifier->classify($invoice,$context);
        $supplier=$decision['community']?$this->classifier->resolveCommunitySupplier((int)$decision['community']['id'],$invoice,$sender):null;
        $status=$decision['community']&&$supplier?'classified':($decision['community']?'needs_review':'unclassified');
        return[
            'decision'=>$decision,'supplier'=>$supplier,'status'=>$status,
            'message_status'=>$status==='classified'?'completed':'needs_review',
            'imap_destination'=>$status==='unclassified'?'Facturas/Sin clasificar':($status==='needs_review'?'Facturas/Pendientes de revisión':''),
            'drive_upload'=>$status==='classified',
        ];
    }
}
