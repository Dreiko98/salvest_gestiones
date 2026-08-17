<?php
declare(strict_types=1);

namespace Salvest;

final class Worker
{
    private MimeParser $parser;
    private Classifier $classifier;
    private Archiver $archiver;

    /** @param array<string,mixed> $config */
    public function __construct(private Database $db, private Crypto $crypto,
        private OpenAIExtractor $extractor, private array $config)
    {
        $this->parser = new MimeParser();
        $this->classifier = new Classifier($db, (float)$config['processing']['classification_threshold']);
        $this->archiver = new Archiver((string)$config['processing']['storage_root']);
    }

    /** @return array<string,int> */
    public function run(bool $dryRun = false, ?int $limit = null, ?string $mailboxEmail = null): array
    {
        $locked = $this->db->one("SELECT GET_LOCK('salvest-invoice-worker',0) acquired");
        if (!(int)($locked['acquired'] ?? 0)) throw new \RuntimeException('Ya hay otro worker ejecutándose');
        $runUuid = $this->uuid();
        $mailboxes = $mailboxEmail
            ? $this->db->all('SELECT * FROM mailboxes WHERE active=1 AND email=? ORDER BY id',[$mailboxEmail])
            : $this->db->all('SELECT * FROM mailboxes WHERE active=1 ORDER BY id');
        if($mailboxEmail!==null&&!$mailboxes)throw new \RuntimeException('No existe un buzón activo con ese correo');
        $this->db->execute("INSERT INTO processing_runs(run_uuid,trigger_type,started_at,status,mailboxes_count) VALUES (?,? ,NOW(),'running',?)",
            [$runUuid,$dryRun?'dry_run':'cron',count($mailboxes)]);
        $runId = (int)$this->db->pdo()->lastInsertId();
        $counts = ['messages'=>0,'documents'=>0,'classified'=>0,'unclassified'=>0,'duplicate'=>0,'errors'=>0];
        try {
            foreach ($mailboxes as $mailbox) {
                try { $this->processMailbox($mailbox, $dryRun, $limit, $counts); }
                catch (\Throwable $error) {
                    $counts['errors']++;
                    $this->db->execute('UPDATE mailboxes SET last_connection_at=NOW(),last_connection_ok=0,last_error=? WHERE id=?',
                        [mb_substr($error->getMessage(),0,2000),$mailbox['id']]);
                    error_log('mailbox_id='.$mailbox['id'].' status=failed error='.$error->getMessage());
                }
            }
            $this->db->execute("UPDATE processing_runs SET finished_at=NOW(),status=?,messages_reviewed=?,documents_detected=?,classified_count=?,unclassified_count=?,duplicate_count=?,error_count=?,openai_input_tokens=?,openai_output_tokens=? WHERE id=?",
                [$counts['errors']?'partial':'completed',$counts['messages'],$counts['documents'],$counts['classified'],$counts['unclassified'],$counts['duplicate'],$counts['errors'],$this->extractor->inputTokens,$this->extractor->outputTokens,$runId]);
            return $counts;
        } catch (\Throwable $error) {
            $this->db->execute("UPDATE processing_runs SET finished_at=NOW(),status='error',error_message=? WHERE id=?", [mb_substr($error->getMessage(),0,2000),$runId]);
            throw $error;
        } finally {
            $this->db->one("SELECT RELEASE_LOCK('salvest-invoice-worker') released");
        }
    }

    /** @param array<string,mixed> $mailbox @param array<string,int> $counts */
    private function processMailbox(array $mailbox, bool $dryRun, ?int $limit, array &$counts): void
    {
        $client = new ImapClient((string)$mailbox['imap_host'],(int)$mailbox['imap_port'],(string)$mailbox['username'],
            $this->crypto->decrypt((string)$mailbox['encrypted_password']),(string)$mailbox['input_folder'],
            (int)$this->config['imap']['timeout_seconds']);
        try {
            $client->connect();
            $this->db->execute('UPDATE mailboxes SET last_connection_at=NOW(),last_connection_ok=1,last_error=NULL WHERE id=?',[$mailbox['id']]);
            $uids = $client->listUids(); $examined = 0;
            foreach ($uids as $uid) {
                if ($limit !== null && $examined >= $limit) break;
                $existing = $this->db->one('SELECT status FROM processed_messages WHERE mailbox_id=? AND uidvalidity=? AND message_uid=?',
                    [$mailbox['id'],$client->uidValidity(),$uid]);
                if ($existing && in_array($existing['status'],['completed','ignored','needs_review','error'],true)) continue;
                $message = $this->parser->parse($client->fetch($uid)); $examined++; $counts['messages']++;
                if (!$message['attachments']) {
                    if (!$dryRun) $this->saveMessage($mailbox,$client,$uid,$message,'ignored',0,null);
                    continue;
                }
                if ($dryRun) { $counts['documents'] += count($message['attachments']); continue; }
                $outcomes = [];
                try {
                    foreach ($message['attachments'] as $attachment) {
                        DocumentValidator::validate($attachment,(int)$this->config['processing']['max_attachment_bytes']);
                        $counts['documents']++;
                        try { $outcomes[] = $this->processAttachment($mailbox,$client,$uid,$message,$attachment,$counts); }
                        catch (\Throwable $attachmentError) {
                            $this->insertAttachment($mailbox,$client,$uid,$attachment,'error',['error_message'=>$attachmentError->getMessage()]);
                            throw $attachmentError;
                        }
                    }
                    $communityIds = array_values(array_unique(array_filter(array_column($outcomes,'community_id'))));
                    $allClassified = !array_filter($outcomes,static fn(array $item): bool => !in_array($item['status'],['classified','duplicate'],true));
                    if ($allClassified && count($communityIds) === 1) {
                        $community = $this->db->one('SELECT * FROM communities WHERE id=?',[$communityIds[0]]);
                        $destination = 'Facturas/'.($community['imap_folder_name'] ?: Text::slug((string)$community['official_name']));
                        try {
                            $client->markSeen($uid); $client->move($uid,$destination);
                            $this->saveMessage($mailbox,$client,$uid,$message,'completed',count($outcomes),$destination,'moved');
                        } catch (\Throwable $imapError) {
                            $this->saveMessage($mailbox,$client,$uid,$message,'completed',count($outcomes),$destination,'failed',$imapError->getMessage());
                        }
                    } else {
                        $allUnknown = !array_filter($outcomes,static fn(array $item): bool => $item['status'] !== 'unclassified');
                        $destination = $allUnknown ? 'Facturas/Sin clasificar' : 'Facturas/Pendientes de revisión';
                        try {
                            $client->move($uid,$destination);
                            $this->saveMessage($mailbox,$client,$uid,$message,'needs_review',count($outcomes),$destination,'moved');
                        } catch (\Throwable $imapError) {
                            $this->saveMessage($mailbox,$client,$uid,$message,'needs_review',count($outcomes),$destination,'failed',$imapError->getMessage());
                        }
                    }
                } catch (\Throwable $error) {
                    try { $client->move($uid,'Facturas/Errores'); } catch (\Throwable $moveError) {
                        $error = new \RuntimeException($error->getMessage().'; movimiento IMAP: '.$moveError->getMessage(),0,$error);
                    }
                    $this->saveMessage($mailbox,$client,$uid,$message,'error',count($message['attachments']),'Facturas/Errores','failed',$error->getMessage());
                    $counts['errors']++;
                }
            }
        } finally { $client->close(); }
    }

    /** @param array<string,mixed> $mailbox @param array<string,mixed> $message @param array<string,mixed> $attachment @param array<string,int> $counts @return array{status:string,community_id:?int} */
    private function processAttachment(array $mailbox, ImapClient $client, string $uid, array $message, array $attachment, array &$counts): array
    {
        $prior = $this->db->one("SELECT * FROM processed_attachments WHERE attachment_sha256=? AND status IN ('classified','unclassified','needs_review','duplicate') ORDER BY id LIMIT 1",[$attachment['sha256']]);
        if ($prior) {
            $this->insertAttachment($mailbox,$client,$uid,$attachment,'duplicate',$prior);
            $counts['duplicate']++;
            return ['status'=>'duplicate','community_id'=>$prior['community_id'] ? (int)$prior['community_id'] : null];
        }
        $incoming = rtrim((string)$this->config['processing']['incoming_root'],'/').'/'.Text::slug((string)$mailbox['email']);
        if (!is_dir($incoming) && !mkdir($incoming,0770,true) && !is_dir($incoming)) throw new \RuntimeException('No se pudo crear incoming');
        $path = $incoming.'/'.$uid.'-'.bin2hex(random_bytes(4)).'-'.$attachment['safe_filename'];
        if (file_put_contents($path,$attachment['payload'],LOCK_EX) === false) throw new \RuntimeException('No se pudo guardar el adjunto temporal');
        $this->insertAttachment($mailbox,$client,$uid,$attachment,'downloaded',[]);
        $this->insertAttachment($mailbox,$client,$uid,$attachment,'processing',[]);
        $context = "Remitente: {$message['sender']}\nAsunto: {$message['subject']}\nAdjunto: {$attachment['original_filename']}\n{$message['body']}";
        $invoice = $this->extractor->extract($path,(string)$attachment['mime_type'],$context);
        $decision = $this->classifier->classify($invoice,$context);
        $supplier = $this->classifier->resolveSupplier($invoice,(string)$message['sender']);
        $supplierOk = $supplier && $this->classifier->supplierAcceptsService($supplier,(string)$invoice['tipo_servicio']);
        $status = $decision['community'] && $supplierOk ? 'classified' : ($decision['community'] ? 'needs_review' : 'unclassified');
        $target = $this->archiver->archive($path,(string)$attachment['original_filename'],$invoice,$decision['community'],$status);
        $data = array_merge($invoice,['community_id'=>$decision['community']['id'] ?? null,'confidence'=>$decision['confidence'],
            'output_path'=>$target,'final_filename'=>basename($target),'extraction_json'=>json_encode($invoice,JSON_UNESCAPED_UNICODE),
            'decision_json'=>json_encode($decision,JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR)]);
        $this->insertAttachment($mailbox,$client,$uid,$attachment,$status,$data);
        $counts[$status === 'classified' ? 'classified' : 'unclassified']++;
        return ['status'=>$status,'community_id'=>$data['community_id'] ? (int)$data['community_id'] : null];
    }

    private function insertAttachment(array $mailbox, ImapClient $client, string $uid, array $attachment, string $status, array $data): void
    {
        $this->db->execute("INSERT INTO processed_attachments(mailbox_id,uidvalidity,message_uid,original_filename,attachment_sha256,mime_type,size_bytes,
            provider,provider_cif,service_type,supply_address,amount,currency,invoice_date,invoice_number,community_id,confidence,final_filename,output_path,status,
            extraction_json,decision_json,error_message,extractor_version,processed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE provider=VALUES(provider),provider_cif=VALUES(provider_cif),service_type=VALUES(service_type),
            supply_address=VALUES(supply_address),amount=VALUES(amount),currency=VALUES(currency),invoice_date=VALUES(invoice_date),
            invoice_number=VALUES(invoice_number),community_id=VALUES(community_id),confidence=VALUES(confidence),final_filename=VALUES(final_filename),
            output_path=VALUES(output_path),status=VALUES(status),extraction_json=VALUES(extraction_json),decision_json=VALUES(decision_json),
            error_message=VALUES(error_message),extractor_version=VALUES(extractor_version),processed_at=NOW()", [
            $mailbox['id'],$client->uidValidity(),$uid,$attachment['original_filename'],$attachment['sha256'],$attachment['mime_type'],$attachment['size'],
            $data['proveedor']??$data['provider']??null,$data['proveedor_cif']??$data['provider_cif']??null,$data['tipo_servicio']??$data['service_type']??null,
            $data['direccion']??$data['supply_address']??null,$data['importe']??$data['amount']??null,$data['moneda']??$data['currency']??null,
            ($data['fecha_factura']??$data['invoice_date']??null) ?: null,($data['numero_factura']??$data['invoice_number']??null) ?: null,$data['community_id']??null,
            $data['confidence']??null,$data['final_filename']??null,$data['output_path']??null,$status,$data['extraction_json']??null,$data['decision_json']??null,
            $data['error_message']??null,OpenAIExtractor::VERSION,
        ]);
    }

    private function saveMessage(array $mailbox, ImapClient $client, string $uid, array $message, string $status, int $count,
        ?string $destination, string $moveStatus='not_required', ?string $error=null): void
    {
        $this->db->execute("INSERT INTO processed_messages(mailbox_id,uidvalidity,message_uid,message_id_header,sender,subject,received_at,status,document_count,
            imap_destination,imap_move_status,error_message,processed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE status=VALUES(status),document_count=VALUES(document_count),imap_destination=VALUES(imap_destination),
            imap_move_status=VALUES(imap_move_status),error_message=VALUES(error_message),processed_at=NOW()", [
            $mailbox['id'],$client->uidValidity(),$uid,$message['message_id'],$message['sender'],$message['subject'],$message['date'],$status,$count,$destination,$moveStatus,$error,
        ]);
    }

    private function uuid(): string
    {
        $data = random_bytes(16); $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($data),4));
    }
}
