<?php
namespace ASCLA\Core\Services;

final class Locations
{
    /** ISO 3166-1 alpha-2, nombre visible en español, nombre común en inglés. */
    private const COUNTRIES=[
        ['AF','Afganistán','Afghanistan'],
        ['AL','Albania','Albania'],
        ['DE','Alemania','Germany'],
        ['AD','Andorra','Andorra'],
        ['AO','Angola','Angola'],
        ['AI','Anguila','Anguilla'],
        ['AG','Antigua y Barbuda','Antigua & Barbuda'],
        ['AQ','Antártida','Antarctica'],
        ['SA','Arabia Saudí','Saudi Arabia'],
        ['DZ','Argelia','Algeria'],
        ['AR','Argentina','Argentina'],
        ['AM','Armenia','Armenia'],
        ['AW','Aruba','Aruba'],
        ['AU','Australia','Australia'],
        ['AT','Austria','Austria'],
        ['AZ','Azerbaiyán','Azerbaijan'],
        ['BS','Bahamas','Bahamas'],
        ['BD','Bangladés','Bangladesh'],
        ['BB','Barbados','Barbados'],
        ['BH','Baréin','Bahrain'],
        ['BZ','Belice','Belize'],
        ['BJ','Benín','Benin'],
        ['BM','Bermudas','Bermuda'],
        ['BY','Bielorrusia','Belarus'],
        ['BO','Bolivia','Bolivia'],
        ['BA','Bosnia y Herzegovina','Bosnia & Herzegovina'],
        ['BW','Botsuana','Botswana'],
        ['BR','Brasil','Brazil'],
        ['BN','Brunéi','Brunei'],
        ['BG','Bulgaria','Bulgaria'],
        ['BF','Burkina Faso','Burkina Faso'],
        ['BI','Burundi','Burundi'],
        ['BT','Bután','Bhutan'],
        ['BE','Bélgica','Belgium'],
        ['CV','Cabo Verde','Cape Verde'],
        ['KH','Camboya','Cambodia'],
        ['CM','Camerún','Cameroon'],
        ['CA','Canadá','Canada'],
        ['BQ','Caribe neerlandés','Caribbean Netherlands'],
        ['QA','Catar','Qatar'],
        ['TD','Chad','Chad'],
        ['CZ','Chequia','Czechia'],
        ['CL','Chile','Chile'],
        ['CN','China','China'],
        ['CY','Chipre','Cyprus'],
        ['VA','Ciudad del Vaticano','Vatican City'],
        ['CO','Colombia','Colombia'],
        ['KM','Comoras','Comoros'],
        ['CG','Congo','Congo - Brazzaville'],
        ['KP','Corea del Norte','North Korea'],
        ['KR','Corea del Sur','South Korea'],
        ['CR','Costa Rica','Costa Rica'],
        ['HR','Croacia','Croatia'],
        ['CU','Cuba','Cuba'],
        ['CW','Curazao','Curaçao'],
        ['CI','Côte d’Ivoire','Côte d’Ivoire'],
        ['DK','Dinamarca','Denmark'],
        ['DM','Dominica','Dominica'],
        ['EC','Ecuador','Ecuador'],
        ['EG','Egipto','Egypt'],
        ['SV','El Salvador','El Salvador'],
        ['AE','Emiratos Árabes Unidos','United Arab Emirates'],
        ['ER','Eritrea','Eritrea'],
        ['SK','Eslovaquia','Slovakia'],
        ['SI','Eslovenia','Slovenia'],
        ['ES','España','Spain'],
        ['US','Estados Unidos','United States'],
        ['EE','Estonia','Estonia'],
        ['SZ','Esuatini','Eswatini'],
        ['ET','Etiopía','Ethiopia'],
        ['PH','Filipinas','Philippines'],
        ['FI','Finlandia','Finland'],
        ['FJ','Fiyi','Fiji'],
        ['FR','Francia','France'],
        ['GA','Gabón','Gabon'],
        ['GM','Gambia','Gambia'],
        ['GE','Georgia','Georgia'],
        ['GH','Ghana','Ghana'],
        ['GI','Gibraltar','Gibraltar'],
        ['GD','Granada','Grenada'],
        ['GR','Grecia','Greece'],
        ['GL','Groenlandia','Greenland'],
        ['GP','Guadalupe','Guadeloupe'],
        ['GU','Guam','Guam'],
        ['GT','Guatemala','Guatemala'],
        ['GF','Guayana Francesa','French Guiana'],
        ['GG','Guernesey','Guernsey'],
        ['GN','Guinea','Guinea'],
        ['GQ','Guinea Ecuatorial','Equatorial Guinea'],
        ['GW','Guinea-Bisáu','Guinea-Bissau'],
        ['GY','Guyana','Guyana'],
        ['HT','Haití','Haiti'],
        ['HN','Honduras','Honduras'],
        ['HU','Hungría','Hungary'],
        ['IN','India','India'],
        ['ID','Indonesia','Indonesia'],
        ['IQ','Irak','Iraq'],
        ['IE','Irlanda','Ireland'],
        ['IR','Irán','Iran'],
        ['BV','Isla Bouvet','Bouvet Island'],
        ['IM','Isla de Man','Isle of Man'],
        ['CX','Isla de Navidad','Christmas Island'],
        ['NF','Isla Norfolk','Norfolk Island'],
        ['IS','Islandia','Iceland'],
        ['AX','Islas Aland','Åland Islands'],
        ['KY','Islas Caimán','Cayman Islands'],
        ['CC','Islas Cocos','Cocos (Keeling) Islands'],
        ['CK','Islas Cook','Cook Islands'],
        ['FO','Islas Feroe','Faroe Islands'],
        ['GS','Islas Georgia del Sur y Sandwich del Sur','South Georgia & South Sandwich Islands'],
        ['HM','Islas Heard y McDonald','Heard & McDonald Islands'],
        ['FK','Islas Malvinas','Falkland Islands'],
        ['MP','Islas Marianas del Norte','Northern Mariana Islands'],
        ['MH','Islas Marshall','Marshall Islands'],
        ['UM','Islas menores alejadas de EE. UU.','U.S. Outlying Islands'],
        ['PN','Islas Pitcairn','Pitcairn Islands'],
        ['SB','Islas Salomón','Solomon Islands'],
        ['TC','Islas Turcas y Caicos','Turks & Caicos Islands'],
        ['VG','Islas Vírgenes Británicas','British Virgin Islands'],
        ['VI','Islas Vírgenes de EE. UU.','U.S. Virgin Islands'],
        ['IL','Israel','Israel'],
        ['IT','Italia','Italy'],
        ['JM','Jamaica','Jamaica'],
        ['JP','Japón','Japan'],
        ['JE','Jersey','Jersey'],
        ['JO','Jordania','Jordan'],
        ['KZ','Kazajistán','Kazakhstan'],
        ['KE','Kenia','Kenya'],
        ['KG','Kirguistán','Kyrgyzstan'],
        ['KI','Kiribati','Kiribati'],
        ['KW','Kuwait','Kuwait'],
        ['LA','Laos','Laos'],
        ['LS','Lesoto','Lesotho'],
        ['LV','Letonia','Latvia'],
        ['LR','Liberia','Liberia'],
        ['LY','Libia','Libya'],
        ['LI','Liechtenstein','Liechtenstein'],
        ['LT','Lituania','Lithuania'],
        ['LU','Luxemburgo','Luxembourg'],
        ['LB','Líbano','Lebanon'],
        ['MK','Macedonia del Norte','North Macedonia'],
        ['MG','Madagascar','Madagascar'],
        ['MY','Malasia','Malaysia'],
        ['MW','Malaui','Malawi'],
        ['MV','Maldivas','Maldives'],
        ['ML','Mali','Mali'],
        ['MT','Malta','Malta'],
        ['MA','Marruecos','Morocco'],
        ['MQ','Martinica','Martinique'],
        ['MU','Mauricio','Mauritius'],
        ['MR','Mauritania','Mauritania'],
        ['YT','Mayotte','Mayotte'],
        ['FM','Micronesia','Micronesia'],
        ['MD','Moldavia','Moldova'],
        ['MN','Mongolia','Mongolia'],
        ['ME','Montenegro','Montenegro'],
        ['MS','Montserrat','Montserrat'],
        ['MZ','Mozambique','Mozambique'],
        ['MM','Myanmar (Birmania)','Myanmar (Burma)'],
        ['MX','México','Mexico'],
        ['MC','Mónaco','Monaco'],
        ['NA','Namibia','Namibia'],
        ['NR','Nauru','Nauru'],
        ['NP','Nepal','Nepal'],
        ['NI','Nicaragua','Nicaragua'],
        ['NG','Nigeria','Nigeria'],
        ['NU','Niue','Niue'],
        ['NO','Noruega','Norway'],
        ['NC','Nueva Caledonia','New Caledonia'],
        ['NZ','Nueva Zelanda','New Zealand'],
        ['NE','Níger','Niger'],
        ['OM','Omán','Oman'],
        ['PK','Pakistán','Pakistan'],
        ['PW','Palaos','Palau'],
        ['PA','Panamá','Panama'],
        ['PG','Papúa Nueva Guinea','Papua New Guinea'],
        ['PY','Paraguay','Paraguay'],
        ['NL','Países Bajos','Netherlands'],
        ['PE','Perú','Peru'],
        ['PF','Polinesia Francesa','French Polynesia'],
        ['PL','Polonia','Poland'],
        ['PT','Portugal','Portugal'],
        ['PR','Puerto Rico','Puerto Rico'],
        ['HK','RAE de Hong Kong (China)','Hong Kong SAR China'],
        ['MO','RAE de Macao (China)','Macao SAR China'],
        ['GB','Reino Unido','United Kingdom'],
        ['CF','República Centroafricana','Central African Republic'],
        ['CD','República Democrática del Congo','Congo - Kinshasa'],
        ['DO','República Dominicana','Dominican Republic'],
        ['RE','Reunión','Réunion'],
        ['RW','Ruanda','Rwanda'],
        ['RO','Rumanía','Romania'],
        ['RU','Rusia','Russia'],
        ['WS','Samoa','Samoa'],
        ['AS','Samoa Americana','American Samoa'],
        ['BL','San Bartolomé','St. Barthélemy'],
        ['KN','San Cristóbal y Nieves','St. Kitts & Nevis'],
        ['SM','San Marino','San Marino'],
        ['MF','San Martín','St. Martin'],
        ['PM','San Pedro y Miquelón','St. Pierre & Miquelon'],
        ['VC','San Vicente y las Granadinas','St. Vincent & Grenadines'],
        ['SH','Santa Elena','St. Helena'],
        ['LC','Santa Lucía','St. Lucia'],
        ['ST','Santo Tomé y Príncipe','São Tomé & Príncipe'],
        ['SN','Senegal','Senegal'],
        ['RS','Serbia','Serbia'],
        ['SC','Seychelles','Seychelles'],
        ['SL','Sierra Leona','Sierra Leone'],
        ['SG','Singapur','Singapore'],
        ['SX','Sint Maarten','Sint Maarten'],
        ['SY','Siria','Syria'],
        ['SO','Somalia','Somalia'],
        ['LK','Sri Lanka','Sri Lanka'],
        ['ZA','Sudáfrica','South Africa'],
        ['SD','Sudán','Sudan'],
        ['SS','Sudán del Sur','South Sudan'],
        ['SE','Suecia','Sweden'],
        ['CH','Suiza','Switzerland'],
        ['SR','Surinam','Suriname'],
        ['SJ','Svalbard y Jan Mayen','Svalbard & Jan Mayen'],
        ['EH','Sáhara Occidental','Western Sahara'],
        ['TH','Tailandia','Thailand'],
        ['TW','Taiwán','Taiwan'],
        ['TZ','Tanzania','Tanzania'],
        ['TJ','Tayikistán','Tajikistan'],
        ['IO','Territorio Británico del Océano Índico','British Indian Ocean Territory'],
        ['TF','Territorios Australes Franceses','French Southern Territories'],
        ['PS','Territorios Palestinos','Palestinian Territories'],
        ['TL','Timor-Leste','Timor-Leste'],
        ['TG','Togo','Togo'],
        ['TK','Tokelau','Tokelau'],
        ['TO','Tonga','Tonga'],
        ['TT','Trinidad y Tobago','Trinidad & Tobago'],
        ['TM','Turkmenistán','Turkmenistan'],
        ['TR','Turquía','Türkiye'],
        ['TV','Tuvalu','Tuvalu'],
        ['TN','Túnez','Tunisia'],
        ['UA','Ucrania','Ukraine'],
        ['UG','Uganda','Uganda'],
        ['UY','Uruguay','Uruguay'],
        ['UZ','Uzbekistán','Uzbekistan'],
        ['VU','Vanuatu','Vanuatu'],
        ['VE','Venezuela','Venezuela'],
        ['VN','Vietnam','Vietnam'],
        ['WF','Wallis y Futuna','Wallis & Futuna'],
        ['YE','Yemen','Yemen'],
        ['DJ','Yibuti','Djibouti'],
        ['ZM','Zambia','Zambia'],
        ['ZW','Zimbabue','Zimbabwe']
    ];

    public static function countries(): array
    {
        return array_map(static fn(array $row)=>['code'=>$row[0],'es'=>$row[1],'en'=>$row[2]],self::COUNTRIES);
    }

    public static function resolveCountry(string $value): ?array
    {
        $needle=self::normalize($value);
        if ($needle==='') { return null; }
        foreach (self::countries() as $country) {
            if ($needle===self::normalize($country['code']) || $needle===self::normalize($country['es']) || $needle===self::normalize($country['en'])) { return $country; }
        }
        return null;
    }

    public static function cities(string $country,string $query='',bool $exact=false): array
    {
        $resolved=self::resolveCountry($country);
        Access::require((bool)$resolved,'Seleccione un país válido.',400);
        $all=self::countryCities($resolved);
        if ($all===null) { return ['country'=>$resolved,'items'=>[],'available'=>false]; }
        $needle=self::normalize($query);
        if ($needle==='') { return ['country'=>$resolved,'items'=>[],'available'=>true]; }
        $matches=[];
        foreach ($all as $city) {
            $normalized=self::normalize($city);
            if ($exact ? $normalized===$needle : str_contains($normalized,$needle)) {
                $matches[]=$city;
                if (!$exact && count($matches)>=40) { break; }
            }
        }
        return ['country'=>$resolved,'items'=>$matches,'available'=>true,'exact'=>$exact ? count($matches)>0 : null];
    }

    private static function countryCities(array $country): ?array
    {
        $key='ascla_geo_cities_'.strtolower($country['code']);
        $cached=get_transient($key);
        if (is_array($cached)) { return $cached; }
        $apiName=str_replace(' & ',' and ',$country['en']);
        $url='https://countriesnow.space/api/v0.1/countries/cities/q?country='.rawurlencode($apiName);
        $response=wp_safe_remote_get($url,['timeout'=>8,'redirection'=>2,'user-agent'=>'ASCLA/'.(defined('ASCLA_VERSION')?ASCLA_VERSION:'1')]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200) { return null; }
        $payload=json_decode((string)wp_remote_retrieve_body($response),true);
        if (!is_array($payload) || !empty($payload['error']) || !is_array($payload['data']??null)) { return null; }
        $cities=[];
        foreach ($payload['data'] as $city) {
            $city=trim(wp_strip_all_tags((string)$city));
            if ($city!=='' && mb_strlen($city)<=120) { $cities[$city]=true; }
            if (count($cities)>=25000) { break; }
        }
        $cities=array_keys($cities);
        natcasesort($cities);
        $cities=array_values($cities);
        set_transient($key,$cities,7*DAY_IN_SECONDS);
        return $cities;
    }

    private static function normalize(string $value): string
    {
        $value=remove_accents(mb_strtolower(trim($value)));
        $value=preg_replace('/[^\p{L}\p{N}]+/u',' ', $value)??$value;
        return trim(preg_replace('/\s+/',' ',$value)??$value);
    }
}
