# Stylesheets

`*-rtl.css` are generated from the LTR files with [rtlcss](https://rtlcss.com/)
and loaded automatically by WordPress for RTL locales (e.g. Arabic) via
`wp_style_add_data(..., 'rtl', 'replace')`. Do not edit them by hand;
after changing an LTR file regenerate them:

```bash
npx rtlcss@4 assets/css/workforce-one.css    assets/css/workforce-one-rtl.css
npx rtlcss@4 assets/css/workforce-one-ui.css assets/css/workforce-one-ui-rtl.css
npx rtlcss@4 assets/css/admin-schedule-config.css assets/css/admin-schedule-config-rtl.css
npx rtlcss@4 assets/css/admin-features.css assets/css/admin-features-rtl.css
npx rtlcss@4 assets/css/admin-notifications.css assets/css/admin-notifications-rtl.css
npx rtlcss@4 assets/css/admin-achievements.css assets/css/admin-achievements-rtl.css
npx rtlcss@4 assets/css/admin-smart-nudges.css assets/css/admin-smart-nudges-rtl.css
npx rtlcss@4 assets/css/admin-employee-profile.css assets/css/admin-employee-profile-rtl.css
npx rtlcss@4 assets/css/admin-attendance-insights.css assets/css/admin-attendance-insights-rtl.css
npx rtlcss@4 assets/css/app-shell.css assets/css/app-shell-rtl.css
npx rtlcss@4 assets/css/admin-appearance.css assets/css/admin-appearance-rtl.css
npx rtlcss@4 assets/css/app-home.css assets/css/app-home-rtl.css
npx rtlcss@4 assets/css/app-time.css assets/css/app-time-rtl.css
npx rtlcss@4 assets/css/app-leave.css assets/css/app-leave-rtl.css
npx rtlcss@4 assets/css/app-schedule.css assets/css/app-schedule-rtl.css
npx rtlcss@4 assets/css/app-polls.css assets/css/app-polls-rtl.css
npx rtlcss@4 assets/css/app-profile.css assets/css/app-profile-rtl.css
npx rtlcss@4 assets/css/app-people.css assets/css/app-people-rtl.css
npx rtlcss@4 assets/css/app-attendance.css assets/css/app-attendance-rtl.css
npx rtlcss@4 assets/css/app-reports.css assets/css/app-reports-rtl.css
npx rtlcss@4 assets/css/app-insights.css assets/css/app-insights-rtl.css
```
