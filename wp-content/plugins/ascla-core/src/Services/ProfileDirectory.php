<?php
namespace ASCLA\Core\Services;

/** Search and ranking use only the attributes exposed in directory results. */
final class ProfileDirectory
{
    public static function search(array $filter=[]): array
    {
        $page=max(1,(int)($filter['page']??1));$perPage=Settings::get()['directory_page_size'];
        $query=self::normalize(Access::text($filter['q']??'',120));$results=[];$offset=0;
        do {
            $users=get_users(['number'=>200,'offset'=>$offset,'orderby'=>'display_name','order'=>'ASC','capability'=>'ascla_access']);$offset+=200;
            foreach ($users as $user) {
                $data=self::candidate((int)$user->ID,$filter,$query);
                if ($data!==null) { $results[]=$data; }
            }
        } while (count($users)===200);
        usort($results,static function(array $a,array $b): int {
            foreach (['text','filters','affinity'] as $key) {
                $comparison=$b['_rank'][$key]<=>$a['_rank'][$key];if ($comparison) { return $comparison; }
            }
            return strcmp(self::normalize($a['name']),self::normalize($b['name'])) ?: $a['id']<=>$b['id'];
        });
        $total=count($results);$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);
        $items=array_slice($results,($page-1)*$perPage,$perPage);
        foreach ($items as &$item) { unset($item['_rank']); } unset($item);
        return ['items'=>Connections::attach($items),'total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>$perPage];
    }
    private static function candidate(int $id,array $filter,string $query): ?array
    {
        if (!self::eligibleCandidate($id)) { return null; }
        $data=self::candidateData($id);
        $rank=self::candidateRank($id,$data,$filter,$query);
        if ($rank===null) { return null; }
        $data['_rank']=$rank;
        return $data;
    }

    private static function eligibleCandidate(int $id): bool
    {
        if (!Access::member($id) || Messaging::blocked(get_current_user_id(),$id)) { return false; }
        $raw=Profiles::raw($id);
        return !empty($raw['directory']) && !ProfileRequirements::missing($raw);
    }

    private static function candidateData(int $id): array
    {
        $raw=Profiles::raw($id);
        $data=Profiles::visible($id);
        // Administrators can inspect private data on the individual administrative form,
        // but a directory search must not infer it from membership in a result set.
        foreach ((array)($raw['hidden']??[]) as $field) { unset($data[$field]); }
        if (in_array('photo_id',(array)($raw['hidden']??[]),true)) { $data['photo_url']=''; }
        unset($data['phone'],$data['birth_date'],$data['email'],$data['phone_visibility']);
        $data['name']=Profiles::publicName($id);$data['terms']=Profiles::labels($data);
        $data['position_label']=($data['position']??'')?:\ASCLA\Core\Frontend\Language::label('Miembro ASCLA');
        return $data;
    }

    private static function candidateRank(int $id,array $data,array $filter,string $query): ?array
    {
        $haystack=self::normalize(implode(' ',array_map(static fn($v)=>is_scalar($v)?(string)$v:'',$data)).' '.wp_json_encode($data['terms'],JSON_UNESCAPED_UNICODE));
        $countryMatches=empty($filter['country']) || self::normalize($data['country']??'')===self::normalize((string)$filter['country']);
        if (($query!=='' && !str_contains($haystack,$query)) || !$countryMatches) { return null; }
        $relevance=self::filterRelevance($data,$filter);
        if ($relevance===null) { return null; }
        return ['text'=>self::textRank($data,$query),'filters'=>$relevance,'affinity'=>self::affinity($id)];
    }

    private static function filterRelevance(array $data,array $filter): ?int
    {
        $relevance=0;
        foreach (['industries','interests','areas'] as $key) {
            $selected=array_values(array_unique(array_filter(array_map('absint',(array)($filter[$key]??[])))));
            if (!$selected) { continue; }
            $matches=count(array_intersect($selected,$data[$key]??[]));
            if (!$matches) { return null; }
            $relevance+=$matches;
        }
        return $relevance;
    }

    private static function affinity(int $id): int
    {
        if (get_current_user_id()===$id) { return 0; }
        try { return (int)Matching::between(get_current_user_id(),$id,false,false)['score']; }
        catch (\ASCLA\Core\Rest\ApiException) { return 0; }
    }

    private static function textRank(array $data,string $query): int
    {
        if ($query==='') { return 0; }
        $name=self::normalize($data['name']);$position=self::normalize($data['position_label']);
        return match(true) {
            $name===$query=>4,
            $position===$query=>3,
            str_starts_with($name,$query)=>2,
            str_contains($name,$query)||str_contains($position,$query)=>1,
            default=>0,
        };
    }
    private static function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u',' ',remove_accents($value))));
    }
}
