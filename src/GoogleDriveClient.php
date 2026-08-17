<?php
declare(strict_types=1);

namespace Salvest;

final class GoogleDriveClient
{
    public function __construct(private AccessTokenProvider $tokens){}

    /** @return list<array<string,mixed>> */
    public function children(string $parentId):array
    {
        $query=http_build_query(['q'=>"'$parentId' in parents and trashed=false",'fields'=>'files(id,name,mimeType,size,modifiedTime)','pageSize'=>1000,'orderBy'=>'name','supportsAllDrives'=>'true','includeItemsFromAllDrives'=>'true']);
        return$this->request('GET','https://www.googleapis.com/drive/v3/files?'.$query)['files']??[];
    }

    public function ensureFolder(string $parentId,string $name):string
    {
        foreach($this->children($parentId)as$file)if(($file['name']??null)===$name&&($file['mimeType']??null)==='application/vnd.google-apps.folder')return(string)$file['id'];
        $query=http_build_query(['fields'=>'id,name,parents','supportsAllDrives'=>'true']);
        $result=$this->request('POST','https://www.googleapis.com/drive/v3/files?'.$query,json_encode(['name'=>$name,'mimeType'=>'application/vnd.google-apps.folder','parents'=>[$parentId]],JSON_THROW_ON_ERROR),'application/json');
        return(string)$result['id'];
    }

    /** @return array<string,mixed> */
    public function upload(string $parentId,string $filename,string $localPath):array
    {
        $boundary='salvest-'.bin2hex(random_bytes(12));$bytes=file_get_contents($localPath);
        if($bytes===false)throw new \RuntimeException("No se pudo leer $localPath");
        $metadata=json_encode(['name'=>$filename,'parents'=>[$parentId]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $body="--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$metadata\r\n--$boundary\r\nContent-Type: application/pdf\r\n\r\n".$bytes."\r\n--$boundary--\r\n";
        $query=http_build_query(['uploadType'=>'multipart','supportsAllDrives'=>'true','fields'=>'id,name,parents,mimeType,size,webViewLink']);
        return$this->request('POST','https://www.googleapis.com/upload/drive/v3/files?'.$query,$body,"multipart/related; boundary=$boundary");
    }

    /** @return array<string,mixed> */
    public function move(string $fileId,string $sourceParentId,string $destinationParentId,?string $newName=null):array
    {
        $query=http_build_query(['addParents'=>$destinationParentId,'removeParents'=>$sourceParentId,'supportsAllDrives'=>'true','fields'=>'id,name,parents,mimeType,size,webViewLink']);
        $body=$newName===null?'{}':json_encode(['name'=>$newName],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        return$this->request('PATCH','https://www.googleapis.com/drive/v3/files/'.rawurlencode($fileId).'?'.$query,$body,'application/json');
    }

    public function trash(string $fileId):void
    {
        $query=http_build_query(['supportsAllDrives'=>'true','fields'=>'id,trashed']);
        $this->request('PATCH','https://www.googleapis.com/drive/v3/files/'.rawurlencode($fileId).'?'.$query,'{"trashed":true}','application/json');
    }

    /** @return array<string,mixed> */
    private function request(string $method,string $url,?string $body=null,?string $contentType=null):array
    {
        $headers=['Authorization: Bearer '.$this->tokens->accessToken()];if($contentType)$headers[]='Content-Type: '.$contentType;
        $curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>90,CURLOPT_HTTPHEADER=>$headers]);if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,$body);
        $response=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        if($response===false)throw new \RuntimeException('Error de red Drive: '.curl_error($curl));
        $decoded=json_decode($response,true,flags:JSON_THROW_ON_ERROR);
        if($status<200||$status>=300)throw new \RuntimeException('Drive respondió HTTP '.$status.': '.($decoded['error']['message']??'error desconocido'));
        return$decoded;
    }
}
