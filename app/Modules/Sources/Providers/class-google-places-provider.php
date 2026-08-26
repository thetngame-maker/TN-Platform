<?php
namespace TNG_OS\Modules\Sources\Providers;
use TNG_OS\Modules\Sources\Provider_Interface;
use WP_Error;
if (!defined('ABSPATH')) exit;

final class Google_Places_Provider implements Provider_Interface {
    private string $api_key;
    public function __construct(string $api_key) { $this->api_key = trim($api_key); }
    public function id(): string { return 'google_places'; }
    public function label(): string { return 'Google Places'; }
    public function capabilities(): array { return ['details','hours','rating','coordinates','photos','attributes']; }

    public function fetch(string $external_id, array $context = []) {
        if ($this->api_key === '') return new WP_Error('missing_key','Google Places API key is not configured.');
        if ($external_id === '') return new WP_Error('missing_id','Google Place ID is required.');
        $fields = [
            'id','displayName','formattedAddress','shortFormattedAddress','location',
            'nationalPhoneNumber','internationalPhoneNumber','websiteUri','googleMapsUri',
            'rating','userRatingCount','priceLevel','businessStatus','regularOpeningHours',
            'primaryType','primaryTypeDisplayName','types','photos','editorialSummary',
            'dineIn','takeout','delivery','outdoorSeating','reservable','servesBreakfast',
            'servesLunch','servesDinner','servesCoffee','servesDessert','servesBeer',
            'servesWine','servesCocktails','liveMusic','goodForChildren','restroom',
            'accessibilityOptions','parkingOptions','paymentOptions'
        ];
        $response = wp_remote_get('https://places.googleapis.com/v1/places/'.rawurlencode($external_id),[
            'timeout'=>25,
            'headers'=>[
                'X-Goog-Api-Key'=>$this->api_key,
                'X-Goog-FieldMask'=>implode(',',$fields),
                'Accept'=>'application/json',
            ],
        ]);
        if (is_wp_error($response)) return $response;
        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($body)) {
            return new WP_Error('google_places_error',$body['error']['message'] ?? ('Google Places returned HTTP '.$status),['status'=>$status,'body'=>$body]);
        }
        return $body;
    }

    public function normalize(array $d): array {
        $hours = $d['regularOpeningHours']['weekdayDescriptions'] ?? [];
        $photos=[];
        foreach(array_slice((array)($d['photos']??[]),0,10) as $photo){
            if(empty($photo['name'])) continue;
            $attributions=[];
            foreach((array)($photo['authorAttributions']??[]) as $a){
                $attributions[]=['display_name'=>$a['displayName']??'','uri'=>$a['uri']??'','photo_uri'=>$a['photoUri']??''];
            }
            $photos[]=['name'=>$photo['name'],'width'=>$photo['widthPx']??0,'height'=>$photo['heightPx']??0,'attributions'=>$attributions];
        }
        $attrs=[];
        foreach([
            'dineIn'=>'dine_in','takeout'=>'takeout','delivery'=>'delivery','outdoorSeating'=>'outdoor_seating',
            'reservable'=>'reservations','goodForChildren'=>'kid_friendly','liveMusic'=>'live_music',
            'servesBreakfast'=>'breakfast','servesLunch'=>'lunch','servesDinner'=>'dinner',
            'servesCoffee'=>'coffee','servesDessert'=>'dessert','servesBeer'=>'beer',
            'servesWine'=>'wine','servesCocktails'=>'cocktails','restroom'=>'restroom'
        ] as $remote=>$local){ if(!empty($d[$remote])) $attrs[]=$local; }
        if(!empty($d['accessibilityOptions']['wheelchairAccessibleEntrance'])) $attrs[]='wheelchair_accessible';
        return [
            'external_id'=>$d['id']??'',
            'name'=>$d['displayName']['text']??'',
            'address'=>$d['formattedAddress']??'',
            'short_address'=>$d['shortFormattedAddress']??'',
            'latitude'=>$d['location']['latitude']??'',
            'longitude'=>$d['location']['longitude']??'',
            'phone'=>$d['nationalPhoneNumber']??($d['internationalPhoneNumber']??''),
            'website'=>$d['websiteUri']??'',
            'maps_url'=>$d['googleMapsUri']??'',
            'rating'=>$d['rating']??'',
            'rating_count'=>$d['userRatingCount']??'',
            'price_level'=>$d['priceLevel']??'',
            'business_status'=>$d['businessStatus']??'',
            'hours'=>is_array($hours)?implode("\n",$hours):'',
            'primary_type'=>$d['primaryType']??'',
            'primary_type_label'=>$d['primaryTypeDisplayName']['text']??'',
            'types'=>(array)($d['types']??[]),
            'summary'=>$d['editorialSummary']['text']??'',
            'attributes'=>array_values(array_unique($attrs)),
            'photos'=>$photos,
        ];
    }
}
