# Stylesheets

`*-rtl.css` are generated from the LTR files with [rtlcss](https://rtlcss.com/)
and loaded automatically by WordPress for RTL locales (e.g. Arabic) via
`wp_style_add_data(..., 'rtl', 'replace')`. Do not edit them by hand;
after changing an LTR file regenerate them:

```bash
npx rtlcss@4 assets/css/workforce-one.css    assets/css/workforce-one-rtl.css
npx rtlcss@4 assets/css/workforce-one-ui.css assets/css/workforce-one-ui-rtl.css
```
