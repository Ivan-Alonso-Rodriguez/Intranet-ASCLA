<?php
namespace ASCLA\Core\Services;

/** Request-local identity index: exact email first, then unique normalized names. */
final class AttendanceIdentity
{
    private array $emails=[],$names=[],$resolved=[];
    private bool $loaded=false;

    public function resolve(array $row): array
    {
        if ($row['error']!=='') { return ['user'=>false,'reason'=>$row['error']]; }
        $email=$row['email'];
        if (is_email($email)) {
            if (!array_key_exists($email,$this->emails)) { $this->emails[$email]=get_user_by('email',$email); }
            $user=$this->emails[$email];
            if ($user) { return user_can($user,'ascla_access')?['user'=>$user,'method'=>'email']:['user'=>false,'reason'=>'El correo pertenece a una cuenta sin acceso ASCLA']; }
        }
        $name=self::normalize($row['name']);
        if ($name==='') { return ['user'=>false,'reason'=>'Correo sin cuenta ASCLA y nombre no disponible']; }
        if (!isset($this->resolved[$name])) { $this->resolved[$name]=$this->byName($name); }
        return $this->resolved[$name];
    }

    private static function normalize(string $value): string
    {
        $value=mb_strtolower(remove_accents($value));
        $value=trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$value));
        $words=explode(' ',$value);sort($words,SORT_STRING);
        return implode(' ',$words);
    }

    private function load(): void
    {
        if ($this->loaded) { return; }
        $page=1;
        do {
            $users=get_users(['capability'=>'ascla_access','number'=>200,'paged'=>$page++,'orderby'=>'ID','order'=>'ASC']);
            update_meta_cache('user',array_map(static fn($user)=>(int)$user->ID,$users));
            foreach ($users as $user) {
                if (!user_can($user,'ascla_access')) { continue; }
                $profile=(array)get_user_meta($user->ID,'_ascla_profile',true);
                $aliases=[$user->display_name,$user->first_name.' '.$user->last_name,($profile['first_name']??'').' '.($profile['last_name']??''),$user->user_login];
                $this->names[(int)$user->ID]=['user'=>$user,'names'=>array_values(array_unique(array_filter(array_map([self::class,'normalize'],$aliases))))];
            }
        } while (count($users)===200);
        $this->loaded=true;
    }

    private function byName(string $name): array
    {
        $this->load();$exact=[];$scores=[];
        foreach ($this->names as $id=>$member) {
            if (in_array($name,$member['names'],true)) { $exact[]=$id; }
            if (strlen($name)<8 || !str_contains($name,' ')) { continue; }
            foreach ($member['names'] as $alias) {
                $scores[$id]=max($scores[$id]??0,self::similarity($name,$alias));
            }
        }
        if (count($exact)===1) { return ['user'=>$this->names[$exact[0]]['user'],'method'=>'name']; }
        if (count($exact)>1) { return ['user'=>false,'reason'=>'Hay varios asociados con ese nombre. Verifica su correo.']; }
        arsort($scores,SORT_NUMERIC);$ids=array_keys($scores);$best=$scores[$ids[0]??0]??0;$second=$scores[$ids[1]??0]??0;
        if ($best>=0.90 && $best-$second>=0.08) { return ['user'=>$this->names[$ids[0]]['user'],'method'=>'similar_name']; }
        return ['user'=>false,'reason'=>$best>=0.80?'El nombre tiene coincidencias dudosas. Verifica su correo.':'No se encontró una cuenta ASCLA con ese correo o nombre'];
    }

    private static function similarity(string $name,string $alias): float
    {
        $length=max(strlen($name),strlen($alias));
        if (!str_contains($alias,' ') || abs(strlen($name)-strlen($alias))>$length*0.1) { return 0; }
        return 1-levenshtein($name,$alias)/max(1,$length);
    }
}
