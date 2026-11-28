<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Leave\HolidayRefund;

/**
 * Company holidays are not leave days (3.31.162, docs/tasks/leave-holidays.md, mockup docs/mockups/leave-holidays/).
 * One switch, off by default; off, every leave counts exactly as before and the report is not shown.
 * - On: a new leave is counted without the company holidays inside it (vacation_working_days_count()) and its approval puts no
 *   leave day on them (the leave keeps them in holidays_skipped, so its leave days always match what it charges).
 * The report and giving back are HolidayRefundService (src/Leave); this trait is their controller.
 * - The report (wp-admin → Leaves → Company holidays): the approved leaves of this leave year, charged to the balance, with a
 *   leave day on an active company holiday (charged before the switch, or a holiday added after the leave was asked). HR gives those days back: the day leaves the leave (its schedule goes
 *   back as it was before the leave, unless a manager changed it), the leave charges one day less, the balance gets it back, the
 *   employee is told, and the Audit Log keeps it. A day is never given back twice (ews_leave_holiday_refunds' unique key, and the
 *   leave day is gone). Pending leaves and leaves with a change waiting are decided first (owner, 2026-11-24).
 */
trait EWS_Leave_Holidays_Trait {

    /** The active company holidays between two days: Y-m-d => title (one per day). @return array<string,string> */
    private function leave_company_holidays($start,$end){
            $out=[];
            foreach($this->services()->scheduleDays()->companyHolidays((string)$start,(string)$end) as $e)if(!isset($out[(string)$e->event_date]))$out[(string)$e->event_date]=(string)$e->title;
            return $out;
        }

    /** The company holidays between two days (Y-m-d, ascending); none with the switch off, so nothing changes then. @return string[] */
    private function leave_holiday_dates($start,$end){
            if(!$this->leave_holidays_on() || !$this->valid_date((string)$start) || !$this->valid_date((string)$end) || (string)$end<(string)$start)return [];
            $days=array_map('strval',array_keys($this->leave_company_holidays((string)$start,(string)$end)));
            sort($days);
            return $days;
        }

    /** The holidays a new leave is counted without (HolidayRefund::skipped(), one reading of the switch). @return string[]|null */
    private function leave_holidays_skipped($start,$end,$days){
            return HolidayRefund::skipped(\WorkforceOne\Leave\WorkingDays::dates((string)$start,(string)$end,$this->working_days()),$this->leave_holiday_dates($start,$end),(float)$days);
        }

    /** The "Company holidays" tab of wp-admin → Leaves. */
    private function leave_admin_holidays_view(){
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only messages after a redirect
            $year=$this->leave_year();
            $error=sanitize_key($_GET['lh_error']??'');
            return ['tab'=>'holidays','return_tabs'=>$this->leave_return_on(),'year'=>$year,'rows'=>$this->services()->leaveHolidays()->report($year),
                'post_url'=>admin_url('admin-post.php'),'page_url'=>admin_url('admin.php?page=ews31-leaves'),
                'done_days'=>isset($_GET['lh_days'])?absint($_GET['lh_days']):null,'done_leaves'=>absint($_GET['lh_leaves']??0),
                'error'=>$error!==''?$this->leave_holiday_error($error):''];
            // phpcs:enable
        }

    private function leave_holiday_error($code){
            $m=['none'=>__('Choose at least one leave.','workforce-one'),'busy'=>__('Another change of this employee\'s leave is being saved. Try again in a moment.','workforce-one'),
                'some'=>__('Some leaves were not changed: they changed meanwhile, or a change is waiting for a decision. The list shows them as they are now.','workforce-one')];
            return $m[$code]??__('Could not save. Nothing was changed.','workforce-one');
        }

    /** HR gives back the company holidays charged on the chosen leaves (one or several). */
    public function admin_leave_holiday_restore(){
            if(!$this->can('ews_manage_settings'))wp_die('Access denied');
            check_admin_referer('ews_leave_holiday_restore');
            $this->ensure_leave_schema();
            if(!$this->leave_holidays_on())wp_die(esc_html__('Company holidays are not leave days is off.','workforce-one'));
            $back=['tab'=>'holidays'];
            // A row's own button gives back that leave only, whatever else is ticked.
            $one=absint($_POST['one']??0);
            $ids=$one?[$one]:array_slice(array_values(array_unique(array_filter(array_map('absint',(array)($_POST['leave_ids']??[]))))),0,200);
            if(!$ids)$this->leave_admin_redirect($back+['lh_error'=>'none']);
            $res=$this->services()->leaveHolidays()->giveBack($ids);
            $this->leave_admin_redirect($back+['lh_days'=>$res['days'],'lh_leaves'=>$res['leaves']]+($res['failed']?['lh_error'=>$res['busy']?'busy':'some']:[]));
        }
}
