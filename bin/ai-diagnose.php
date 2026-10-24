#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Diagnóstico manual de la IA sobre uno o varios PDF, con la IA real y el mismo InvoiceProcessor
 * que usa el Worker. Muestra, por documento, lo que entró, la respuesta cruda de cada llamada
 * (proveedor, modelo, tokens, latencia) y la decisión final con sus motivos.
 *
 * Nunca escribe nada: el autolink va desactivado (si la decisión dice "autolink_disabled" es que
 * se habría intentado), no hay IMAP ni Drive y los resolvedores solo leen. Con --no-db solo se
 * ejecuta la comprensión del documento, sin base de datos.
 *
 *   php bin/ai-diagnose.php factura.pdf [otra.pdf ...] [--provider=fallback|claude|openai]
 *       [--sender=facturas@proveedor.es] [--subject="Factura septiembre"] [--no-db] [--json]
 *
 * La configuración sale de bootstrap.php (SALVEST_CONFIG para apuntar a otra base). Las claves de
 * API nunca se imprimen. Los PDF se envían al proveedor de IA configurado: úsalo solo con
 * documentos de Salvest y los proveedores ya contratados.
 */

$config = require dirname(__DIR__) . '/bootstrap.php';

$options = ['provider'=>'fallback','sender'=>'','subject'=>'','no-db'=>false,'json'=>false];
$files = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(provider|sender|subject)=(.*)$/', $arg, $m)) $options[$m[1]] = $m[2];
    elseif ($arg === '--no-db' || $arg === '--json') $options[substr($arg, 2)] = true;
    elseif (str_starts_with($arg, '--')) { fwrite(STDERR, "Opción desconocida: $arg\n"); exit(2); }
    else $files[] = $arg;
}
if (!$files) { fwrite(STDERR, "Uso: php bin/ai-diagnose.php documento.pdf [...] [--provider=fallback|claude|openai] [--sender=] [--subject=] [--no-db] [--json]\n"); exit(2); }

$client = match ($options['provider']) {
    'claude' => new Salvest\ClaudeClient($config['anthropic']),
    'openai' => new Salvest\OpenAIClient($config['openai']),
    'fallback' => new Salvest\FallbackAiClient(new Salvest\ClaudeClient($config['anthropic']), new Salvest\OpenAIClient($config['openai'])),
    default => null,
};
if ($client === null) { fwrite(STDERR, "--provider debe ser fallback, claude u openai\n"); exit(2); }
$ai = new Salvest\DocumentIntelligence($client);

$processor = null;
if (!$options['no-db']) {
    try { $processor = Salvest\Worker::processor(new Salvest\Database($config['database']), $ai, null, false); }
    catch (Throwable $error) { fwrite(STDERR, 'Sin base de datos ('.$error->getMessage()."): solo comprensión del documento.\n"); }
}

$exit = 0;
foreach ($files as $file) {
    $started = microtime(true);
    $report = ['file'=>$file];
    try {
        if (!is_file($file)) throw new RuntimeException('No existe el archivo');
        $bytes = (string)file_get_contents($file);
        $report += ['size_bytes'=>strlen($bytes),'sha256'=>hash('sha256', $bytes)];
        $sender = $options['sender'];
        $context = "Remitente: {$sender}\nAsunto: {$options['subject']}\nAdjunto: ".basename($file);
        if ($processor !== null) {
            $result = $processor->process($file, basename($file), $context, $sender);
            $c = $result['classification'];
            $report += [
                'status'=>$result['status'],'reason_code'=>$result['reason_code'],'reason'=>$result['reason'],
                'document'=>$c['document'],
                'community'=>['id'=>$c['community']['id'],'name'=>$c['community']['name'],'source'=>$c['community']['source'],'outcome'=>$c['community']['outcome'],
                    'confidence'=>$c['community']['confidence'],'tax_id_matches'=>$c['community']['tax_id']['matches'] ?? null,'candidates'=>count($c['community']['candidates']),'ai'=>$c['community']['ai']],
                'supplier'=>['id'=>$c['supplier']['id'],'name'=>$c['supplier']['name'],'source'=>$c['supplier']['source'],'outcome'=>$c['supplier']['outcome'],
                    'confidence'=>$c['supplier']['confidence'],'linked'=>$c['supplier']['linked'],'candidates'=>count($c['supplier']['candidates']),'ai'=>$c['supplier']['ai']],
                'service'=>['folder'=>$c['service']['folder'],'source'=>$c['service']['source'],'tried'=>$c['service']['tried']],
                'autolink'=>$c['autolink'],'corrections'=>$c['corrections'],
                'understanding'=>$result['raw_understanding'],
            ];
        } else {
            $ai->resetCalls();
            $understanding = $ai->understand($file, $context);
            $gate = (new Salvest\ClassificationPolicy())->documentGate($understanding, basename($file));
            $report += ['status'=>$gate['decision'],'reason_code'=>$gate['reason_code'],'reason'=>$gate['reason'],'understanding'=>$ai->calls[0]['response'] ?? null];
        }
        $report['ai_calls'] = array_map(static fn(array $call): array => array_diff_key($call, ['response'=>true]) + ($call['task'] === Salvest\AiContracts::UNDERSTAND_TASK ? [] : ['response'=>$call['response']]), $ai->calls);
        $report['tokens'] = ['input'=>array_sum(array_column($ai->calls, 'input_tokens')),'output'=>array_sum(array_column($ai->calls, 'output_tokens'))];
    } catch (Throwable $error) {
        $report['error'] = $error->getMessage();
        $report['ai_calls'] = array_map(static fn(array $call): array => array_diff_key($call, ['response'=>true]), $ai->calls);
        $exit = 1;
    }
    $report['elapsed_ms'] = (int)round((microtime(true) - $started) * 1000);

    if ($options['json']) { echo json_encode($report, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR), PHP_EOL; continue; }
    echo str_repeat('=', 78), PHP_EOL, $file, PHP_EOL;
    if (isset($report['error'])) { echo 'ERROR: ', $report['error'], PHP_EOL; }
    else {
        echo 'Decisión: ', $report['status'], $report['reason_code'] ? ' ('.$report['reason_code'].')' : '', $report['reason'] ? ' — '.$report['reason'] : '', PHP_EOL;
        if (isset($report['document'])) echo 'Documento: ', $report['document']['type'], ' conf=', $report['document']['confidence'], ' — ', $report['document']['reason'], PHP_EOL;
        foreach (['community'=>'Comunidad','supplier'=>'Proveedor'] as $key => $label) {
            if (!isset($report[$key])) continue;
            $r = $report[$key];
            echo $label, ': ', $r['name'] ?? '—', ' [id=', $r['id'] ?? '—', ' fuente=', $r['source'] ?? '—', ' resultado=', $r['outcome'], ' conf=', $r['confidence'], ' candidatos=', $r['candidates'], ']', PHP_EOL;
            if ($r['ai'] !== null) echo '  IA: ', json_encode($r['ai'], JSON_UNESCAPED_UNICODE), PHP_EOL;
        }
        if (isset($report['service'])) echo 'Servicio: ', $report['service']['folder'] ?? '—', ' (', $report['service']['source'] ?? 'ninguna fuente', ')', PHP_EOL;
        if (isset($report['autolink'])) echo 'Autolink: ', ($report['autolink']['reason'] ?? '') === 'autolink_disabled' ? 'se intentaría (desactivado en el diagnóstico)' : 'no — '.($report['autolink']['reason'] ?? ''), PHP_EOL;
        echo 'Comprensión cruda: ', json_encode($report['understanding'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), PHP_EOL;
    }
    foreach ($report['ai_calls'] as $call) echo 'Llamada ', $call['task'], ': ', $call['provider'], '/', $call['model'], ' ', $call['latency_ms'], ' ms, tokens ', $call['input_tokens'], '+', $call['output_tokens'], PHP_EOL;
    echo 'Total: ', $report['elapsed_ms'], ' ms', PHP_EOL;
}
exit($exit);
