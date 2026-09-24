<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Frontend\Language;

/** Small presentation helpers used by notification targets. */
final class NotificationCopy
{
    private static function tr(string $es,string $en): string{return Language::text($es,$en);}

    public static function postTitle(string $kind,string $actor,bool $chatham,string $default): string
    {
        $mention=$chatham
            ?self::tr('Te mencionaron en una conversación privada','You were mentioned in a private conversation')
            :self::tr($actor.' te mencionó en el Hub',$actor.' mentioned you in the Hub');
        return match($kind) {
            'comment'=>Language::english()?$actor.' commented on your post':$actor.' comentó en tu publicación',
            'comment_reply'=>Language::english()?$actor.' replied to your comment':$actor.' respondió a tu comentario',
            'reaction'=>Language::english()?$actor.' reacted to your post':$actor.' reaccionó a tu publicación',
            'mention'=>$mention,
            'resource'=>self::tr('Un nuevo recurso para tus intereses','A new resource for your interests'),
            'event'=>self::tr('Tienes una invitación a un evento','You have an event invitation'),
            'event_waitlist_available'=>self::tr('Se liberó un cupo para ti','A spot is available for you'),
            'event_cancelled'=>self::tr('Un evento fue cancelado','An event was cancelled'),
            'event_updated'=>self::tr('Un evento cambió información importante','An event has important updates'),
            'microevent'=>self::tr('Tu círculo ASCLA te espera','Your ASCLA circle is waiting'),
            default=>$default,
        };
    }

    public static function postDescription(\WP_Post $post,string $kind,array $context): string
    {
        $description=Access::excerpt($post->post_title,180);
        if ($kind!=='event_updated') { return $description; }
        $changes=is_array($context['changes']??null)?$context['changes']:[];
        if ($changes) {
            $labelsEs=['title'=>'nombre','start'=>'inicio','end'=>'finalización','modality'=>'modalidad','location'=>'ubicación','url'=>'enlace','capacity'=>'aforo'];
            $labelsEn=['title'=>'name','start'=>'start time','end'=>'end time','modality'=>'format','location'=>'location','url'=>'meeting link','capacity'=>'capacity'];
            $labels=Language::english()?$labelsEn:$labelsEs;
            $visible=array_values(array_filter(array_map(static fn($key)=>$labels[$key]??'',array_slice($changes,0,4))));
            if ($visible) {
                $prefix=Language::english()?'Updated: ':'Cambios: ';
                $description=Access::excerpt($post->post_title.' · '.$prefix.implode(', ',$visible).(count($changes)>4?'…':''),180);
            }
        } elseif (!empty($context['note'])) {
            $description=Access::excerpt($post->post_title.' · '.$context['note'],180);
        }
        return $description;
    }

    public static function postActionLabel(string $postType,string $kind): string
    {
        if ($postType==='ascla_event') {
            return match($kind) {
                'event_waitlist_available'=>self::tr('Confirmar cupo','Confirm spot'),
                'event_cancelled'=>self::tr('Ver evento cancelado','View cancelled event'),
                'event_updated'=>self::tr('Revisar cambios','Review updates'),
                default=>self::tr('Ver encuentro e invitación','View event and invitation'),
            };
        }
        return $postType==='ascla_resource'?self::tr('Abrir recurso','Open resource'):self::tr('Ver publicación','View post');
    }
}
