<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Orquestador: lee los buzones, decide qué se procesa (baseline, deduplicación, PDF-only), entrega
 * cada PDF a InvoiceProcessor y ejecuta los efectos externos con su resultado: copia local, Drive,
 * fila en processed_attachments y movimiento IMAP del correo. Las reglas de negocio (qué es una
 * factura, a qué comunidad y proveedor pertenece, qué estado recibe) viven en InvoiceProcessor y
 * ClassificationPolicy, no aquí.
 */
final class Worker
{
    private MimeParser $parser;
    private Archiver $archiver;
    private int $inputTokens = 0;
    private int $outputTokens = 0;

    /** @param array<string,mixed> $config */
    public function __construct(private Database $db, private Crypto $crypto, private InvoiceProcessor $processor,
        private array $config, private ?DriveInvoiceArchiver $driveArchiver = null)
    {
        $this->parser = new MimeParser();
        $this->archiver = new Archiver((string)$config['processing']['storage_root']);
    }

    /** Único sitio que ensambla el pipeline para todos los puntos de entrada (cron, CLI, botón del
     * panel): Claude como IA principal y OpenAI como respaldo automático llamada a llamada. */
    public static function create(Database $db, array $config): self
    {
        $drive = null; $folders = null;
        if ((bool)($config['google_drive']['enabled'] ?? false)) {
            $tokens = new GoogleUserOAuthProvider((string)$config['google_drive']['oauth_client_file'], (string)$config['google_drive']['oauth_token_file']);
            $client = new GoogleDriveClient($tokens);
            $drive = new DriveInvoiceArchiver($client, (string)$config['google_drive']['root_folder_id']);
            $directory = new DriveCommunityFolders($client, (string)$config['google_drive']['root_folder_id'], $db);
            $folders = static fn(): array => $directory->names();
        }
        $ai = new DocumentIntelligence(new FallbackAiClient(new ClaudeClient($config['anthropic']), new OpenAIClient($config['openai'])));
        return new self($db, new Crypto((string)$config['app']['encryption_key']), self::processor($db, $ai, $folders), $config, $drive);
    }

    /** @param (callable():array<string,string>)|null $folderNames */
    public static function processor(Database $db, DocumentIntelligence $ai, ?callable $folderNames = null, bool $autoLink = true): InvoiceProcessor
    {
        return new InvoiceProcessor($ai,
            new CommunityResolver($db, new CommunityCandidateRetriever($db), $folderNames),
            new SupplierResolver($db, new SupplierCandidateRetriever($db)),
            new ServiceResolver(), new ClassificationPolicy(),
            $autoLink ? new CommunitySupplierAutoLinker($db) : null);
    }

    /**
     * @param string|null $triggerType overrides the recorded trigger ('cron' or 'dry_run' are inferred when omitted,
     *   pass 'manual' from the dashboard button so cron and manual runs stay distinguishable in processing_runs)
     * @return array<string,int>
     */
    public function run(bool $dryRun = false, ?int $limit = null, ?string $mailboxEmail = null, ?string $triggerType = null, ?int $triggeredByUserId = null): array
    {
        $locked = $this->db->one("SELECT GET_LOCK('salvest-invoice-worker',0) acquired");
        if (!(int)($locked['acquired'] ?? 0)) throw new WorkerBusyException('Ya hay otro worker ejecutándose');
        $runUuid = $this->uuid();
        $mailboxes = $mailboxEmail
            ? $this->db->all('SELECT * FROM mailboxes WHERE active=1 AND email=? ORDER BY id',[$mailboxEmail])
            : $this->db->all('SELECT * FROM mailboxes WHERE active=1 ORDER BY id');
        if($mailboxEmail!==null&&!$mailboxes)throw new \RuntimeException('No existe un buzón activo con ese correo');
        $type = $triggerType ?? ($dryRun ? 'dry_run' : 'cron');
        $this->db->execute("INSERT INTO processing_runs(run_uuid,trigger_type,triggered_by_user_id,started_at,status,mailboxes_count) VALUES (?,?,?,NOW(),'running',?)",
            [$runUuid,$type,$triggeredByUserId,count($mailboxes)]);
        $runId = (int)$this->db->pdo()->lastInsertId();
        $counts = ['messages'=>0,'documents'=>0,'classified'=>0,'unclassified'=>0,'needs_review'=>0,'duplicate'=>0,'not_invoice'=>0,'errors'=>0];
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
            $this->db->execute("UPDATE processing_runs SET finished_at=NOW(),status=?,messages_reviewed=?,documents_detected=?,classified_count=?,unclassified_count=?,needs_review_count=?,duplicate_count=?,not_invoice_count=?,error_count=?,openai_input_tokens=?,openai_output_tokens=? WHERE id=?",
                [$counts['errors']?'partial':'completed',$counts['messages'],$counts['documents'],$counts['classified'],$counts['unclassified'],$counts['needs_review'],$counts['duplicate'],$counts['not_invoice'],$counts['errors'],$this->inputTokens,$this->outputTokens,$runId]);
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
            $inboxUids = $client->listUids();
            // Fase 24: antes de leer correo nuevo, volver a intentar mover los que se quedaron en la
            // bandeja de entrada porque su movimiento falló en una pasada anterior (ver
            // ImapMoveRetry). Nunca tumba el buzón: si algo falla aquí, se sigue con lo nuevo.
            if (!$dryRun) {
                try { (new ImapMoveRetry($this->db))->retry((int)$mailbox['id'], $client, $inboxUids); }
                catch (\Throwable $retryError) { error_log('mailbox_id='.$mailbox['id'].' status=move_retry_error error='.$retryError->getMessage()); }
            }
            $uids = $this->applyBaseline($mailbox, $client, $inboxUids); $examined = 0;
            foreach ($uids as $uid) {
                if ($limit !== null && $examined >= $limit) break;
                $existing = $this->db->one('SELECT status FROM processed_messages WHERE mailbox_id=? AND uidvalidity=? AND message_uid=?',
                    [$mailbox['id'],$client->uidValidity(),$uid]);
                if ($existing && in_array($existing['status'],['completed','ignored','needs_review','error'],true)) continue;
                $message = $this->parser->parse($client->fetch($uid)); $examined++; $counts['messages']++;
                // Recognises "Esto no es una factura" across an IMAP move — the UID this message
                // has right now was never seen before (that's exactly why the check above didn't
                // already skip it), but its Message-ID is stable. Checked before touching OpenAI,
                // before creating any row, before any folder move: if matched, this cycle treats
                // the message as if it were never fetched — no saveMessage(), no processAttachment(),
                // nothing. It simply stays wherever it is (normally INBOX) for good.
                if ($message['message_id'] !== '' && $this->isDismissedNotInvoice((int)$mailbox['id'], $message['message_id'])) continue;
                if (!$message['attachments']) {
                    if (!$dryRun) $this->saveMessage($mailbox,$client,$uid,$message,'ignored',0,null);
                    continue;
                }
                if ($dryRun) { $counts['documents'] += count($message['attachments']); continue; }
                $outcomes = [];
                try {
                    foreach ($message['attachments'] as $attachment) {
                        try {
                            DocumentValidator::validate($attachment,(int)$this->config['processing']['max_attachment_bytes']);
                        } catch (NotPdfException) {
                            // Fase 4 (PDF-only): not a real PDF, however it was labeled — silently
                            // excluded from processing. Never counted, never reaches OpenAI, never
                            // becomes a processed_attachments row, never fails the whole email.
                            // MimeParser already keeps most of these out of $attachments entirely;
                            // this is the defense-in-depth backstop for whatever still gets here.
                            continue;
                        } catch (EncryptedPdfException) {
                            // Fase 14: a real invoice, but password-protected — same treatment as
                            // NotPdfException (never counted, never reaches the extractor, never a
                            // processed_attachments row, never fails the whole email), since there
                            // is nothing the pipeline can do with it automatically. The one line a
                            // developer needs to trace a specific "vanished" invoice back to this
                            // stays in error_log() only — never surfaced to the non-technical UI.
                            error_log('mailbox_id='.$mailbox['id'].' uid='.$uid.' status=skipped reason=encrypted_pdf filename='.($attachment['original_filename']??'?'));
                            continue;
                        }
                        $counts['documents']++;
                        // Content already confirmed real PDF above — the declared MIME (possibly
                        // wrong, e.g. "image/jpeg" on a mislabeled real PDF) must never reach
                        // the AI client, which always sends the attachment as a PDF document.
                        $attachment['mime_type'] = 'application/pdf';
                        try { $outcomes[] = $this->processAttachment($mailbox,$client,$uid,$message,$attachment,$counts); }
                        catch (\Throwable $attachmentError) {
                            $this->insertAttachment($mailbox,$client,$uid,$attachment,'error',['error_message'=>$attachmentError->getMessage()]);
                            throw $attachmentError;
                        }
                    }
                    if (!$outcomes) {
                        // Every attachment this email had turned out not to be a real PDF (or
                        // there simply weren't any left after MimeParser's own filtering) — same
                        // outcome as an email with zero attachments: 'ignored', no processed_
                        // attachments row, no IMAP move, the email stays in INBOX untouched.
                        error_log('mailbox_id='.$mailbox['id'].' uid='.$uid.' status=ignored reason=no_processable_pdf');
                        $this->saveMessage($mailbox,$client,$uid,$message,'ignored',0,null);
                        continue;
                    }
                    // Fase 18: una única carpeta "Facturas" para TODO lo clasificado, sin
                    // importar a cuántas comunidades distintas pertenezcan sus adjuntos — antes
                    // se movía a una carpeta por comunidad (y solo cuando todos los adjuntos eran
                    // de la misma), lo que además de complicar el correo sin necesidad (cada
                    // comunidad con su propia carpeta IMAP) era la fuente directa de bugs reales
                    // de nomenclatura (espacios repetidos, "TRYCREATE" de Gmail — ver Fase 17).
                    // El archivo real de cada factura (en Drive, por comunidad) no cambia en
                    // absoluto: esto solo afecta a dónde va a parar el CORREO ya procesado.
                    // Un documento que la IA identifica con seguridad como no factura (informe,
                    // presupuesto, certificado...) no cuenta para decidir la carpeta del correo. Si
                    // ninguno de sus PDF es factura, el correo se trata como cualquier otro correo
                    // sin factura: 'ignored', sin mover, y se queda en la bandeja de entrada.
                    $invoiceOutcomes = array_values(array_filter($outcomes,static fn(array $item): bool => $item['status'] !== 'ignored_not_invoice'));
                    if (!$invoiceOutcomes) {
                        $this->saveMessage($mailbox,$client,$uid,$message,'ignored',count($outcomes),null);
                        continue;
                    }
                    $allClassified = !array_filter($invoiceOutcomes,static fn(array $item): bool => !in_array($item['status'],['classified','duplicate'],true));
                    if ($allClassified) {
                        $destination = 'facturgerman/Facturas';
                        try {
                            $this->moveWithReconnect($client,$uid,$destination,true);
                            $this->saveMessage($mailbox,$client,$uid,$message,'completed',count($outcomes),$destination,'moved');
                        } catch (\Throwable $imapError) {
                            $this->saveMessage($mailbox,$client,$uid,$message,'completed',count($outcomes),$destination,'failed',$imapError->getMessage());
                        }
                    } else {
                        $allUnknown = !array_filter($invoiceOutcomes,static fn(array $item): bool => $item['status'] !== 'unclassified');
                        $destination = $allUnknown ? 'facturgerman/Sin clasificar' : 'facturgerman/Pendientes de revisión';
                        try {
                            $this->moveWithReconnect($client,$uid,$destination,false);
                            $this->saveMessage($mailbox,$client,$uid,$message,'needs_review',count($outcomes),$destination,'moved');
                        } catch (\Throwable $imapError) {
                            $this->saveMessage($mailbox,$client,$uid,$message,'needs_review',count($outcomes),$destination,'failed',$imapError->getMessage());
                        }
                    }
                } catch (\Throwable $error) {
                    try { $client->move($uid,'facturgerman/Errores'); } catch (\Throwable $moveError) {
                        $error = new \RuntimeException($error->getMessage().'; movimiento IMAP: '.$moveError->getMessage(),0,$error);
                    }
                    $this->saveMessage($mailbox,$client,$uid,$message,'error',count($message['attachments']),'facturgerman/Errores','failed',$error->getMessage());
                    $counts['errors']++;
                }
            }
        } finally { $client->close(); }
    }

    /** Fase 24: mover el correo ya tratado, con un segundo intento tras reconectar. Entre leer un
     * correo y moverlo pasan las llamadas a la IA de todos sus adjuntos — con varios PDF, minutos —
     * y un corte de la conexión en ese rato bastaba para que el correo se quedara en la bandeja de
     * entrada. Al reconectar se vuelve a seleccionar la misma carpeta, así que el UID sigue valiendo.
     * Si el segundo intento también falla, la excepción sigue su curso y ImapMoveRetry lo
     * reintentará en la próxima pasada. */
    private function moveWithReconnect(ImapClient $client, string $uid, string $destination, bool $markSeen): void
    {
        try {
            if ($markSeen) $client->markSeen($uid);
            $client->move($uid,$destination);
        } catch (\Throwable $firstError) {
            error_log('uid='.$uid.' status=move_failed_reconnecting error='.$firstError->getMessage());
            $client->close(); $client->connect();
            if ($markSeen) $client->markSeen($uid);
            $client->move($uid,$destination);
        }
    }

    /** Recognition side of InvoiceDismissal — deliberately keyed by (mailbox_id,message_id_header)
     * only, never by attachment_sha256: dismissing one specific email must never turn into a
     * global "never look at this PDF again" rule, since the exact same file could legitimately
     * arrive attached to a genuinely different, real invoice email later. */
    private function isDismissedNotInvoice(int $mailboxId, string $messageIdHeader): bool
    {
        return $this->db->one("SELECT 1 ok FROM processed_messages WHERE mailbox_id=? AND message_id_header=? AND status='dismissed_not_invoice' LIMIT 1",
            [$mailboxId, $messageIdHeader]) !== null;
    }

    /**
     * Keeps a freshly added (or freshly re-enabled) mailbox from processing whatever was already
     * sitting in its inbox. "process_existing_on_activate" opts a mailbox out of this entirely
     * (today's behaviour: every UID is a candidate). Otherwise, the first time this mailbox has no
     * recorded baseline — brand new, or an admin just turned protection back on — this snapshots the
     * highest UID currently in the folder as the cut-off and processes nothing this cycle; from the
     * next cycle on, only UIDs strictly greater than that baseline are considered. A UIDVALIDITY
     * change invalidates any old baseline (the server has renumbered the mailbox, so old UIDs carry
     * no meaning) and is treated the same as "never baselined": re-snapshot now, process nothing this
     * cycle, never blindly replay whatever exists under the new UIDVALIDITY.
     * @param array<string,mixed> $mailbox @param list<string> $uids @return list<string>
     */
    private function applyBaseline(array $mailbox, ImapClient $client, array $uids): array
    {
        if ((int)($mailbox['process_existing_on_activate'] ?? 0) === 1) return $uids;
        $currentUidValidity = $client->uidValidity();
        $hadBaseline = $mailbox['baseline_captured_at'] !== null;
        $uidValidityChanged = $hadBaseline && (string)$mailbox['baseline_uidvalidity'] !== $currentUidValidity;
        if (!$hadBaseline || $uidValidityChanged) {
            $baseline = MailboxBaseline::fromUids($currentUidValidity, $uids);
            if ($uidValidityChanged) {
                error_log('mailbox_id='.$mailbox['id'].' status=uidvalidity_changed old='.$mailbox['baseline_uidvalidity'].' new='.$currentUidValidity.' rebaselined_at_uid='.$baseline['uid']);
            }
            $this->db->execute('UPDATE mailboxes SET baseline_uidvalidity=?,baseline_uid=?,baseline_captured_at=NOW() WHERE id=?',
                [$baseline['uidvalidity'], $baseline['uid'], $mailbox['id']]);
            return [];
        }
        $floor = (int)$mailbox['baseline_uid'];
        return array_values(array_filter($uids, static fn(string $uid): bool => (int)$uid > $floor));
    }

    /** @param array<string,mixed> $mailbox @param array<string,mixed> $message @param array<string,mixed> $attachment @param array<string,int> $counts @return array{status:string,community_id:?int} */
    private function processAttachment(array $mailbox, ImapClient $client, string $uid, array $message, array $attachment, array &$counts): array
    {
        $prior = $this->db->one("SELECT * FROM processed_attachments WHERE attachment_sha256=? AND status IN ('classified','unclassified','needs_review','duplicate','ignored_not_invoice') ORDER BY id LIMIT 1",[$attachment['sha256']]);
        if ($prior) {
            // Un PDF ya visto no vuelve a la IA. Si la primera vez se identificó como no factura,
            // la copia hereda esa decisión; en cualquier otro caso queda como 'duplicate'. La copia
            // nunca guarda traza propia: la traza solo sirve para el documento que alguien revisa.
            $status = $prior['status'] === 'ignored_not_invoice' ? 'ignored_not_invoice' : 'duplicate';
            $this->insertAttachment($mailbox,$client,$uid,$attachment,$status,['debug_trace_json'=>null]+$prior);
            $counts[$status === 'duplicate' ? 'duplicate' : 'not_invoice']++;
            return ['status'=>$status,'community_id'=>$prior['community_id'] ? (int)$prior['community_id'] : null];
        }
        $incoming = rtrim((string)$this->config['processing']['incoming_root'],'/').'/'.Text::slug((string)$mailbox['email']);
        if (!is_dir($incoming) && !mkdir($incoming,0770,true) && !is_dir($incoming)) throw new \RuntimeException('No se pudo crear incoming');
        $path = $incoming.'/'.$uid.'-'.bin2hex(random_bytes(4)).'-'.$attachment['safe_filename'];
        if (file_put_contents($path,$attachment['payload'],LOCK_EX) === false) throw new \RuntimeException('No se pudo guardar el adjunto temporal');
        $this->insertAttachment($mailbox,$client,$uid,$attachment,'downloaded',[]);
        $this->insertAttachment($mailbox,$client,$uid,$attachment,'processing',[]);
        $context = "Remitente: {$message['sender']}\nAsunto: {$message['subject']}\nAdjunto: {$attachment['original_filename']}\n{$message['body']}";

        // Comprensión, resolución y política: todo lo que decide vive en InvoiceProcessor.
        $result = $this->processor->process($path,(string)$attachment['original_filename'],$context,(string)$message['sender']);
        foreach ($result['ai_calls'] as $call) { $this->inputTokens += (int)$call['input_tokens']; $this->outputTokens += (int)$call['output_tokens']; }
        $status = $result['status']; $community = $result['community']; $supplier = $result['supplier']; $legacy = $result['legacy'];
        $classification = $result['classification'];

        // La traza se construye siempre, pero solo se guarda para lo que va a /Revisar (ver
        // ReviewTrace::persistForReview()). La decisión completa se guarda siempre en decision_json.
        $trace = new ReviewTrace();
        $trace->add('document',['filename'=>$attachment['original_filename'],'mime'=>$attachment['mime_type'],'size_bytes'=>$attachment['size'],'sha256'=>$attachment['sha256']]);
        $understandingCall = $result['ai_calls'][0] ?? [];
        $trace->add('document_understanding',['provider'=>$understandingCall['provider'] ?? null,'model'=>$understandingCall['model'] ?? null,
            'latency_ms'=>$understandingCall['latency_ms'] ?? null,'input_tokens'=>$understandingCall['input_tokens'] ?? null,'output_tokens'=>$understandingCall['output_tokens'] ?? null,
            'document'=>$classification['document'],'response'=>$result['raw_understanding']]);
        $trace->add('community_resolution',$classification['community']);
        $trace->add('supplier_resolution',$classification['supplier']);
        $trace->add('service_resolution',$classification['service']);
        $trace->add('final_decision',['status'=>$status,'reason'=>$result['reason'],'reason_code'=>$result['reason_code'],'autolink'=>$classification['autolink'],
            'ai_calls'=>count($result['ai_calls'])]);

        $target = $this->archiver->archive($path,(string)$attachment['original_filename'],$legacy,$community,$status);
        $drive = null;
        if ($status === 'classified' && $this->driveArchiver) $drive = $this->driveArchiver->archive($target,$community,$supplier,(string)$result['service']['folder'],$legacy);
        $data = $legacy + [
            'community_id'=>$community['id'] ?? null,
            'confidence'=>$community !== null ? round((float)$classification['community']['confidence'] * 100, 3) : null,
            'supplier_id'=>$supplier['id'] ?? null,
            'document_type'=>$result['understanding']['document_type'],
            'document_confidence'=>$result['understanding']['document_confidence'],
            'output_path'=>$target,'final_filename'=>basename($target),
            'extraction_json'=>json_encode($result['raw_understanding'],JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR),
            'decision_json'=>json_encode($classification,JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR),
            'error_message'=>$result['reason'],'debug_trace_json'=>$trace->persistForReview($status),
            'extractor_version'=>isset($understandingCall['provider']) ? $understandingCall['provider'].':'.$understandingCall['model'] : null,
            'drive_file_id'=>$drive['id'] ?? null,'drive_path'=>$drive['path'] ?? null,'drive_status'=>$drive ? 'uploaded' : null,
        ];
        $this->insertAttachment($mailbox,$client,$uid,$attachment,$status,$data);
        $counts[match ($status) { 'classified'=>'classified','needs_review'=>'needs_review','ignored_not_invoice'=>'not_invoice',default=>'unclassified' }]++;
        return ['status'=>$status,'community_id'=>$data['community_id'] !== null ? (int)$data['community_id'] : null];
    }

    private function insertAttachment(array $mailbox, ImapClient $client, string $uid, array $attachment, string $status, array $data): void
    {
        $this->db->execute("INSERT INTO processed_attachments(mailbox_id,uidvalidity,message_uid,original_filename,attachment_sha256,mime_type,size_bytes,
            provider,raw_supplier_name,provider_cif,service_type,supply_address,amount,currency,invoice_date,invoice_number,community_id,supplier_id,confidence,document_type,document_confidence,final_filename,output_path,status,
            extraction_json,decision_json,debug_trace_json,error_message,extractor_version,drive_file_id,drive_path,drive_status,processed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE provider=VALUES(provider),raw_supplier_name=VALUES(raw_supplier_name),provider_cif=VALUES(provider_cif),service_type=VALUES(service_type),
            supply_address=VALUES(supply_address),amount=VALUES(amount),currency=VALUES(currency),invoice_date=VALUES(invoice_date),
            invoice_number=VALUES(invoice_number),community_id=VALUES(community_id),supplier_id=VALUES(supplier_id),confidence=VALUES(confidence),
            document_type=VALUES(document_type),document_confidence=VALUES(document_confidence),final_filename=VALUES(final_filename),
            output_path=VALUES(output_path),status=VALUES(status),extraction_json=VALUES(extraction_json),decision_json=VALUES(decision_json),debug_trace_json=VALUES(debug_trace_json),
            error_message=VALUES(error_message),extractor_version=VALUES(extractor_version),drive_file_id=VALUES(drive_file_id),drive_path=VALUES(drive_path),drive_status=VALUES(drive_status),processed_at=NOW()", [
            $mailbox['id'],$client->uidValidity(),$uid,$attachment['original_filename'],$attachment['sha256'],$attachment['mime_type'],$attachment['size'],
            $data['proveedor']??$data['provider']??null,$data['raw_supplier_name']??null,$data['proveedor_cif']??$data['provider_cif']??null,$data['tipo_servicio']??$data['service_type']??null,
            $data['direccion']??$data['supply_address']??null,$data['importe']??$data['amount']??null,$data['moneda']??$data['currency']??null,
            ($data['fecha_factura']??$data['invoice_date']??null) ?: null,($data['numero_factura']??$data['invoice_number']??null) ?: null,$data['community_id']??null,
            $data['supplier_id']??null,$data['confidence']??null,$data['document_type']??null,$data['document_confidence']??null,
            $data['final_filename']??null,$data['output_path']??null,$status,$data['extraction_json']??null,$data['decision_json']??null,$data['debug_trace_json']??null,
            $data['error_message']??null,$data['extractor_version']??null,$data['drive_file_id']??null,$data['drive_path']??null,$data['drive_status']??null,
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
