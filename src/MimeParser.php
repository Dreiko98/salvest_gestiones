<?php
declare(strict_types=1);

namespace Salvest;

final class MimeParser
{
    /** @return array{message_id:string,sender:string,subject:string,date:string,body:string,attachments:list<array<string,mixed>>} */
    public function parse(string $raw): array
    {
        [$headerText, $body] = array_pad(preg_split("/\r?\n\r?\n/", $raw, 2) ?: [], 2, '');
        $headers = $this->headers($headerText);
        $attachments = []; $plain = [];
        $this->walk($headers, $body, $attachments, $plain, 1);
        return [
            'message_id'=>$headers['message-id'] ?? '', 'sender'=>$this->decode($headers['from'] ?? ''),
            'subject'=>$this->decode($headers['subject'] ?? ''), 'date'=>$headers['date'] ?? '',
            'body'=>mb_substr(implode("\n\n", $plain), 0, 50000), 'attachments'=>$attachments,
        ];
    }

    /** @return array<string,string> */
    private function headers(string $text): array
    {
        $text = preg_replace("/\r?\n[ \t]+/", ' ', $text) ?? $text; $result = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if (!str_contains($line, ':')) continue;
            [$name,$value] = explode(':',$line,2); $result[strtolower(trim($name))] = trim($value);
        }
        return $result;
    }

    /** @param array<string,string> $headers @param list<array<string,mixed>> $attachments @param list<string> $plain */
    private function walk(array $headers, string $body, array &$attachments, array &$plain, int $index): void
    {
        // The boundary must keep its original case: Outlook/Hotmail and Apple Mail routinely
        // generate mixed-case boundaries (e.g. "_002_FED2942EE6AB...hotmailcom_",
        // "Apple-Mail=_8A6213BB-..."), and explode() is byte-exact. Matching the boundary against
        // a lowercased copy of Content-Type — used only to check the "multipart/" type prefix,
        // which genuinely is case-insensitive — silently produced a lowercased boundary that
        // never matched the real (mixed-case) delimiters in the body. The whole message then fell
        // through as a single unsplit chunk starting with "--", which the very next check exists
        // to skip (the closing boundary's epilogue) — so it was discarded as "no parts found",
        // with zero attachments, for every sender whose mail client capitalises its boundaries.
        $contentType = $headers['content-type'] ?? 'text/plain';
        $type = strtolower($contentType);
        if (str_starts_with($type, 'multipart/') && preg_match('/boundary\s*=\s*(?:"([^"]+)"|([^;\s]+))/i', $contentType, $match)) {
            $boundary = $match[1] ?: $match[2];
            foreach (explode('--'.$boundary, $body) as $part) {
                $part = ltrim($part, "\r\n");
                if ($part === '' || str_starts_with($part, '--')) continue;
                [$partHeaders,$partBody] = array_pad(preg_split("/\r?\n\r?\n/", $part, 2) ?: [], 2, '');
                $this->walk($this->headers($partHeaders), preg_replace("/\r?\n$/",'', $partBody) ?? $partBody, $attachments, $plain, ++$index);
            }
            return;
        }
        $encoding = strtolower($headers['content-transfer-encoding'] ?? '');
        $decodedBody = match ($encoding) {
            'base64' => base64_decode(preg_replace('/\s+/', '', $body) ?? $body, true) ?: '',
            'quoted-printable' => quoted_printable_decode($body), default => $body,
        };
        $disposition = strtolower($headers['content-disposition'] ?? '');
        $filename = '';
        foreach ([$headers['content-disposition'] ?? '', $headers['content-type'] ?? ''] as $source) {
            if (preg_match('/(?:filename|name)\*?\s*=\s*(?:UTF-8\'\')?(?:"([^"]+)"|([^;\s]+))/i', $source, $match)) {
                $filename = rawurldecode($match[1] ?: $match[2]); break;
            }
        }
        $mime = trim(explode(';', $type, 2)[0]);
        $supported = $mime === 'application/pdf' || str_starts_with($mime, 'image/') || preg_match('/\.(pdf|jpe?g|png|tiff?|webp)$/i', $filename);
        if ($supported && ($filename !== '' || str_contains($disposition, 'attachment'))) {
            $extension = $mime === 'application/pdf' ? '.pdf' : '.jpg';
            $original = $this->decode($filename ?: "adjunto-sin-nombre-$index$extension");
            $attachments[] = ['original_filename'=>$original,'safe_filename'=>Text::safeFilename($original,$index,$extension),
                'mime_type'=>$mime,'payload'=>$decodedBody,'sha256'=>hash('sha256',$decodedBody),'size'=>strlen($decodedBody)];
        } elseif (str_starts_with($mime, 'text/plain')) {
            $plain[] = trim($decodedBody);
        } elseif (str_starts_with($mime, 'text/html')) {
            $plain[] = trim(html_entity_decode(strip_tags(preg_replace('/<(script|style).*?<\/\1>/is','',$decodedBody) ?? $decodedBody)));
        }
    }

    private function decode(string $value): string
    {
        return function_exists('mb_decode_mimeheader') ? mb_decode_mimeheader($value) : $value;
    }
}
