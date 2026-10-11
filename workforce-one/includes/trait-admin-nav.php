<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Ui\AdminNav;

/**
 * The wp-admin menu in 8 sections with tabs (3.31.88, owner-approved mockup docs/mockups/admin-menu/).
 *
 * Pages are registered exactly as before (EWS_Admin_Trait::admin_menu), each with its own capability:
 * WordPress opens only pages that are in the menu, so none is removed. After registration the menu is
 * reordered: one item per section, named after the section and pointing at its first page this
 * person may open; the other pages stay in the menu but hidden. Each page then shows its section's
 * tabs. A tab whose feature is off is not shown (the page itself still opens from a link).
 * Which sections and pages: src/Ui/AdminNav.php. Behaviour: tests/e2e_admin_nav.py.
 */
trait EWS_Admin_Nav_Trait {

    /** @var array<string,list<string>> section => visible slugs, for this request */
    private $ews_admin_nav = [];

    /** @return array<string,string> */
    private function admin_nav_section_labels(){
        return [
            'home'=>__('Home','workforce-one'),
            'people'=>__('People','workforce-one'),
            'attendance'=>__('Attendance','workforce-one'),
            'requests'=>__('Requests','workforce-one'),
            'schedule'=>__('Schedule & places','workforce-one'),
            'pay'=>_x('Pay','admin menu section','workforce-one'),
            'engage'=>__('Engagement','workforce-one'),
            'settings'=>__('Settings','workforce-one'),
        ];
    }

    /** @return array<string,string> slug => tab name */
    private function admin_nav_tab_labels(){
        return [
            'ews31'=>__('Today','workforce-one'),
            'ews31-employees'=>__('Employees','workforce-one'),
            'ews31-departments'=>__('Departments','workforce-one'),
            'ews31-teams'=>__('Teams','workforce-one'),
            'ews31-employee-branches'=>__('Branches','workforce-one'),
            'ews31-time-report'=>__('Sign In / Out','workforce-one'),
            'ews31-attendance-insights'=>__('Insights','workforce-one'),
            'ews31-corrections'=>__('Corrections','workforce-one'),
            'ews31-auto-attendance'=>__('Auto attendance','workforce-one'),
            'ews31-face-reset-requests'=>__('Face resets','workforce-one'),
            'ews31-requests'=>__('Requests Hub','workforce-one'),
            'ews31-leaves'=>__('Leaves','workforce-one'),
            'ews31-approvals'=>__('Approval workflows','workforce-one'),
            'ews31-schedule-config'=>__('Schedule','workforce-one'),
            'ews31-multi-locations'=>__('Work locations','workforce-one'),
            'ews31-presence-kiosks'=>__('Kiosks','workforce-one'),
            'ews31-payroll'=>__('Payroll','workforce-one'),
            'ews31-daily-workers'=>__('Daily workers','workforce-one'),
            'ews31-moments'=>__('Moments','workforce-one'),
            'ews31-smart-nudges'=>__('Nudges','workforce-one'),
            'ews31-achievements'=>__('Achievements','workforce-one'),
            'ews31-polls'=>__('Polls','workforce-one'),
            'ews31-recognition'=>__('Recognition','workforce-one'),
            'ews31-tasks'=>__('Tasks','workforce-one'),
            'ews31-features'=>__('Features','workforce-one'),
            'ews31-notifications'=>__('Notifications','workforce-one'),
            'ews31-email'=>__('Email','workforce-one'),
            'ews31-roles'=>__('Roles & permissions','workforce-one'),
            'ews31-appearance'=>__('Appearance','workforce-one'),
            'ews31-navigation'=>__('App menu','workforce-one'),
            'ews31-employee-profile-settings'=>__('Profile page','workforce-one'),
            'ews31-settings-overview'=>__('Overview','workforce-one'),
            'ews31-audit'=>__('Audit log','workforce-one'),
        ];
    }

    /** Features that hide a tab while off (AdminNav::sections()). @return array<string,bool> */
    private function admin_nav_features(){
        return ['face'=>$this->face_signin_enabled(),'recognition'=>$this->recognition_enabled(),'tasks'=>$this->tasks_enabled()];
    }

    /** End of admin_menu: one menu item per section, the other pages kept but hidden. */
    private function admin_nav_apply(){
        global $submenu;
        if(empty($submenu['ews31']))return;
        $entries=[];
        foreach($submenu['ews31'] as $e)$entries[(string)$e[2]]=$e;
        $this->ews_admin_nav=AdminNav::visible(array_fill_keys(array_keys($entries),true),$this->admin_nav_features());
        $names=$this->admin_nav_section_labels();
        $menu=[];$used=[];
        foreach($this->ews_admin_nav as $id=>$slugs){
            $e=$entries[$slugs[0]];
            $e[0]=$names[$id];
            $menu[]=$e;$used[$slugs[0]]=true;
        }
        $hidden=[];
        foreach($entries as $slug=>$e){
            if(isset($used[$slug]))continue;
            $menu[]=$e;$hidden[]=$slug;
        }
        $submenu['ews31']=$menu;
        add_action('admin_head',function()use($hidden){
            $sel=[];
            foreach($hidden as $slug){
                $a='#toplevel_page_ews31 .wp-submenu a[href="admin.php?page='.esc_attr($slug).'"]';
                $sel[]=$a;$sel[]='#toplevel_page_ews31 .wp-submenu li:has(> '.substr($a,strlen('#toplevel_page_ews31 .wp-submenu ')).')';
            }
            echo '<style>'.($sel?implode(',',$sel).'{display:none!important}':'')
                .'.wfo-admin-nav{margin:12px 20px 0 2px}.wfo-admin-nav-section{font-size:12px;color:#646970;margin:0 0 4px}.wfo-admin-nav .nav-tab-wrapper{margin:0}</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_attr() slugs
        });
    }

    /** The page being shown, when it is one of the sections' pages. @return array{0:string,1:string}|null [section, tab] */
    private function admin_nav_current(){
        $page=sanitize_key($_GET['page']??''); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation
        return $page!==''?AdminNav::place($page):null;
    }

    /** Keeps "Workforce One" open in the menu on every section page. */
    public function admin_nav_parent_file($file){
        return $this->admin_nav_current()?'ews31':$file;
    }

    /** Highlights the section of the page being shown. */
    public function admin_nav_submenu_file($file){
        $cur=$this->admin_nav_current();
        if(!$cur || empty($this->ews_admin_nav[$cur[0]]))return $file;
        return $this->ews_admin_nav[$cur[0]][0];
    }

    /** The section's tabs above the page (none for a section with one page). */
    public function admin_nav_tabs(){
        $cur=$this->admin_nav_current();
        if(!$cur || empty($this->ews_admin_nav[$cur[0]]))return;
        [$section,$tab]=$cur;
        $slugs=$this->ews_admin_nav[$section];
        $names=$this->admin_nav_tab_labels();
        echo '<div class="wfo-admin-nav"><p class="wfo-admin-nav-section">'.esc_html($this->admin_nav_section_labels()[$section]).'</p>';
        if(count($slugs)>1){
            echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="'.esc_attr($this->admin_nav_section_labels()[$section]).'">';
            foreach($slugs as $slug){
                $on=$slug===$tab;
                echo '<a href="'.esc_url(admin_url('admin.php?page='.$slug)).'" class="nav-tab'.($on?' nav-tab-active':'').'"'.($on?' aria-current="page"':'').'>'.esc_html($names[$slug]??$slug).'</a>';
            }
            echo '</nav>';
        }
        echo '</div>';
    }
}
