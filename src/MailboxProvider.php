<?php
declare(strict_types=1);

namespace Salvest;

final class MailboxProvider
{
    /** @return array{host:string,port:int,use_ssl:int} */
    public static function connection(string$provider):array
    {
        return match($provider){
            'ionos'=>['host'=>'imap.ionos.es','port'=>993,'use_ssl'=>1],
            'gmail'=>['host'=>'imap.gmail.com','port'=>993,'use_ssl'=>1],
            default=>throw new \InvalidArgumentException('Proveedor de correo no permitido'),
        };
    }

    public static function fromHost(string$host):string
    {
        return strtolower(trim($host))==='imap.gmail.com'?'gmail':'ionos';
    }
}
