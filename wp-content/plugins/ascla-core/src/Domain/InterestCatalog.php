<?php
namespace ASCLA\Core\Domain;
use ASCLA\Core\Repositories\{Store,WordPressWrites};
use ASCLA\Core\Services\{Access,Audit};
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
        $aliases=[];foreach($terms as $t) {$aliases[self::fold($t['name'])]=(int)$t['id']; }
        foreach(self::GROUPS as $group){$id=0;foreach($group as $name) {if(isset($aliases[self::fold($name)])){$id=$aliases[self::fold($name)];break;} }if($id) {foreach($group as $name) {$aliases[self::fold($name)]=$id; } }}
        uksort($aliases,static fn($a,$b)=>mb_strlen($b)<=>mb_strlen($a));return $aliases;
    }
    public static function propose(string $text): array
    {
        $aliases=self::aliases(self::terms());$rest=self::fold(str_replace(["\r\n","\r","\n"],';',$text));$items=[];$unknown=[];$ids=[];
        while($rest!=='') {
            $found=false;
            foreach($aliases as $label=>$id) {
                if(preg_match('/^'.preg_quote($label,'/').'(?:\s*[,;|\n]\s*|$)/u',$rest,$match)){
                    $items[]=['original'=>$label,'topic_id'=>$id,'method'=>'deterministic','confidence'=>'high'];$ids[]=$id;$rest=ltrim(substr($rest,strlen($match[0])));$found=true;break;
                }
            }
            if(!$found){$parts=preg_split('/\s*[,;|\n]\s*/u',$rest,2);$unknown[]=$parts[0];$items[]=['original'=>$parts[0],'topic_id'=>0,'method'=>'manual','confidence'=>'unknown'];$rest=$parts[1]??'';}
        }
        return ['ids'=>array_values(array_unique($ids)),'items'=>$items,'unknown'=>$unknown,'selected_count'=>count($items)];
    }
    public static function validate(array $ids): array
    {
        Access::require(count($ids)<=20,'Selecciona como máximo 20 intereses.',400);$ids=\ASCLA\Core\Services\FormRules::ids($ids);$allowed=array_column(self::terms(),'id');$out=[];
        foreach($ids as $id){Access::require(in_array($id,$allowed,true),'Interés fuera del catálogo.',400);$out[]=$id;}
        return array_values(array_unique($out));
    }
    public static function activeIds(array $ids): array
    {
        return array_values(array_filter(array_unique(array_map('intval',$ids)),
            static fn(int $id): bool => get_term($id,'ascla_interest') instanceof \WP_Term));
    }
    public static function seed(): array
    {
        Access::require(Access::member() && current_user_can('ascla_manage'));
        foreach(self::GROUPS as $group) {self::create($group[0]); }return self::terms();
    }
    public static function create(string $name): array
    {
        Access::require(Access::member() && current_user_can('ascla_manage'));
        $name=trim(Access::text($name,100));
        Access::require(Access::hasText($name),'Escribe el nombre del tema.',400);
        return Store::atomic('interest-catalog',static function() use ($name) {
            $aliases=self::aliases(self::terms());
            $existing=$aliases[self::fold($name)]??0;
            if ($existing) {
                $term=get_term($existing,'ascla_interest');
                return ['id'=>(int)$term->term_id,'name'=>$term->name,'existing'=>true];
            }
            $term=wp_insert_term($name,'ascla_interest');
            Access::require(!is_wp_error($term),'No se pudo crear el tema.',400);
            Audit::record('interest_topic_created',(int)$term['term_id'],$name);
            return ['id'=>(int)$term['term_id'],'name'=>$name,'existing'=>false];
        });
    }

    public static function delete(int $id): array
    {
        Access::require(Access::member() && current_user_can('ascla_manage'));
        return Store::atomic('interest-catalog',static function() use ($id) {
            $term=get_term($id,'ascla_interest');
            Access::require($term instanceof \WP_Term,'Tema no encontrado.',404);
            Access::require(wp_delete_term($id,'ascla_interest')===true,'No se pudo eliminar el tema.',409);
            // Recalculate recommendations that depended on this topic in member profiles.
            WordPressWrites::bumpProfileRevision();
            Audit::record('interest_topic_deleted',$id,$term->name);
            return self::terms();
        });
    }
}
