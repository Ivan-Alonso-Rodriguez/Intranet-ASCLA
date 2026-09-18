<?php
namespace ASCLA\Core\Domain;
use ASCLA\Core\Services\Access;
/** Small dependency-free CSV reader. File content is data; spreadsheet formulas are never evaluated. */
final class DelimitedFile
{
    public static function read(mixed $csv,string $filename='',string $mime='text/csv'): array
    {
        Access::require(is_string($csv) && strlen($csv)>0 && strlen($csv)<=524288,'El CSV debe ocupar como máximo 512 KB.',400);
        Access::require($filename==='' || strtolower(pathinfo($filename,PATHINFO_EXTENSION))==='csv','Exporta el archivo como CSV UTF-8.',400);
        Access::require(in_array(strtolower(trim(explode(';',$mime)[0])),['','text/csv','text/plain','application/csv','application/vnd.ms-excel','application/octet-stream'],true),'Tipo de archivo CSV no válido.',400);
        Access::require(!str_contains($csv,"\0") && mb_check_encoding($csv,'UTF-8'),'Guarda el CSV con codificación UTF-8.',400);
        $f=fopen('php://temp','r+');fwrite($f,preg_replace('/^\xEF\xBB\xBF/','',$csv));rewind($f);
        try {
            $first=fgets($f);$delimiter=',';$size=0;foreach([',',';',"\t"] as $d){$n=count(str_getcsv($first,$d,'"',''));if($n>$size){$size=$n;$delimiter=$d;}}
            rewind($f);$headers=fgetcsv($f,0,$delimiter,'"','');Access::require(is_array($headers) && count($headers)<=100,'Cabecera CSV no válida.',400);
            $headers=array_map(static fn($v)=>Access::text($v,500),$headers);$rows=[];$line=1;
            while(($row=fgetcsv($f,0,$delimiter,'"',''))!==false){++$line;if(count($row)===1 && trim((string)$row[0])==='')continue;Access::require(count($rows)<5000,'El CSV admite hasta 5000 filas.',400);Access::require(count($row)===count($headers),'La fila '.$line.' no coincide con las columnas de la cabecera.',400);$rows[]=['line'=>$line,'values'=>$row];}
            Access::require((bool)$rows,'El CSV no contiene filas.',400);return ['headers'=>$headers,'rows'=>$rows];
        } finally {fclose($f);}
    }
}
