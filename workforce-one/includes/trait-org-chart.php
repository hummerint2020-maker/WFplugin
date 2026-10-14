<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Organization\OrgTree;
use WorkforceOne\Settings\OrgChartSettings;

/**
 * Organization chart (3.31.97, owner-approved mockup docs/mockups/org-chart/, decisions in
 * docs/tasks/org-chart.md), off until turned on in Features. Part of People (it owns the employees and
 * their relationships, docs/architecture/modules.md).
 *
 * The chart is built on each employee's direct manager: the "supervisor" relationship the approvals
 * already use, set on wp-admin → Employees. Nothing is entered twice.
 * - wp-admin → People → Org chart: the whole chart, a person panel, search, who has no manager and
 *   who has too many direct reports (for people who manage employees).
 * - The app → My team: the reporting line, the direct reports and teammates; who may open it and
 *   what an employee sees are settings (src/Settings/OrgChartSettings.php).
 * Rules: src/Organization/OrgTree.php. Behaviour: tests/e2e_org_chart.py.
 */
trait EWS_Org_Chart_Trait {

    /** @return array{enabled:int,audience:string,employee_view:string,wide_span:int,title_required:int} */
    private function org_settings(){
        return OrgChartSettings::config($this->option('ews_org_chart'));
    }

    private function org_enabled(){
        return (bool)$this->org_settings()['enabled'];
    }

    private function org_title_required(){
        $s=$this->org_settings();
        return $s['enabled'] && $s['title_required'];
    }

    /* ------------------------------------------------------------------ People's functions for the other modules */

    /** Each active employee's direct manager (0 = none), from the newest supervisor relationship. @return array<int,int> */
    private function people_manager_ids(){
        global $wpdb;
        $rt=$wpdb->prefix.'ews_employee_relationships';
        $out=[];
        foreach((array)$wpdb->get_col("SELECT id FROM {$this->employees} WHERE active=1") as $id)$out[(int)$id]=0;
        if(!$out)return $out;
        if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$rt))!==$rt)return $out;
        foreach((array)$wpdb->get_results("SELECT employee_id,related_employee_id FROM {$rt} WHERE relationship_type='supervisor' AND active=1 AND related_employee_id IS NOT NULL ORDER BY id DESC") as $r){
            $e=(int)$r->employee_id;
            if(isset($out[$e]) && !$out[$e])$out[$e]=(int)$r->related_employee_id;
        }
        return $out;
    }

    /** The organization chart of the active employees (OrgTree). */
    private function org_tree(){
        return new OrgTree($this->people_manager_ids());
    }

    /** The active employees as the chart shows them, by id. @return array<int,object> */
    private function people_org_rows(){
        global $wpdb;
        $d=$wpdb->prefix.'ews_departments';
        $has_dept=$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$d))===$d;
        $rows=(array)$wpdb->get_results("SELECT e.id,e.name,e.job_title,e.department_id,e.wp_user_id,e.profile_image_type,e.profile_image_url,e.avatar_key".($has_dept?",d.name department_name FROM {$this->employees} e LEFT JOIN {$d} d ON d.id=e.department_id":" FROM {$this->employees} e")." WHERE e.active=1 ORDER BY e.name ASC");
        $out=[];
        foreach($rows as $r)$out[(int)$r->id]=$r;
        return $out;
    }

    /* ------------------------------------------------------------------ wp-admin → People → Org chart */

    /** The chart's data for the page: people, colours by department, counts and what needs attention. */
    private function org_chart_data(){
        $rows=$this->people_org_rows();
        $tree=$this->org_tree();
        $s=$this->org_settings();
        $depts=[];
        // A department that no longer exists counts as none.
        foreach($rows as $r)if((int)$r->department_id && (string)($r->department_name??'')!=='')$depts[(int)$r->department_id]=['name'=>(string)$r->department_name,'n'=>0];
        // A department keeps its colour (by id order), whoever is added or removed.
        ksort($depts);$i=0;
        foreach($depts as $k=>$d)$depts[$k]['n']=$i++%8;
        $people=[];
        foreach($rows as $id=>$r){
            $people[]=['id'=>$id,'n'=>(string)$r->name,'t'=>(string)($r->job_title??''),'d'=>(int)$r->department_id,'m'=>$tree->managerOf($id),
                'r'=>count($tree->reportsOf($id)),'u'=>$tree->underCount($id),'p'=>$this->employee_picture_url($r)];
        }
        $wide=$tree->wideSpans((int)$s['wide_span']);
        return [
            'people'=>$people,'departments'=>$depts,
            'counts'=>['people'=>count($rows),'managers'=>count($tree->managers()),'levels'=>$tree->levels(),'without'=>count($tree->withoutManager())],
            'without'=>$tree->withoutManager(),'wide'=>$wide,'wide_span'=>(int)$s['wide_span'],
            'profile'=>admin_url('admin.php?page=ews31-employee-profile&employee_id='),
            'set_manager'=>admin_url('admin.php?page=ews31-employees&s='),
        ];
    }

    public function admin_org_chart(){
        if(!$this->can('ews_manage_employees'))wp_die(esc_html__('Access denied','workforce-one'));
        echo $this->render_template('admin/org-chart',[ // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template
            'enabled'=>$this->org_enabled(),'data'=>$this->org_chart_data(),
            'features_url'=>admin_url('admin.php?page=ews31-features#ews-org-chart'),
        ]);
    }

    /* ------------------------------------------------------------------ the app → My team */

    /** May the signed-in person open My team? */
    private function org_app_allowed(){
        if($this->org_app_allowed_memo!==null)return $this->org_app_allowed_memo;
        return $this->org_app_allowed_memo=$this->org_app_allowed_now();
    }

    /** @var bool|null org_app_allowed() for this request (the menu asks it for every page) */
    private $org_app_allowed_memo=null;

    private function org_app_allowed_now(){
        $s=$this->org_settings();
        if(!$s['enabled'])return false;
        $admin=current_user_can('manage_options')||$this->can('ews_manage_employees');
        $emp=$this->current_employee();
        // Asked on every app page (the menu): one small query, not the whole chart.
        $manager=$emp && $s['audience']==='managers' && !$admin && $this->people_has_reports((int)$emp->id);
        return OrgChartSettings::appAllowed($s,$admin,$manager);
    }

    /** Does an active employee report directly to this one? */
    private function people_has_reports($employee_id){
        global $wpdb;
        $rt=$wpdb->prefix.'ews_employee_relationships';
        if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$rt))!==$rt)return false;
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT r.id FROM {$rt} r JOIN {$this->employees} e ON e.id=r.employee_id AND e.active=1 WHERE r.related_employee_id=%d AND r.relationship_type='supervisor' AND r.active=1 LIMIT 1",$employee_id));
    }

    /** Whom the signed-in person may look at in My team (null = anyone). @return array<int,bool>|null */
    private function org_app_visible(OrgTree $tree,$me){
        $s=$this->org_settings();
        if(current_user_can('manage_options')||$this->can('ews_manage_employees'))return null;
        if($s['audience']==='everyone' && $s['employee_view']==='company')return null;
        $ids=[$me];
        $ids=array_merge($ids,$tree->chain($me),$tree->teammatesOf($me),$tree->everyoneUnder($me));
        return array_fill_keys(array_map('intval',$ids),true);
    }

    /** The app's My team page (?ews_view=team, &person= to look at someone else). */
    private function team_content(){
        $emp=$this->current_employee();
        $rows=$this->people_org_rows();
        $tree=$this->org_tree();
        $me=$emp?(int)$emp->id:0;
        $visible=$this->org_app_visible($tree,$me);
        $focus=absint($_GET['person']??0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- whom to show
        if(!$focus || !isset($rows[$focus]) || ($visible!==null && empty($visible[$focus])))$focus=$me;
        // Someone who may see everyone but is not an employee (an administrator) starts at the top.
        if(!$focus && $visible===null){
            foreach($tree->tops() as $top){
                if($tree->reportsOf($top)){$focus=$top;break;}
            }
        }
        $card=function($id)use($rows,$tree){
            $r=$rows[$id]??null;
            if(!$r)return null;
            return ['id'=>(int)$id,'name'=>(string)$r->name,'title'=>(string)($r->job_title??''),'department'=>(string)($r->department_name??''),
                'picture'=>$this->employee_picture_url($r),'reports'=>count($tree->reportsOf((int)$id)),
                'url'=>add_query_arg(['ews_view'=>'team','person'=>(int)$id],$this->app_home_url())];
        };
        $list=function(array $ids)use($card,$visible){
            $out=[];
            foreach($ids as $id){if($visible!==null && empty($visible[(int)$id]))continue;$c=$card((int)$id);if($c)$out[]=$c;}
            usort($out,function($a,$b){return strcasecmp($a['name'],$b['name']);});
            return $out;
        };
        $chain=$focus?$tree->chain($focus):[];
        array_pop($chain);
        wp_enqueue_style('workforce-one-team-page');
        return $this->render_template('app/team',[
            'me'=>$me,'person'=>$focus?$card($focus):null,
            'above'=>array_values(array_filter(array_map($card,$chain))),
            'reports'=>$focus?$list($tree->reportsOf($focus)):[],
            'teammates'=>$focus?$list($tree->teammatesOf($focus)):[],
            'home_url'=>add_query_arg('ews_view','team',$this->app_home_url()),
        ]);
    }
}
