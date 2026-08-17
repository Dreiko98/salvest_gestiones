<?php
declare(strict_types=1);

namespace Salvest;

final class DriveYearRollover
{
    private const CATEGORIES=['ELECTRICIDAD','AGUA','EXTINTORES','ASCENSOR','LIMPIEZA','JARDINERIA','PISCINA','DESCALCIFICADOR','MANTENIMIENTO'];

    public function __construct(private Database $db,private GoogleDriveClient $drive,private string $rootFolderId){}

    /** @return array{status:string,active_year:int,moved_files:int} */
    public function runIfNeeded(?int $currentYear=null):array
    {
        $currentYear??=(int)date('Y');
        $lock=$this->db->one("SELECT GET_LOCK('salvest-drive-year-rollover',0) acquired");
        if(!(int)($lock['acquired']??0))return['status'=>'already_running','active_year'=>$currentYear,'moved_files'=>0];
        try{
            $state=$this->db->one('SELECT active_year FROM drive_year_state WHERE id=1');
            if(!$state){
                $this->db->execute('INSERT INTO drive_year_state(id,active_year) VALUES (1,?)',[$currentYear]);
                return['status'=>'initialized','active_year'=>$currentYear,'moved_files'=>0];
            }
            $activeYear=(int)$state['active_year'];$moved=0;
            if($activeYear>=$currentYear)return['status'=>'not_required','active_year'=>$activeYear,'moved_files'=>0];
            while($activeYear<$currentYear){
                $moved+=$this->closeYear($activeYear);
                $activeYear++;
                $this->db->execute('UPDATE drive_year_state SET active_year=? WHERE id=1',[$activeYear]);
            }
            return['status'=>'completed','active_year'=>$activeYear,'moved_files'=>$moved];
        }finally{$this->db->one("SELECT RELEASE_LOCK('salvest-drive-year-rollover') released");}
    }

    private function closeYear(int $year):int
    {
        $this->db->execute("INSERT INTO drive_year_rollovers(year_closed,status,moved_files,started_at,completed_at,error_message) VALUES (?,'running',0,NOW(),NULL,NULL) ON DUPLICATE KEY UPDATE status='running',started_at=NOW(),completed_at=NULL,error_message=NULL",[$year]);
        $moved=0;
        try{
            foreach($this->db->all('SELECT external_code,official_name FROM communities WHERE active=1 ORDER BY external_code')as$community){
                $communityId=$this->folderId($this->rootFolderId,DriveInvoiceArchiver::communityFolderName($community));
                if($communityId===null)continue;
                $documentsId=$this->folderId($communityId,'Doc año en Vigor');
                if($documentsId===null)continue;
                foreach(self::CATEGORIES as$category){
                    $sourceId=$this->folderId($documentsId,$category);
                    if($sourceId===null)continue;
                    $files=array_values(array_filter($this->drive->children($sourceId),static fn(array$file):bool=>($file['mimeType']??'')==='application/pdf'));
                    if(!$files)continue;
                    $yearId=$this->drive->ensureFolder($documentsId,(string)$year);
                    $destinationId=$this->drive->ensureFolder($yearId,$category);
                    $destinationNames=array_map(static fn(array$file):string=>(string)$file['name'],$this->drive->children($destinationId));
                    foreach($files as$file){
                        $name=DriveInvoiceArchiver::availableFilename($destinationNames,(string)$file['name']);
                        $this->drive->move((string)$file['id'],$sourceId,$destinationId,$name);
                        $destinationNames[]=$name;$moved++;
                    }
                }
            }
            $this->db->execute("UPDATE drive_year_rollovers SET status='completed',moved_files=?,completed_at=NOW() WHERE year_closed=?",[$moved,$year]);
            return$moved;
        }catch(\Throwable$error){
            $this->db->execute("UPDATE drive_year_rollovers SET status='error',moved_files=?,error_message=? WHERE year_closed=?",[$moved,mb_substr($error->getMessage(),0,2000),$year]);
            throw$error;
        }
    }

    private function folderId(string $parentId,string $name):?string
    {
        foreach($this->drive->children($parentId)as$file)if(($file['name']??null)===$name&&($file['mimeType']??null)==='application/vnd.google-apps.folder')return(string)$file['id'];
        return null;
    }
}
