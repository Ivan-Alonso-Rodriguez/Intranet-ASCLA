<?php
namespace ASCLA\Core\Domain;
use ASCLA\Core\Services\Access;
final class InterestCatalog
{
    private const GROUPS=[
        ['Estándares de Gobernanza Corporativa','Estándares de gobierno corporativo'],
        ['Composición / Diversidad del Directorio','Composición y diversidad del directorio'],
        ['Desempeño del Directorio y los Directores','Desempeño del directorio'],
        ['Comités de Directorio','Comites del directorio'],
        ['Gobernanza de las empresas familiares','Gobierno de empresas familiares'],
        ['Sostenibilidad','ESG, Cambio Climático, Estándares de Sostenibilidad','ESG','ASG','Cambio climático'],
        ['Compliance','Cumplimiento normativo'],
        ['Secretaría corporativa','Secretario Corporativo: roles, funciones, competencias, prácticas'],
        ['Gobernanza de la IA','gobernanza ia','Gobierno de IA'],
        ['Gestión de riesgos','Gestión de riesgos y Gobernanza','Risk management'],
        ['Transformación Digital; Ciberseguridad','Transformación Digital y Ciberseguridad'],
        ['Ciberseguridad','Cyber Security','Cybersecurity','Seguridad informática'],
        ['Empresas y Geopolítica','Geopolítica'],['Ética en los negocios','Ética empresarial'],
        ['Gestión de riesgo reputacional','Riesgo reputacional'],['Auditoría y control interno','Control interno']
    ];
    public static function fold(string $text): string{return trim(preg_replace('/\s+/u',' ',mb_strtolower(remove_accents($text))));}
    public static function terms(): array
    {
        $terms=get_terms(['taxonomy'=>'ascla_interest','hide_empty'=>false]);return is_wp_error($terms)?[]:array_map(static fn($t)=>['id'=>(int)$t->term_id,'name'=>$t->name],$terms);
    }
    private static function aliases(array $terms): array
    {
        $aliases=[];foreach($terms as $t)$aliases[self::fold($t['name'])]=(int)$t['id'];
        foreach(self::GROUPS as $group){$id=0;foreach($group as $name)if(isset($aliases[self::fold($name)])){$id=$aliases[self::fold($name)];break;}if($id)foreach($group as $name)$aliases[self::fold($name)]=$id;}
        uksort($aliases,static fn($a,$b)=>mb_strlen($b)<=>mb_strlen($a));return $aliases;
    }
    public static function propose(string $text): array
    {
        $aliases=self::aliases(self::terms());$rest=self::fold(str_replace(["\r\n","\r","\n"],';',$text));$items=[];$unknown=[];$ids=[];
        while($rest!=='') {
            $found=false;
            foreach($aliases as $label=>$id)if(preg_match('/^'.preg_quote($label,'/').'(?:\s*[,;|\n]\s*|$)/u',$rest,$match)){
                $items[]=['original'=>$label,'topic_id'=>$id,'method'=>'deterministic','confidence'=>'high'];$ids[]=$id;$rest=ltrim(substr($rest,strlen($match[0])));$found=true;break;
            }
            if(!$found){$parts=preg_split('/\s*[,;|\n]\s*/u',$rest,2);$unknown[]=$parts[0];$items[]=['original'=>$parts[0],'topic_id'=>0,'method'=>'manual','confidence'=>'unknown'];$rest=$parts[1]??'';}
        }
        return ['ids'=>array_values(array_unique($ids)),'items'=>$items,'unknown'=>$unknown,'selected_count'=>count($items)];
    }
    public static function validate(array $ids): array
    {
        Access::require(count($ids)<=20,'Selecciona como máximo 20 intereses.',400);$allowed=array_column(self::terms(),'id');$out=[];
        foreach($ids as $id){Access::require((is_int($id)||(is_scalar($id) && ctype_digit((string)$id))) && in_array((int)$id,$allowed,true),'Interés fuera del catálogo.',400);$out[]=(int)$id;}
        return array_values(array_unique($out));
    }
    public static function seed(): array
    {
        Access::require(Access::member() && current_user_can('ascla_manage'));
        foreach(self::GROUPS as $group)self::create($group[0]);return self::terms();
    }
    public static function create(string $name): array
    {
        Access::require(Access::member() && current_user_can('ascla_manage'));$name=trim(Access::text($name,100));Access::require($name!=='','Escribe el nombre del tema.',400);
        $aliases=self::aliases(self::terms());$key=self::fold($name);if(isset($aliases[$key])){$t=get_term($aliases[$key],'ascla_interest');return ['id'=>(int)$t->term_id,'name'=>$t->name,'existing'=>true];}
        $t=wp_insert_term($name,'ascla_interest');Access::require(!is_wp_error($t),'No se pudo crear el tema.',400);return ['id'=>(int)$t['term_id'],'name'=>$name,'existing'=>false];
    }
}
