<?php
namespace ASCLA\Core\Domain;
use ASCLA\Core\Services\Access;

/** Parses Zoom participant exports without evaluating cells or guessing identities by name. */
final class ZoomCsv
{
    private const COLUMNS=[
        'email'=>['email','useremail','emailaddress','correo','correoelectronico','correoelectronicodelusuario'],
        'minutes'=>['durationminutes','durationmins','durationmin','duration','duracionminutos','duracionmin','duracion','totaldurationminutes','totaldurationmins','tiempoenlasesionminutos'],
        'name'=>['name','nameoriginalname','username','nombre','nombreoriginal','nombreusuario','nombreoriginalnombre'],
        'join'=>['jointime','jointimestamp','horadeentrada','horadeingreso','horadeunion'],
        'leave'=>['leavetime','leavetimestamp','horadesalida'],
    ];
    private static function key(?string $value): string
    {
        return preg_replace('/[^a-z0-9]/','',strtolower(remove_accents(ltrim((string)$value,"\xEF\xBB\xBF"))));
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
                if (!array_intersect($keys,self::COLUMNS['email'])) { continue; }
                Access::require(count($keys)<=100,'Cabecera CSV no válida.',400);
                return [self::columnMap($keys),$separator];
            }
        }
        return [null,','];
    }
    private static function columnMap(array $keys): array
    {
        $header=['columns'=>count($keys)];
        foreach(self::COLUMNS as $field=>$aliases) {
            $positions=array_keys(array_filter($keys,static fn($key)=>in_array($key,$aliases,true)));
            Access::require(count($positions)<=1,'La cabecera CSV contiene columnas repetidas para el mismo dato.',400);
            $header[$field]=$positions[0]??false;
        }
        return $header;
    }
    private static function participantRow(array $cells,array $header,int $line): array
    {
        $email=strtolower(trim((string)($cells[$header['email']]??'')));
        $duration=$header['minutes']===false?'':trim((string)($cells[$header['minutes']]??''));
        $minutes=null;$seconds=null;
        if ($duration!=='') {
            $value=preg_match('/^\d+(?:[.,]\d+)?$/D',$duration)?(float)str_replace(',','.',$duration):-1;
            if (!is_finite($value) || $value<0 || $value>10080) { $minutes=-1; }
            else { $minutes=(int)ceil($value);$seconds=(int)round($value*60); }
        }
        $reason='';
        if (!is_email($email)) { $reason='Correo ausente o no válido'; }
        elseif ($minutes!==null && ($minutes<0 || $minutes>10080)) { $reason='Duración no válida'; }
        return [
            'signature'=>hash('sha256',wp_json_encode($cells)),'line'=>$line,'email'=>$email,
            'name'=>mb_substr(sanitize_text_field((string)($header['name']===false?'':($cells[$header['name']]??''))),0,160),
            'minutes'=>$minutes,'seconds'=>$seconds,
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
            Access::require(count($cells)===$header['columns'],sprintf(\ASCLA\Core\Frontend\Language::label('La fila %d no coincide con las columnas de la cabecera.'),$line),400);
            $rows[]=self::participantRow($cells,$header,$line);
        }
        return $rows;
    }
    public static function parse(string $csv): array
    {
        Access::require(strlen($csv)>0 && strlen($csv)<=524288,'El CSV debe ocupar como máximo 512 KB.',400);
        Access::require(!str_contains($csv,"\0") && mb_check_encoding($csv,'UTF-8'),'Guarda el CSV con codificación UTF-8.',400);
        $stream=fopen('php://temp','r+');fwrite($stream,$csv);rewind($stream);$line=0;
        try {
            [$header,$delimiter]=self::participantHeader($stream,$line);
            Access::require($header!==null,'No se encontró la columna de correo del participante en el CSV.',400);
            $rows=self::participantRows($stream,$header,$delimiter,$line);
            Access::require(!empty($rows),'El CSV no contiene participantes.',400);
            return $rows;
        } finally { fclose($stream); }
    }

}
