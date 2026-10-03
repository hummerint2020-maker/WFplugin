<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Pwa\Manifest;

/**
 * The installable app (PWA) employees use on their phones: the manifest (?ews_pwa=manifest), the
 * service worker (?ews_pwa=sw, offline files and push notifications), the app page's head tags and
 * splash screen, and the footer's install banner, push helpers and service worker registration.
 * Values: src/Pwa/Manifest.php; output: templates/pwa/. The URLs, scope and output are what installed
 * apps rely on: tests/e2e_pwa.py and tests/pwa_browser.js pin them.
 */
trait EWS_PWA_Trait {

    private function pwa_is_employee_app_page(){
            if(!is_page()) return false;
            $post=get_post();
            if(!$post) return false;
            return has_shortcode($post->post_content,'employee_app');
        }

    private function pwa_plugin_root(){
            return plugin_dir_url(dirname(__DIR__) . '/employee-schedule-manager.php');
        }

    public function pwa_manifest_route(){
            if(isset($_GET['ews_pwa']) && $_GET['ews_pwa']==='manifest'){
                nocache_headers();
                header('Content-Type: application/manifest+json; charset=utf-8');
                $app=$this->pwa_is_employee_app_page()?get_permalink():home_url('/');
                echo wp_json_encode(Manifest::data((string)$app,wp_parse_url(home_url('/'),PHP_URL_PATH) ?: '/',$this->pwa_plugin_root()));
                exit;
            }
            if(isset($_GET['ews_pwa']) && $_GET['ews_pwa']==='icon'){
                nocache_headers();
                header('Content-Type: image/svg+xml; charset=utf-8');
                echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><rect width="512" height="512" rx="96" fill="#101828"/><rect x="100" y="110" width="312" height="292" rx="42" fill="#fff"/><path d="M150 185h212M150 245h150M150 305h110" stroke="#101828" stroke-width="28" stroke-linecap="round"/><circle cx="355" cy="318" r="45" fill="#2563eb"/><path d="M355 292v52M329 318h52" stroke="#fff" stroke-width="16" stroke-linecap="round"/></svg>';
                exit;
            }
        }

    public function pwa_sw_route(){
            if(isset($_GET['ews_pwa']) && $_GET['ews_pwa']==='sw'){
                nocache_headers();
                header('Content-Type: application/javascript; charset=utf-8');
                header('Service-Worker-Allowed: ' . (wp_parse_url(home_url('/'), PHP_URL_PATH) ?: '/'));
                $root=$this->pwa_plugin_root();
                echo $this->render_template('pwa/service-worker',[ // phpcs:ignore WordPress.Security.EscapeOutput -- JavaScript, values JSON-encoded in the template
                    'sw'=>Manifest::serviceWorker($root,(string)wp_parse_url($root, PHP_URL_PATH),defined('EWS_VERSION') ? EWS_VERSION : '3.22.81'),
                    'scope'=>home_url('/'),'home'=>home_url('/'),
                ]);
                exit;
            }
        }

    public function pwa_head(){
            if(!$this->pwa_is_employee_app_page()) return;
            $splash=$this->pwa_splash_settings();
            echo $this->render_template('pwa/head',[ // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template
                'manifest'=>add_query_arg('ews_pwa','manifest',home_url('/')),
                'icon'=>$this->pwa_plugin_root().'assets/icons/workforce-one-192.png',
                'splash'=>!empty($splash['enabled'])?$splash:null,
            ]);
        }

    public function pwa_footer(){
            if(!$this->pwa_is_employee_app_page()) return;
            echo $this->render_template('pwa/footer',[ // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template
                'vapid_key'=>$this->get_vapid_public_key(),'push_url'=>admin_url('admin-post.php'),'push_nonce'=>wp_create_nonce('ews_push_subscription'),
                'sw'=>add_query_arg('ews_pwa','sw',home_url('/')),
            ]);
        }
}
