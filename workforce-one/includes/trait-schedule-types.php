<?php
if (!defined('ABSPATH')) exit;

/**
 * Schedule types (Office, WFH, Vacation, …): their configuration and what each requires (Sign In,
 * location) and counts as (attendance rule).
 */
trait EWS_Schedule_Types_Trait {


    private function default_schedule_types_config(){
            return [
                ['name'=>'Office','requires_sign_in'=>1,'requires_location'=>1,'attendance_rule'=>'attendance','active'=>1,'icon'=>'🏢','bg_color'=>'#dcfce7','text_color'=>'#166534','border_color'=>'#bbf7d0'],
                ['name'=>'WFH','requires_sign_in'=>1,'requires_location'=>0,'attendance_rule'=>'attendance','active'=>1,'icon'=>'🏠','bg_color'=>'#dbeafe','text_color'=>'#1d4ed8','border_color'=>'#bfdbfe'],
                ['name'=>'Vacation','requires_sign_in'=>0,'requires_location'=>0,'attendance_rule'=>'leave','active'=>1,'icon'=>'🌴','bg_color'=>'#fef3c7','text_color'=>'#92400e','border_color'=>'#fde68a'],
                ['name'=>'Business Trip','requires_sign_in'=>0,'requires_location'=>0,'attendance_rule'=>'business_trip','active'=>1,'icon'=>'🚗','bg_color'=>'#ede9fe','text_color'=>'#6d28d9','border_color'=>'#ddd6fe'],
                ['name'=>'Training Course','requires_sign_in'=>0,'requires_location'=>0,'attendance_rule'=>'business_trip','active'=>1,'icon'=>'🎓','bg_color'=>'#e0f2fe','text_color'=>'#0369a1','border_color'=>'#bae6fd'],
            ];
        }

    private function schedule_types_config($active_only=true){
            if(self::$ews_schedule_types_cache===null){
                $types=$this->option('ews_schedule_types_config');
            if(!is_array($types)||empty($types)){
                $types=$this->default_schedule_types_config();
                update_option('ews_schedule_types_config',$types,false);
            }
            $out=[];
            foreach($types as $type){
                if(!is_array($type))continue;
                $name=trim(sanitize_text_field($type['name']??''));
                if($name==='')continue;
                if($active_only && empty($type['active']))continue;
                $rule=sanitize_key($type['attendance_rule']??'attendance');
                if(!in_array($rule,['attendance','leave','business_trip'],true))$rule='attendance';
                $defaults=['icon'=>'•','bg_color'=>'#f2f4f7','text_color'=>'#667085','border_color'=>'#e5e7eb'];
                $style_defaults=[
                    'Office'=>['icon'=>'🏢','bg_color'=>'#dcfce7','text_color'=>'#166534','border_color'=>'#bbf7d0'],
                    'WFH'=>['icon'=>'🏠','bg_color'=>'#dbeafe','text_color'=>'#1d4ed8','border_color'=>'#bfdbfe'],
                    'Vacation'=>['icon'=>'🌴','bg_color'=>'#fef3c7','text_color'=>'#92400e','border_color'=>'#fde68a'],
                    'Business Trip'=>['icon'=>'🚗','bg_color'=>'#ede9fe','text_color'=>'#6d28d9','border_color'=>'#ddd6fe'],
                    'Training Course'=>['icon'=>'🎓','bg_color'=>'#e0f2fe','text_color'=>'#0369a1','border_color'=>'#bae6fd']
                ];
                $sd=$style_defaults[$name]??$defaults;
                $hex=function($v,$fallback){$v=sanitize_text_field((string)$v);return preg_match('/^#[0-9a-fA-F]{6}$/',$v)?strtolower($v):$fallback;};
                $out[]=[
                    'name'=>$name,
                    'requires_sign_in'=>!empty($type['requires_sign_in'])?1:0,
                    'requires_location'=>!empty($type['requires_location'])?1:0,
                    'attendance_rule'=>$rule,
                    'active'=>!empty($type['active'])?1:0,
                    'icon'=>trim(sanitize_text_field($type['icon']??$sd['icon']))?:$sd['icon'],
                    'bg_color'=>$hex($type['bg_color']??$sd['bg_color'],$sd['bg_color']),
                    'text_color'=>$hex($type['text_color']??$sd['text_color'],$sd['text_color']),
                    'border_color'=>$hex($type['border_color']??$sd['border_color'],$sd['border_color'])
                ];
            }
            $has_training=false;
            foreach($out as $x){if(strtolower($x['name'])==='training course'){$has_training=true;break;}}
            if(!$has_training)$out[]=array_merge(['name'=>'Training Course','requires_sign_in'=>0,'requires_location'=>0,'attendance_rule'=>'business_trip','active'=>1],['icon'=>'🎓','bg_color'=>'#e0f2fe','text_color'=>'#0369a1','border_color'=>'#bae6fd']);
                self::$ews_schedule_types_cache=$out;
            }
            if(!$active_only)return self::$ews_schedule_types_cache;
            return array_values(array_filter(self::$ews_schedule_types_cache,function($type){return !empty($type['active']);}));
        }

    private function schedule_type_config($name){
            $name=(string)$name;
            if(array_key_exists($name,self::$ews_schedule_type_by_name_cache))return self::$ews_schedule_type_by_name_cache[$name];
            foreach($this->schedule_types_config(false) as $type){
                if($type['name']===$name)return self::$ews_schedule_type_by_name_cache[$name]=$type;
            }
            return self::$ews_schedule_type_by_name_cache[$name]=null;
        }

    private function schedule_type_requires_sign_in($name){
            $type=$this->schedule_type_config($name);
            return $type?(bool)$type['requires_sign_in']:false;
        }


    private function schedule_type_requires_location($name){
            $type=$this->schedule_type_config($name);
            return $type?(bool)$type['requires_location']:false;
        }

    private function schedule_type_attendance_rule($name){
            $type=$this->schedule_type_config($name);
            return $type?$type['attendance_rule']:'attendance';
        }

    private function schedule_type_names($active_only=true){
            return array_map(function($x){return $x['name'];},$this->schedule_types_config($active_only));
        }

    private function statuses(){
            return array_merge($this->schedule_type_names(true),['Absent']);
        }
}
