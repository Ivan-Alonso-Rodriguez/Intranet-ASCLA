<?php
namespace ASCLA\Core\Rest;
use ASCLA\Core\Services\{Access,FormRules,Profiles};

/** Validate field shapes before callbacks cast values or acquire write locks. */
final class RequestValidation
{
    private const TEXT=['title','body','description','message','reason','detail','decision','kind','status','q','country','name','email','login','role','membership_status','membership_until','first_name','last_name','position','company','member_type','bio','experience','phone','phone_visibility','birth_date','linkedin','twitter','website','current_password','new_password','confirm_password','board_reference','board_date'];
    private const BOOLEANS=['active','reviewed','suspended','board_confirmed','directory','networking','microevents','demo','moderation_required','moderate_comments','chatham_default','micro_enabled','micro_approval','turnstile_enabled','turnstile_login','turnstile_recovery','turnstile_public','send_invite','complete','acknowledge','enabled','reset'];
    private const IDS=['target','parent','photo_id','professional_request_id','assignee','resource_id','conversation_id','user_id','last','page','per_page'];
    private const FILTERS=['mine','past','recommended','micro'];
    private const LISTS=['users','media_ids','interest','category','tag','topics','tag_names','assigned_roles','interests','areas','industries','goals','languages','learn','help','connect_topics','hidden','profile_completion_extra'];
    private const INVALID_FORMAT='Formato de solicitud no válido.';

    public static function validate(\WP_REST_Request $request): void
    {
        $json=$request->get_json_params();
        self::jsonShape($request,$json);
        $params=$request->get_params();
        self::textFields($params);
        self::booleanFields($request,$params);
        self::identifiers($request,$params);
        $fields=is_array($json)?$json:$request->get_body_params();
        self::lists($request,$params,$fields);
        self::objects($request,$params,$fields);
    }

    private static function jsonShape(\WP_REST_Request $request,mixed $json): void
    {
        if ($json!==null) {
            // Empty arrays remain compatible with existing clients for bodyless actions.
            Access::require(is_array($json) && ($json===[] || !array_is_list($json)),self::INVALID_FORMAT,400);
        } elseif ($request->is_json_content_type() && trim((string)$request->get_body())!=='') {
            Access::require(false,self::INVALID_FORMAT,400);
        }
    }
    private static function textFields(array $params): void
    {
        foreach (self::TEXT as $key) {
            if (isset($params[$key])) { Access::require(is_string($params[$key]),'Texto no válido.',400); }
        }
    }
    private static function booleanFields(\WP_REST_Request $request,array $params): void
    {
        foreach (self::BOOLEANS as $key) {
            if (array_key_exists($key,$params)) { self::boolean($params[$key]);$request->set_param($key,rest_sanitize_boolean($params[$key])); }
        }
        foreach (self::FILTERS as $key) {
            if (!array_key_exists($key,$params)) { continue; }
            if ($request->get_method()==='GET' && $params[$key]==='') { $request->set_param($key,false);continue; }
            self::boolean($params[$key]);$request->set_param($key,rest_sanitize_boolean($params[$key]));
        }
        foreach ($params as $key=>$value) {
            if (str_starts_with((string)$key,'clear_')) { self::boolean($value);$request->set_param($key,rest_sanitize_boolean($value)); }
        }
    }
    private static function identifiers(\WP_REST_Request $request,array $params): void
    {
        foreach (self::IDS as $key) {
            // Optional list controls submit an empty value to mean no filter.
            if ($request->get_method()==='GET' && in_array($key,['parent','last','page','per_page'],true) && ($params[$key]??null)==='') { continue; }
            if (isset($params[$key])) { self::integer($params[$key]); }
        }
        if (array_key_exists('duration_seconds',$params)) { FormRules::integer('duration_seconds',$params['duration_seconds']); }
        foreach (['after','before'] as $key) {
            if (isset($params[$key])) { Access::require(is_string($params[$key])||is_int($params[$key]),self::INVALID_FORMAT,400); }
        }
    }
    private static function lists(\WP_REST_Request $request,array $params,array $fields): void
    {
        // Analytics selects one ID (zero means all); editorial taxonomies are lists.
        $statistics=in_array($request->get_route(),['/ascla/v1/admin/statistics','/ascla/v1/admin/statistics/topics'],true);
        if ($statistics) {
            foreach (['topic','category','event'] as $key) {
                if (array_key_exists($key,$params)) { self::integer($params[$key]); }
            }
        }
        foreach (self::LISTS as $key) {
            if (!array_key_exists($key,$fields)) { continue; }
            if ($statistics && $key==='category') { continue; }
            self::list($fields[$key]);
            if (in_array($key,['users','media_ids','interest','category','tag','topics'],true)||isset(Profiles::TERMS[$key])) { FormRules::ids($fields[$key]); }
        }
    }
    private static function objects(\WP_REST_Request $request,array $params,array $fields): void
    {
        foreach (['meta','columns','permission_overrides','email_notifications','matching_weights'] as $key) {
            if (array_key_exists($key,$params)) { self::object($params[$key]); }
        }
        if (isset($fields['meta'])) { self::metadata($fields['meta']);$request->set_param('meta',$fields['meta']); }
    }

    private static function boolean(mixed $value): void
    {
        FormRules::boolean($value);
    }
    private static function integer(mixed $value): void
    {
        Access::require((is_int($value)||is_string($value)) && filter_var($value,FILTER_VALIDATE_INT)!==false && (int)$value>=0,'Número no válido.',400);
    }
    private static function list(mixed $value): void
    {
        Access::require(is_array($value) && array_is_list($value),'Selección no válida.',400);
        foreach ($value as $entry) { Access::require(is_int($entry)||is_string($entry),'Selección no válida.',400); }
    }
    private static function object(mixed $value): void
    {
        Access::require(is_array($value) && ($value===[] || !array_is_list($value)),self::INVALID_FORMAT,400);
    }
    private static function metadata(array &$meta): void
    {
        foreach (array_merge(array_keys(FormRules::TEXT),['description','modality','resource_type','alliance_type','start','end','registration_deadline','change_reason']) as $key) {
            if (array_key_exists($key,$meta)) { Access::require(is_string($meta[$key]),'Texto no válido.',400); }
        }
        foreach (['capacity','offer_hours','duration_seconds','micro_min','priority_hours'] as $key) {
            if (array_key_exists($key,$meta)) { FormRules::integer($key,$meta[$key]); }
        }
        foreach (['chatham','micro','micro_approved','cancelled'] as $key) {
            if (array_key_exists($key,$meta)) { self::boolean($meta[$key]);$meta[$key]=rest_sanitize_boolean($meta[$key]); }
        }
        if (array_key_exists('media_ids',$meta)) { FormRules::ids($meta['media_ids']); }
        if (array_key_exists('reminder_hours',$meta)) {
            self::list($meta['reminder_hours']);
            foreach ($meta['reminder_hours'] as $hour) { FormRules::integer('reminder_hours',$hour); }
        }
    }
}
