<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Pure computation shared by WebApp (captures the baseline synchronously when a
 * protected mailbox is saved/activated) and Worker (defensive fallback capture,
 * and re-capture on a UIDVALIDITY change). Kept dependency-free so it is directly
 * testable without a live IMAP connection.
 */
final class MailboxBaseline
{
    /** @param list<string> $uids @return array{uidvalidity:string,uid:int} */
    public static function fromUids(string $uidValidity, array $uids): array
    {
        return ['uidvalidity' => $uidValidity, 'uid' => $uids ? max(array_map('intval', $uids)) : 0];
    }
}
