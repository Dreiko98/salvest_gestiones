<?php
declare(strict_types=1);

namespace Salvest;

final class Worker
{
    private MimeParser $parser;
    private Classifier $classifier;
    private InvoiceRouter $router;
    private Archiver $archiver;
    private ?DriveInvoiceArchiver $driveArchiver=null;

    /** @param array<string,mixed> $config */
    public function __construct(private Database $db, private Crypto $crypto,
        private ExtractorProvider $extractor, private array $config)
    {
        $this->parser = new MimeParser();
        $this->classifier = new Classifier($db, (float)$config['processing']['classification_threshold']);
        $this->router = new InvoiceRouter($this->classifier, new CommunitySupplierAutoLinker($db));
        $this->archiver = new Archiver((string)$config['processing']['storage_root']);
        if((bool)($config['google_drive']['enabled']??false)){
            $tokens=new GoogleUserOAuthProvider((string)$config['google_drive']['oauth_client_file'],(string)$config['google_drive']['oauth_token_file']);
            $this->driveArchiver=new DriveInvoiceArchiver(new GoogleDriveClient($tokens),(string)$config['google_drive']['root_folder_id']);
        }
    }

    /** Builds a Worker exactly the way every entry point (cron, CLI, manual button) needs it, so
     * none of them has to repeat the Crypto/extractor wiring by hand — and, critically, so all
     * three stay in sync on which extractor is actually used: this is the ONLY place that wires
     * Claude/OpenAI, so a future extractor change never risks updating some entry points and
     * missing others. Fase 8: Claude is the primary extractor, OpenAI the automatic fallback for
     * any call Claude's own client throws on — see FallbackExtractor's docblock. */
    public static function create(Database $db, array $config): self
    {
        $primary = new ClaudeExtractor($config['anthropic']);
        $fallback = new OpenAIExtractor($config['openai']);
        return new self($db, new Crypto((string)$config['app']['encryption_key']), new FallbackExtractor($primary, $fallback), $config);
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
        $counts = ['messages'=>0,'documents'=>0,'classified'=>0,'unclassified'=>0,'needs_review'=>0,'duplicate'=>0,'errors'=>0];
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
            $this->db->execute("UPDATE processing_runs SET finished_at=NOW(),status=?,messages_reviewed=?,documents_detected=?,classified_count=?,unclassified_count=?,needs_review_count=?,duplicate_count=?,error_count=?,openai_input_tokens=?,openai_output_tokens=? WHERE id=?",
                [$counts['errors']?'partial':'completed',$counts['messages'],$counts['documents'],$counts['classified'],$counts['unclassified'],$counts['needs_review'],$counts['duplicate'],$counts['errors'],$this->extractor->inputTokens,$this->extractor->outputTokens,$runId]);
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
            $uids = $this->applyBaseline($mailbox, $client, $client->listUids()); $examined = 0;
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
                        // OpenAIExtractor, which picks its request shape from this exact string.
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
                    $communityIds = array_values(array_unique(array_filter(array_column($outcomes,'community_id'))));
                    $allClassified = !array_filter($outcomes,static fn(array $item): bool => !in_array($item['status'],['classified','duplicate'],true));
                    if ($allClassified && count($communityIds) === 1) {
                        $community = $this->db->one('SELECT * FROM communities WHERE id=?',[$communityIds[0]]);
                        $destination = 'facturgerman/'.($community['imap_folder_name'] ?: Text::slug((string)$community['official_name']));
                        try {
                            $client->markSeen($uid); $client->move($uid,$destination);
                            $this->saveMessage($mailbox,$client,$uid,$message,'completed',count($outcomes),$destination,'moved');
                        } catch (\Throwable $imapError) {
                            $this->saveMessage($mailbox,$client,$uid,$message,'completed',count($outcomes),$destination,'failed',$imapError->getMessage());
                        }
                    } else {
                        $allUnknown = !array_filter($outcomes,static fn(array $item): bool => $item['status'] !== 'unclassified');
                        $destination = $allUnknown ? 'facturgerman/Sin clasificar' : 'facturgerman/Pendientes de revisión';
                        try {
                            $client->move($uid,$destination);
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
        $prior = $this->db->one("SELECT * FROM processed_attachments WHERE attachment_sha256=? AND status IN ('classified','unclassified','needs_review','duplicate') ORDER BY id LIMIT 1",[$attachment['sha256']]);
        if ($prior) {
            // A 'duplicate' row never keeps a technical trace of its own, even if the original
            // it points to was needs_review and had one — the trace is only ever meaningful for
            // the specific attachment a human is about to open and debug.
            $this->insertAttachment($mailbox,$client,$uid,$attachment,'duplicate',['debug_trace_json'=>null]+$prior);
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

        // Built in memory regardless of outcome — cheap, no I/O of its own — and only ever
        // persisted (in insertAttachment, below) when the attachment ends up in needs_review.
        // A failure anywhere in ReviewTrace can never throw: see its own docblock.
        $trace = new ReviewTrace();
        $trace->add('document',['filename'=>$attachment['original_filename'],'mime'=>$attachment['mime_type'],'size_bytes'=>$attachment['size'],'sha256'=>$attachment['sha256']]);

        $trace->add('openai_request',['model'=>$this->config['anthropic']['model']??null,'reasoning'=>'low']);
        $tokensBefore=['in'=>$this->extractor->inputTokens,'out'=>$this->extractor->outputTokens];
        $started=microtime(true);
        $invoice = $this->extractor->extract($path,(string)$attachment['mime_type'],$context);
        $extractorVersion=$this->extractor->version();
        $trace->add('openai_response',['latency_ms'=>(int)round((microtime(true)-$started)*1000),
            'input_tokens'=>$this->extractor->inputTokens-$tokensBefore['in'],'output_tokens'=>$this->extractor->outputTokens-$tokensBefore['out'],
            'provider'=>$extractorVersion,'response'=>$invoice]);
        $openAiServiceRaw=$invoice['tipo_servicio']??null;

        // Debug-only observer for Classifier's tier-by-tier reasoning — never influences the
        // decision, see Classifier::classify()/resolveSupplierInCommunity() docblocks. Buffers
        // into two lists (community/supplier) so each becomes one readable trace step below,
        // instead of a dozen tiny ones.
        $communitySignals=[];$supplierSignals=[];
        $resolutionTrace=function(string $tier,string $outcome,array $details)use(&$communitySignals,&$supplierSignals):void{
            $entry=['method'=>$tier,'result'=>$outcome]+$details;
            if(str_starts_with($tier,'supplier'))$supplierSignals[]=$entry;else $communitySignals[]=$entry;
        };
        // Second, restricted look at the *same* PDF — headers/logos/stamps included — only
        // reached when the community is known but no deterministic master-data match found a
        // supplier among that community's own suppliers. Never called otherwise, so it never
        // adds latency/cost to the common case.
        $restrictedCalled=false;$restrictedCandidates=[];$restrictedResponse=null;$restrictedChosen=null;
        $restrictedResolver=function(array $candidates,array $community)use($path,$attachment,$context,&$restrictedCalled,&$restrictedCandidates,&$restrictedResponse,&$restrictedChosen):?int{
            $restrictedCalled=true;$restrictedCandidates=$candidates;
            try{
                $chosen=$this->extractor->resolveSupplierAmongCandidates($path,(string)$attachment['mime_type'],$context,(string)$community['official_name'],$candidates);
                $restrictedChosen=$chosen;$restrictedResponse=['supplier_id'=>$chosen];
                return $chosen;
            }catch(\Throwable$error){
                error_log('restricted_openai_retry status=failed community_id='.$community['id'].' '.$error->getMessage());
                $restrictedResponse=['error'=>$error->getMessage()];return null;
            }
        };
        $route=$this->router->route($invoice,(string)$message['sender'],$context,$restrictedResolver,$resolutionTrace);
        $decision=$route['decision'];$supplier=$route['supplier'];$status=$route['status'];

        // Fase 15: una fecha de factura ausente o con un formato que Archiver no reconoce nunca
        // debe destruir todo el intento — antes, Archiver::archive() lanzaba una excepción no
        // capturada aquí, y esa excepción se comía la copia local del PDF, el JSON de extracción
        // y el detalle técnico entero, dejando solo una fila 'error' opaca sin nada que revisar
        // (caso real: un "Estado de Cuenta" con comunidad y proveedor ya resueltos, pero sin una
        // fecha de factura reconocible). Se degrada a needs_review con motivo explícito — el
        // documento sigue archivándose (a la carpeta de no clasificados) y queda descargable y
        // con su detalle técnico, en vez de perderse.
        $invalidDate=$status==='classified'&&!preg_match('/^\d{4}-\d{2}/',(string)($invoice['fecha_factura']??''));
        if($invalidDate){$status='needs_review';$route['reason']='Fecha de factura no reconocible; revisa y confirma manualmente.';}

        $trace->add('community_resolution',['signals'=>$communitySignals,'community_id'=>$decision['community']['id']??null,
            'official_name'=>$decision['community']['official_name']??null,'evidence'=>$decision['evidence']]);
        $communityCandidates=$decision['community']?$this->classifier->suppliersForCommunity((int)$decision['community']['id']):[];
        $trace->add('supplier_resolution',['raw_supplier_name'=>$route['raw_supplier_name'],'supplier_cif'=>$invoice['proveedor_cif']??null,
            'raw_supplier_name_normalized'=>Text::normalizeCompanyName((string)($invoice['proveedor']??'')),
            'supplier_cif_normalized'=>Text::normalizeIdentifier((string)($invoice['proveedor_cif']??'')),
            'community_candidates'=>$communityCandidates,'tiers'=>$supplierSignals,
            'supplier_id'=>$supplier['id']??null,'supplier_name'=>$supplier['official_name']??null,
            'evidence'=>$route['evidence']['supplier'],'ambiguous'=>$route['supplier_ambiguous']]);
        $trace->add('service_resolution',['openai_service'=>$openAiServiceRaw,'suppliers_main_service_type'=>$supplier['service_type_name']??null,
            'community_suppliers_category'=>$supplier['category']??null,'final_service'=>$route['service'],'evidence'=>$route['evidence']['service']]);
        if($restrictedCalled){
            $trace->add('restricted_openai',['model'=>$this->config['anthropic']['model']??null,'reasoning'=>'medium',
                'candidates_sent'=>$restrictedCandidates,'response'=>$restrictedResponse,'chosen_supplier_id'=>$restrictedChosen,
                'provider'=>$this->extractor->version(),
                'validated'=>($route['evidence']['supplier']['type']??null)==='restricted_openai_retry']);
        }
        $blockingFactor=$invalidDate?'invalid_date':($status==='needs_review'?($route['supplier_ambiguous']?'supplier_ambiguous':'supplier_unresolved'):($status==='unclassified'?'community_unresolved':null));
        $trace->add('final_decision',['status'=>$status,'reason'=>$route['reason'],'blocking_factor'=>$blockingFactor]);

        // MySQL corrects OpenAI's suggestion here: $route['service'] already went through
        // Classifier::resolveService() (supplier's configured type > community-supplier
        // relation category > OpenAI's own tipo_servicio guess, only as a last resort). And a
        // "proveedor" is only ever the confirmed supplier's own official name — OpenAI's raw
        // guess is kept apart, in raw_supplier_name, never promoted to a real supplier.
        $invoice['proveedor']=$supplier?$supplier['official_name']:$route['raw_supplier_name'];
        $invoice['tipo_servicio']=mb_strtolower((string)$route['service']);
        $target = $this->archiver->archive($path,(string)$attachment['original_filename'],$invoice,$decision['community'],$status);
        $drive=null;
        if($status==='classified'&&$this->driveArchiver)$drive=$this->driveArchiver->archive($target,$decision['community'],$supplier,(string)$route['service'],$invoice);
        $decisionTrace=$decision+['supplier_evidence'=>$route['evidence']['supplier'],'service_evidence'=>$route['evidence']['service'],'reason'=>$route['reason']];
        // The full technical trace is only ever worth keeping for a document a human will have to
        // open and debug on /Revisar — see ReviewTrace::persistForReview() for exactly which
        // statuses that covers and its never-throws guarantee.
        $debugTraceJson=$trace->persistForReview($status);
        $data = array_merge($invoice,['community_id'=>$decision['community']['id'] ?? null,'confidence'=>$decision['confidence'],
            'proveedor'=>$supplier?$supplier['official_name']:null,'raw_supplier_name'=>$route['raw_supplier_name'],
            'output_path'=>$target,'final_filename'=>basename($target),'extraction_json'=>json_encode($invoice,JSON_UNESCAPED_UNICODE),
            'decision_json'=>json_encode($decisionTrace,JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR),
            'error_message'=>$route['reason'],'debug_trace_json'=>$debugTraceJson,'extractor_version'=>$extractorVersion,
            'drive_file_id'=>$drive['id']??null,'drive_path'=>$drive['path']??null,'drive_status'=>$drive?'uploaded':null]);
        $this->insertAttachment($mailbox,$client,$uid,$attachment,$status,$data);
        $counts[$status === 'needs_review' ? 'needs_review' : ($status === 'classified' ? 'classified' : 'unclassified')]++;
        return ['status'=>$status,'community_id'=>$data['community_id'] ? (int)$data['community_id'] : null];
    }

    private function insertAttachment(array $mailbox, ImapClient $client, string $uid, array $attachment, string $status, array $data): void
    {
        $this->db->execute("INSERT INTO processed_attachments(mailbox_id,uidvalidity,message_uid,original_filename,attachment_sha256,mime_type,size_bytes,
            provider,raw_supplier_name,provider_cif,service_type,supply_address,amount,currency,invoice_date,invoice_number,community_id,confidence,final_filename,output_path,status,
            extraction_json,decision_json,debug_trace_json,error_message,extractor_version,drive_file_id,drive_path,drive_status,processed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE provider=VALUES(provider),raw_supplier_name=VALUES(raw_supplier_name),provider_cif=VALUES(provider_cif),service_type=VALUES(service_type),
            supply_address=VALUES(supply_address),amount=VALUES(amount),currency=VALUES(currency),invoice_date=VALUES(invoice_date),
            invoice_number=VALUES(invoice_number),community_id=VALUES(community_id),confidence=VALUES(confidence),final_filename=VALUES(final_filename),
            output_path=VALUES(output_path),status=VALUES(status),extraction_json=VALUES(extraction_json),decision_json=VALUES(decision_json),debug_trace_json=VALUES(debug_trace_json),
            error_message=VALUES(error_message),extractor_version=VALUES(extractor_version),drive_file_id=VALUES(drive_file_id),drive_path=VALUES(drive_path),drive_status=VALUES(drive_status),processed_at=NOW()", [
            $mailbox['id'],$client->uidValidity(),$uid,$attachment['original_filename'],$attachment['sha256'],$attachment['mime_type'],$attachment['size'],
            $data['proveedor']??$data['provider']??null,$data['raw_supplier_name']??null,$data['proveedor_cif']??$data['provider_cif']??null,$data['tipo_servicio']??$data['service_type']??null,
            $data['direccion']??$data['supply_address']??null,$data['importe']??$data['amount']??null,$data['moneda']??$data['currency']??null,
            ($data['fecha_factura']??$data['invoice_date']??null) ?: null,($data['numero_factura']??$data['invoice_number']??null) ?: null,$data['community_id']??null,
            $data['confidence']??null,$data['final_filename']??null,$data['output_path']??null,$status,$data['extraction_json']??null,$data['decision_json']??null,$data['debug_trace_json']??null,
            $data['error_message']??null,$data['extractor_version']??OpenAIExtractor::VERSION,$data['drive_file_id']??null,$data['drive_path']??null,$data['drive_status']??null,
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
