<?php
namespace ASCLA\Core\Domain;

/** Select playable references to source timestamps; never create or download media files. */
final class Transcript
{
    public static function videoLinks(array $clips,string $videoId): array
    {
        $valid=(bool)preg_match('/^[A-Za-z0-9_-]{11}$/D',$videoId);
        return array_map(static function(array $clip)use($valid,$videoId):array{
            $start=max(0,(int)($clip['start']??0));
            $clip['youtube_url']=$valid?'https://www.youtube.com/watch?v='.$videoId.'&t='.$start.'s':'';
            return $clip;
        },array_values(array_filter($clips,'is_array')));
    }
    private static function seconds(string $value): float
    {
        $parts=explode(':',str_replace(',','.',$value));
        if (count($parts)===2) { array_unshift($parts,'0'); }
        if (count($parts)!==3 || (int)$parts[1]>59 || (float)$parts[2]>=60) { return -1; }
        return (int)$parts[0]*3600+(int)$parts[1]*60+(float)$parts[2];
    }
    public static function segments(string $text): array
    {
        $text=str_replace("\r",'', $text); $segments=[];
        $stamp='\d{1,3}:\d{2}(?::\d{2})?(?:[.,]\d{1,3})?';
        preg_match_all('/^('.$stamp.')\s+-->\s+('.$stamp.')[^\n]*\n(.*?)(?=\n\s*\n|\z)/msu',$text,$cues,PREG_SET_ORDER);
        foreach ($cues as $cue) {
            $start=self::seconds($cue[1]); $end=self::seconds($cue[2]);
            if ($start>=0 && $end>$start) { $segments[]=['start'=>$start,'end'=>$end,'text'=>trim(strip_tags($cue[3]))]; }
        }
        if (!$segments) {
            preg_match_all('/^\[('.$stamp.')\]\s*(.*)$/mu',$text,$markers,PREG_SET_ORDER);
            for ($i=0;$i<count($markers)-1;$i++) {
                $start=self::seconds($markers[$i][1]); $end=self::seconds($markers[$i+1][1]);
                if ($start>=0 && $end>$start) { $segments[]=['start'=>$start,'end'=>$end,'text'=>trim(strip_tags($markers[$i][2]))]; }
            }
        }
        usort($segments,static fn($a,$b)=>$a['start']<=>$b['start']);
        return $segments;
    }
    public static function duration(string $text): int
    {
        $segments=self::segments($text);
        return $segments?(int)ceil(max(array_column($segments,'end'))):0;
    }
    /** A short video is already capsule-sized; longer material gets only a few useful excerpts. */
    public static function capsuleLimit(int $durationSeconds): int
    {
        return match(true) {
            $durationSeconds<=180=>0,
            $durationSeconds<=600=>1,
            $durationSeconds<=1800=>2,
            default=>3,
        };
    }
    private static function clampSegments(array $segments,int $durationSeconds): array
    {
        if ($durationSeconds<=0) { return $segments; }
        $safe=[];
        foreach ($segments as $segment) {
            if ($segment['start'] >= $durationSeconds) { continue; }
            $segment['end']=min((float)$segment['end'],(float)$durationSeconds);
            if ($segment['end']>$segment['start']) { $safe[]=$segment; }
        }
        return $safe;
    }
    private static function candidate(array $segments,int $start,int $end,string $method): ?array
    {
        $selected=array_values(array_filter($segments,static fn($s)=>$s['start']>=$start && $s['end']<=$end+1));
        if ($end-$start<60 || $end-$start>180 || !$selected || abs($selected[0]['start']-$start)>1 || abs(end($selected)['end']-$end)>1) { return null; }
        $text=self::continuousText($selected,$start);
        if ($text===null) { return null; }
        preg_match_all('/gobierno|riesgo|responsabil|conclusi|recomenda|acuerdo|directorio|supervisi/iu',$text,$hits);
        return ['start'=>$start,'end'=>$end,'title'=>mb_substr(trim(preg_replace('/\s+/u',' ',$text)),0,100),'selection'=>$method,'score'=>count($hits[0])];
    }
    private static function continuousText(array $segments,int $start): ?string
    {
        $covered=$start; $text='';
        foreach ($segments as $segment) {
            if ($segment['start']-$covered>15) { return null; }
            $covered=max($covered,$segment['end']); $text.=' '.$segment['text'];
        }
        return $text;
    }
    private static function suggestedCandidates(array $segments,array $suggestions,int $durationSeconds): array
    {
        $candidates=[];
        foreach (array_slice($suggestions,0,20) as $suggestion) {
            if (!is_array($suggestion) || !is_numeric($suggestion['start']??null) || !is_numeric($suggestion['end']??null)) { continue; }
            $start=max(0,(int)$suggestion['start']); $end=(int)$suggestion['end'];
            if ($durationSeconds>0 && $end>$durationSeconds) { continue; }
            $candidate=self::candidate($segments,$start,$end,'Sugerencia IA con tiempos verificados');
            if ($candidate) { $candidate['score']+=1000; $candidates[]=$candidate; }
        }
        return $candidates;
    }
    private static function windowCandidate(array $segments,int $index): ?array
    {
        $start=(int)floor($segments[$index]['start']);
        for ($j=$index;$j<count($segments);$j++) {
            $end=(int)floor($segments[$j]['end']);
            if ($end-$start>180) { break; }
            if ($end-$start>=60) {
                return self::candidate(array_slice($segments,$index,$j-$index+1),$start,$end,'Selección por contenido y tiempos');
            }
        }
        return null;
    }
    private static function contentCandidates(array $segments): array
    {
        $candidates=[];
        foreach (array_keys($segments) as $index) {
            $candidate=self::windowCandidate($segments,$index);
            if ($candidate) { $candidates[]=$candidate; }
        }
        return $candidates;
    }
    private static function overlaps(array $candidate,array $selected): bool
    {
        foreach ($selected as $existing) {
            if ($candidate['start']<$existing['end'] && $candidate['end']>$existing['start']) { return true; }
        }
        return false;
    }
    private static function selectMoments(array $candidates,int $durationSeconds,int $limit): array
    {
        usort($candidates,static fn($a,$b)=>($b['score']<=>$a['score'])?:($a['start']<=>$b['start']));
        $selected=[];
        foreach ($candidates as $candidate) {
            if ($durationSeconds>0 && $candidate['end']>$durationSeconds) { continue; }
            if (!self::overlaps($candidate,$selected)) { unset($candidate['score']); $selected[]=$candidate; }
            if (count($selected)>=$limit) { break; }
        }
        usort($selected,static fn($a,$b)=>$a['start']<=>$b['start']);
        return $selected;
    }
    public static function moments(string $text,array $suggestions=[],int $durationSeconds=0,?int $limit=null): array
    {
        $segments=self::segments($text);
        if (!$segments) { return []; }
        $durationProvided=$durationSeconds>0;
        $durationSeconds=$durationProvided?$durationSeconds:self::duration($text);
        $limit=$limit??($durationProvided?self::capsuleLimit($durationSeconds):8);
        $segments=$limit>0?self::clampSegments($segments,$durationSeconds):[];
        if (!$segments) { return []; }
        $candidates=array_merge(self::suggestedCandidates($segments,$suggestions,$durationSeconds),self::contentCandidates($segments));
        return self::selectMoments($candidates,$durationSeconds,$limit);
    }
}
