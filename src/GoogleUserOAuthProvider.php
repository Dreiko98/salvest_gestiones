<?php
declare(strict_types=1);

namespace Salvest;

final class GoogleUserOAuthProvider implements AccessTokenProvider
{
    public function __construct(private string $clientFile,private string $tokenFile){}

    public function accessToken():string
    {
        $token=$this->json($this->tokenFile);
        if(!empty($token['access_token'])&&(float)($token['expires_at']??0)>time()+60)return(string)$token['access_token'];
        if(empty($token['refresh_token']))throw new \RuntimeException('Falta refresh_token de Google Drive');
        $container=$this->json($this->clientFile);$client=$container['installed']??null;
        if(!is_array($client))throw new \RuntimeException('El cliente OAuth de Google no es de escritorio');
        $curl=curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_POSTFIELDS=>http_build_query([
            'client_id'=>$client['client_id'],'client_secret'=>$client['client_secret'],'refresh_token'=>$token['refresh_token'],'grant_type'=>'refresh_token',
        ])]);
        $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        if($body===false)throw new \RuntimeException('Error de red OAuth: '.curl_error($curl));
        $fresh=json_decode($body,true,flags:JSON_THROW_ON_ERROR);
        if($status<200||$status>=300)throw new \RuntimeException('OAuth respondió HTTP '.$status.': '.($fresh['error_description']??$fresh['error']??'error desconocido'));
        $fresh['refresh_token']=$token['refresh_token'];$fresh['expires_at']=time()+(int)($fresh['expires_in']??3600);
        $encoded=json_encode($fresh,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
        if(file_put_contents($this->tokenFile,$encoded,LOCK_EX)===false)throw new \RuntimeException('No se pudo actualizar el token de Google');
        @chmod($this->tokenFile,0600);return(string)$fresh['access_token'];
    }

    /** @return array<string,mixed> */
    private function json(string $path):array
    {
        $body=file_get_contents($path);if($body===false)throw new \RuntimeException("No se pudo leer $path");
        $value=json_decode($body,true,flags:JSON_THROW_ON_ERROR);if(!is_array($value))throw new \RuntimeException("JSON inválido: $path");return$value;
    }
}
