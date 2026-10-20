<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Api\Bootstrap;

/**
 * Mobile app API (3.31.113): GET /me/bootstrap. The /me document, unchanged, plus the features, the screens of this user's phone
 * menu and a few facts about the site (src/Api/Bootstrap.php). Read only; off by default (Mobile app API switch).
 */
trait EWS_Api_Me_Trait {

    public function api_me_bootstrap($request){
        if($e=$this->api_app_guard($request))return $e;
        return $this->api_ok(Bootstrap::document($this->api_me_document(),$this->api_feature_flags(),$this->app_screens(),[
            'timezone'=>wp_timezone_string(),'locale'=>get_locale(),'today'=>current_time('Y-m-d'),'working_days'=>$this->working_days(),
        ]));
    }
}
