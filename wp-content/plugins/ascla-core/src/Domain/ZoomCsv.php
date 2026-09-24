<?php
namespace ASCLA\Core\Domain;
use ASCLA\Core\Services\Access;

/** Parses Zoom participant exports without evaluating cells or guessing identities by name. */
final class ZoomCsv
{
    private static function key(string $value): string
    {
        return preg_replace('/[^a-z0-9]/','',strtolower(remove_accents(ltrim($value,"\xEF\xBB\xBF"))));
    }
    public static function input(mixed $value): string
    {
        Access::require(is_string($value),'CSV no válido.',400);
        return $value;
    }
    private static function participantHeader($stream,int &$line): array
    {
        while (($raw=fgets($stream))!==false && ++$line<=30) {
            foreach ([',',';',"\t"] as $separator) {
                $cells=str_getcsv($raw,$separator,'"','');$keys=array_map([self::class,'key'],$cells);
                $email=array_search(true,array_map(static fn($key)=>in_array($key,['email','useremail','emailaddress','correo','correoelectronico','correoelectronicodelusuario'],true),$keys),true);
                if ($email===false) { continue; }
                $duration=array_search(true,array_map(static fn($key)=>in_array($key,['durationminutes','durationmins','durationmin','duration','duracionminutos','duracionmin','duracion','totaldurationminutes','totaldurationmins','tiempoenlasesionminutos'],true),$keys),true);
                $name=array_search(true,array_map(static fn($key)=>in_array($key,['name','nameoriginalname','username','nombre','nombreoriginal','nombreusuario','nombreoriginalnombre'],true),$keys),true);
                $join=array_search(true,array_map(static fn($key)=>in_array($key,['jointime','jointimestamp','horadeentrada','horadeingreso','horadeunion'],true),$keys),true);
                $leave=array_search(true,array_map(static fn($key)=>in_array($key,['leavetime','leavetimestamp','horadesalida'],true),$keys),true);
                return [['email'=>$email,'minutes'=>$duration,'name'=>$name,'join'=>$join,'leave'=>$leave],$separator];
            }
        }
        return [null,','];
    }
    private static function participantRow(array $cells,array $header,int $line): array
    {
        $email=strtolower(trim((string)($cells[$header['email']]??'')));
        $duration=$header['minutes']===false?'':trim((string)($cells[$header['minutes']]??''));
        $minutes=null;
        if ($duration!=='') { $minutes=preg_match('/^\d+(?:[.,]\d+)?$/D',$duration)?(int)ceil((float)str_replace(',','.',$duration)):-1; }
        $reason='';
        if (!is_email($email)) { $reason='Correo ausente o no válido'; }
        elseif ($minutes!==null && ($minutes<0 || $minutes>10080)) { $reason='Duración no válida'; }
        return [
            'signature'=>hash('sha256',wp_json_encode($cells)),'line'=>$line,'email'=>$email,
            'name'=>mb_substr(sanitize_text_field((string)($header['name']===false?'':($cells[$header['name']]??''))),0,160),
            'minutes'=>$minutes,'seconds'=>$minutes===null?null:(int)round((float)str_replace(',','.',$duration)*60),
            'join'=>$header['join']===false?'':trim((string)($cells[$header['join']]??'')),
            'leave'=>$header['leave']===false?'':trim((string)($cells[$header['leave']]??'')),'error'=>$reason,
        ];
    }
    private static function participantRows($stream,array $header,string $delimiter,int &$line): array
    {
        $rows=[];
        while (($cells=fgetcsv($stream,0,$delimiter,'"',''))!==false) {
            ++$line;
            if (count($cells)===1 && trim((string)$cells[0])==='') { continue; }
            Access::require(count($rows)<5000,'El CSV admite hasta 5000 filas de participantes.',400);
            $rows[]=self::participantRow($cells,$header,$line);
        }
        return $rows;
    }
    public static function parse(string $csv): array
    {
        Access::require(strlen($csv)>0 && strlen($csv)<=524288,'El CSV debe ocupar como máximo 512 KB.',400);
        Access::require(!str_contains($csv,"\0") && mb_check_encoding($csv,'UTF-8'),'Guarda el CSV con codificación UTF-8.',400);
        $stream=fopen('php://temp','r+');fwrite($stream,$csv);rewind($stream);$line=0;
        [$header,$delimiter]=self::participantHeader($stream,$line);
        try {
            Access::require($header!==null,'No se encontró la columna de correo del participante en el CSV.',400);
            $rows=self::participantRows($stream,$header,$delimiter,$line);
            Access::require(!empty($rows),'El CSV no contiene participantes.',400);
            return $rows;
        } finally { fclose($stream); }
    }

}
