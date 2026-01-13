<?php
/**
 * Core plugin functionality for LP Cargonizer Return Portal.
 *
 * @package LP_Cargonizer_Return_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'LP_Cargonizer_Returns' ) ) {

final class LP_Cargonizer_Returns {

    /**
     * Singleton instance.
     *
     * @var LP_Cargonizer_Returns|null
     */
    private static $instance = null;

    /**
     * Retrieve the plugin instance.
     *
     * @return LP_Cargonizer_Returns
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Plugin activation handler.
     */
    public static function activate() {
        $instance = self::instance();
        $instance->register_events();
        $instance->maybe_create_log_table();
        $instance->register_admin_route();
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation handler.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook('lp_cargo_cleanup_labels');
        wp_clear_scheduled_hook('lp_cargo_warm_agreements');
    }
    /* -------- Options -------- */
    const OPT_API_KEY         = 'lp_cargo_api_key';
    const OPT_SENDER_ID       = 'lp_cargo_sender_id';
    const OPT_ALLOWED         = 'lp_cargo_allowed_products';
    const OPT_AUTO_TRANSFER   = 'lp_cargo_auto_transfer';
    const OPT_ATTACH_PDF      = 'lp_cargo_attach_pdf';
    const OPT_SWAP_PARTIES    = 'lp_cargo_swap_parties';
    const OPT_DEFAULT_SERV    = 'lp_cargo_default_services';
    const OPT_EMAIL_VIA_LOG   = 'lp_cargo_email_via_logistra';
    const OPT_TT_NOTIFY       = 'lp_cargo_tt_notify';
    const OPT_FEE_SMALL       = 'lp_cargo_fee_small';
    const OPT_FEE_LARGE       = 'lp_cargo_fee_large';
    const OPT_RETURN_WINDOW   = 'lp_cargo_return_window_days';

    /* -------- Free-shipping bonus -------- */
    const OPT_FS_BONUS_ENABLE = 'lp_cargo_fs_bonus_enable';
    const OPT_FS_BONUS_HOURS  = 'lp_cargo_fs_bonus_hours';
    const OPT_FS_BANNER_COLOR = 'lp_cargo_fs_banner_color';
    const COOKIE_FS_KEY       = 'lp_fs_key';
    const QUERY_FS_PARAM      = 'lp_fs';  // GET-parameter for aktivering i hvilken som helst nettleser (cookie/IP-basert)

    /* -------- Label & retention -------- */
    const OPT_LABEL_VALID_DAYS     = 'lp_cargo_label_valid_days';
    const OPT_LABEL_RETENTION_DAYS = 'lp_cargo_label_retention_days';

    /* -------- Content / UX -------- */
    const OPT_SUPPORT_EMAIL   = 'lp_cargo_support_email';
    const OPT_RETURN_REASONS  = 'lp_cargo_return_reasons';     // array
    const OPT_EXCHANGE_INFO   = 'lp_cargo_exchange_info_text'; // string
    const OPT_ADMIN_USERNAME  = 'lp_cargo_admin_username';
    const OPT_ADMIN_PIN       = 'lp_cargo_admin_pin';
    const OPT_ADMIN_PAGE_TITLE      = 'lp_cargo_admin_page_title';
    const OPT_ADMIN_LOGIN_TITLE     = 'lp_cargo_admin_login_title';
    const OPT_ADMIN_LOGIN_DESC      = 'lp_cargo_admin_login_desc';
    const OPT_ADMIN_LOGIN_BUTTON    = 'lp_cargo_admin_login_button';
    const OPT_ADMIN_SEARCH_TITLE    = 'lp_cargo_admin_search_title';
    const OPT_ADMIN_SEARCH_DESC     = 'lp_cargo_admin_search_desc';
    const OPT_ADMIN_SEARCH_PLACEHOLDER = 'lp_cargo_admin_search_placeholder';
    const OPT_ADMIN_STATUS_PROMPT   = 'lp_cargo_admin_status_prompt';
    const OPT_ADMIN_EMPTY_RESULTS   = 'lp_cargo_admin_empty_results';

    /* -------- Returlogg -------- */
    const LOG_DBVER           = 'lp_cargo_log_dbver';
    const LOG_TABLE           = 'lp_cargo_returns';

    /* -------- Misc -------- */
    const NONCE               = 'lp_cargo_rtn_nonce';
    const ENDPOINT_BASE       = 'https://api.cargonizer.no/';
    const ADMIN_ROUTE_SLUG    = 'mottakretur';

    // Meta på ordre
    const META_LOCKED         = '_lp_return_portal_locked';
    const META_RETURNED_QTY   = '_lp_returned_qty';
    const META_OVERRIDE       = '_lp_return_override';
    const META_CONSIGNMENT_ID = '_lp_cargo_consignment_id';
    const META_LABEL_PUBLIC   = '_lp_cargo_label_public_url';
    const META_LABEL_PRIVATE  = '_lp_cargo_label_private_url';
    const META_LABEL_VALID_TS = '_lp_cargo_label_valid_until';
    const META_LAST_REGEN     = '_lp_cargo_label_last_regen';
    const META_REFUND_METHOD  = '_lp_refund_method'; // 'giftcard' | 'original'
    const META_LOCK_OVERRIDE  = '_lp_return_lock_override';
    const META_PARCEL_SIZE    = '_lp_return_parcel_size';   // small|large|oversize
    const META_RETURN_REASON  = '_lp_return_reason';
    const META_CREATED_TS     = '_lp_return_created_ts';
    const META_ITEM_WKG       = '_lp_wkg';

    // Media Library attachment id for stored label
    const META_LABEL_ATTACHMENT = '_lp_cargo_label_attachment_id';

    /** Runtime cache */
    private $agreements_cache_runtime = null;

    /** For Media Library parenting */
    private $last_order_id_for_attachment = 0;

    /** Siste tildelte fraktfri-bonus (nonce + utløp) */
    private $last_granted_fs = ['nonce' => '', 'until' => 0];

    /** API client */
    private $api_client;

    /** Admin/settings handler */
    private $settings;

    public function __construct() {
        if (!defined('LP_CARGO_VERSION')) define('LP_CARGO_VERSION', LP_CARGO_RETURN_VERSION);
        if (!defined('LP_CARGO_DEBUG')) define('LP_CARGO_DEBUG', false);

        $this->api_client = new LP_Cargonizer_API_Client(
            self::OPT_API_KEY,
            self::OPT_SENDER_ID,
            self::ENDPOINT_BASE,
            [$this, 'log']
        );

        if (!function_exists('esc_xml')) {
            function esc_xml($t){ return wp_specialchars($t, ENT_XML1); }
        }

        // i18n (lett, for framtidig bruk)
        add_action('init', [$this,'load_textdomain']);

        // **Fraktfri 24t – aktivering via GET-token** (må kjøres svært tidlig)
        add_action('init', [$this,'maybe_accept_fs_token'], 1);
        add_action('init', [$this,'register_admin_route']);
        add_filter('query_vars', [$this,'register_query_vars']);
        add_filter('template_include', [$this,'admin_route_template']);

        // Admin
        if (is_admin()) {
            $this->settings = new LP_Cargonizer_Settings($this);
            add_action('wp_ajax_lp_cargo_fetch_agreements', [$this,'ajax_fetch_agreements']);
            add_action('wp_ajax_lp_cargo_refresh_agreements', [$this,'ajax_refresh_agreements']);
            add_action('wp_ajax_lp_cargo_save_allowed',     [$this,'ajax_save_allowed']);   // legacy
            add_action('wp_ajax_lp_cargo_save_services',    [$this,'ajax_save_services']);  // legacy
            add_action('wp_ajax_lp_cargo_save_all',         [$this,'ajax_save_all']);       // bulk save
            add_action('wp_ajax_lp_cargo_admin_unlock_order', [$this,'ajax_admin_unlock_order']);
            add_action('wp_dashboard_setup', [$this,'register_dashboard_widget']);
            add_action('wp_ajax_lp_cargo_test_api', [$this,'ajax_test_api']); // Test API
        }

        // Register shortcode early + enqueue assets when needed
        add_action('init',               [$this,'register_shortcode']);
        add_action('wp_enqueue_scripts', [$this,'maybe_enqueue_assets']);

        // Frontend admin login + search
        add_action('wp_ajax_lp_cargo_admin_login', [$this,'ajax_admin_login']);
        add_action('wp_ajax_nopriv_lp_cargo_admin_login', [$this,'ajax_admin_login']);
        add_action('wp_ajax_lp_cargo_admin_validate', [$this,'ajax_admin_validate']);
        add_action('wp_ajax_nopriv_lp_cargo_admin_validate', [$this,'ajax_admin_validate']);
        add_action('wp_ajax_lp_cargo_admin_search_orders', [$this,'ajax_admin_search_orders']);
        add_action('wp_ajax_nopriv_lp_cargo_admin_search_orders', [$this,'ajax_admin_search_orders']);
        add_action('wp_ajax_lp_cargo_admin_order_details', [$this,'ajax_admin_order_details']);
        add_action('wp_ajax_nopriv_lp_cargo_admin_order_details', [$this,'ajax_admin_order_details']);

        // Label-regen (signed token)
        add_action('wp_ajax_lp_cargo_regen_label',        [$this,'ajax_regen_label']);
        add_action('wp_ajax_nopriv_lp_cargo_regen_label', [$this,'ajax_regen_label']);

        // FS bonus (only if enabled)
        if (get_option(self::OPT_FS_BONUS_ENABLE,'1')==='1') {
            add_action('wp_footer', [$this,'maybe_show_freeship_banner']);
            add_filter('woocommerce_package_rates', [$this,'apply_return_bonus_free_shipping'], 9999, 2);
            add_action('woocommerce_checkout_order_processed', [$this,'maybe_mark_freeship_used'], 10, 3);
            // ✅ Tving valg av fraktfri metode når bonus er aktiv (riktig signatur: 2 parametre)
            add_filter('woocommerce_shipping_chosen_method', [$this,'maybe_force_free_method'], 9999, 2);
        }

        // Cron + DB table
        add_action('init', [$this,'register_events']);
        add_action('lp_cargo_cleanup_labels',  [$this,'cron_cleanup_labels']);
        add_action('lp_cargo_warm_agreements', [$this,'cron_warm_agreements']);
        add_action('init', [$this,'maybe_flush_rewrites']);
        add_filter('request', [$this,'maybe_map_admin_route']);
        add_action('init', [$this,'register_purchase_order_post_type']);

        /** ✅ Alltid registrer returlogg-hook (flyttet ut av DB-opprettelsen) */
        add_action('lp_cargo_return_created', [$this,'log_return_created'], 10, 1);
    }

    public function register_purchase_order_post_type() {
        register_post_type('lp_purchase_order', [
            'labels' => [
                'name'          => 'Purchase Orders',
                'singular_name' => 'Purchase Order',
                'menu_name'     => 'Purchase Orders',
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => 'lp-cargo-returns',
            'supports'     => ['title'],
            'has_archive'  => false,
            'rewrite'      => false,
        ]);
    }

    /**
     * Ensure scheduled events and database tables exist.
     */
    public function register_events(){
        if (!wp_next_scheduled('lp_cargo_cleanup_labels')) {
            wp_schedule_event(time()+HOUR_IN_SECONDS, 'daily', 'lp_cargo_cleanup_labels');
        }
        if (!wp_next_scheduled('lp_cargo_warm_agreements')) {
            wp_schedule_event(time()+5*MINUTE_IN_SECONDS, 'hourly', 'lp_cargo_warm_agreements');
        }
        $this->maybe_create_log_table();
    }

    /** i18n loader (lette forberedelser) */
    public function load_textdomain(){
        load_plugin_textdomain('lp-cargo', false, dirname(plugin_basename(LP_CARGO_RETURN_PLUGIN_FILE)).'/languages');
    }

    /** Minimal logging når debug er på */
    private function log($m){ if (LP_CARGO_DEBUG) error_log('[LP_CARGO] '.$m); }
    private function h($s){ return esc_html($s); }

    /** Registrer shortcode tidlig – alltid */
    public function register_shortcode(){
        add_shortcode('cargonizer_returns', [$this,'render_shortcode']);
        add_shortcode('cargonizer_admin_login', [$this,'render_admin_login_shortcode']);
    }

    /**
     * Last CSS/JS kun når nødvendig:
     * - Oppdager shortcode på singular via has_shortcode
     * - Eller aktivt PRG-step via lp_step/lp_token
     */
    public function maybe_enqueue_assets(){
        if (is_admin()) return;
        $need = false;
        if (!empty($_GET['lp_step']) || !empty($_GET['lp_token'])) {
            $need = true;
        }
        if (!$need && is_singular()) {
            global $post;
            if ($post && function_exists('has_shortcode') && has_shortcode((string)$post->post_content, 'cargonizer_returns')) {
                $need = true;
            }
            if (!$need && $post && function_exists('has_shortcode') && has_shortcode((string)$post->post_content, 'cargonizer_admin_login')) {
                $need = true;
            }
        }
        if ($need) $this->enqueue_assets();
    }

    public function register_admin_route() {
        add_rewrite_rule('^' . self::ADMIN_ROUTE_SLUG . '/?$', 'index.php?lp_cargo_admin=1', 'top');
    }

    public function maybe_map_admin_route($vars) {
        if (is_admin()) {
            return $vars;
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = wp_parse_url($request_uri, PHP_URL_PATH);
        $path = trim((string) $path, '/');

        if ($path === self::ADMIN_ROUTE_SLUG) {
            $vars['lp_cargo_admin'] = 1;
        }

        return $vars;
    }

    public function maybe_flush_rewrites() {
        $stored_version = get_option('lp_cargo_return_version', '');
        if ($stored_version === LP_CARGO_RETURN_VERSION) {
            return;
        }

        $this->register_admin_route();
        flush_rewrite_rules(false);
        update_option('lp_cargo_return_version', LP_CARGO_RETURN_VERSION, false);
    }

    public function register_query_vars($vars) {
        $vars[] = 'lp_cargo_admin';
        return $vars;
    }

    public function admin_route_template($template) {
        if (get_query_var('lp_cargo_admin')) {
            $custom = LP_CARGO_RETURN_PLUGIN_DIR . 'includes/admin-frontend-template.php';
            if (file_exists($custom)) {
                return $custom;
            }
        }
        return $template;
    }

    /* ===================== Admin AJAX: avtaler/tjenester ===================== */

    /** Standard nonce/caps sjekk for admin-AJAX */
    private function check_nonce(){
        if (!current_user_can('manage_woocommerce')) wp_die('Ingen tilgang',403);
        check_ajax_referer(self::NONCE, '_wpnonce');
    }
    public function ajax_fetch_agreements(){
        $this->check_nonce();
        $agreements = $this->get_transport_agreements(false);
        echo $this->render_agreements_markup($agreements);
        wp_die();
    }

    public function ajax_refresh_agreements(){
        $this->check_nonce();
        $this->clear_agreements_cache();
        $agreements = $this->get_transport_agreements(false, true);
        echo $this->render_agreements_markup($agreements);
        wp_die();
    }

    private function render_agreements_markup($agreements){
        if (is_wp_error($agreements)) return '<div class="notice notice-error"><p>'.esc_html($agreements->get_error_message()).'</p></div>';
        $allowed  = (array) get_option(self::OPT_ALLOWED, []);
        $defaults = (array) get_option(self::OPT_DEFAULT_SERV, []);

        ob_start();

        echo '<div class="lp-agree-actions" style="margin:0 0 8px">
        <button type="button" class="button button-primary lp-cargo-agree-save">Lagre valg</button>
        <button type="button" class="button lp-cargo-agree-refresh" style="margin-left:8px">Oppdater avtaler</button>
        <span class="lp-cargo-agree-status lp-small" style="margin-left:8px" aria-live="polite"></span>
      </div>';

        foreach ($agreements as $ag) {
            echo '<div class="postbox" style="padding:10px;margin-bottom:10px">';
            echo '<h3>'. $this->h($ag['carrier_name'].' (Avtale #'.$ag['id'].')') .'</h3><ul style="columns:2;list-style:disc;margin-left:18px">';
            foreach ($ag['products'] as $p) {
                $key = $ag['id'].'|'.$p['id'];
                $checked = in_array($key,$allowed,true)?' checked':'';
                echo '<li style="break-inside:avoid"><label><input type="checkbox" class="lp-cargo-checkbox" value="'.esc_attr($key).'"'.$checked.'> '.$this->h($p['name'].' ['.$p['id'].']').'</label>';
                if (!empty($p['services'])) {
                    $sel = isset($defaults[$key]) ? (array)$defaults[$key] : [];
                    echo '<details class="lp-serv-wrap"><summary class="lp-serv-title">Standard tilleggstjenester <span class="lp-small">(for dette produktet)</span></summary>';
                    foreach ($p['services'] as $svc) {
                        $sid = $svc['id']; $sname=$svc['name'];
                        $svcChecked = in_array($sid,$sel,true)?' checked':'';
                        echo '<label style="display:block"><input class="lp-cargo-serv-cb" data-key="'.esc_attr($key).'" type="checkbox" value="'.esc_attr($sid).'"'.$svcChecked.'> '.$this->h($sname.' ['.$sid.']').'</label>';
                    }
                    echo '</details>';
                }
                echo '</li>';
            }
            echo '</ul></div>';
        }

        echo '<div class="lp-agree-actions" style="margin:8px 0 0">
        <button type="button" class="button button-primary lp-cargo-agree-save">Lagre valg</button>
        <button type="button" class="button lp-cargo-agree-refresh" style="margin-left:8px">Oppdater avtaler</button>
        <span class="lp-cargo-agree-status lp-small" style="margin-left:8px" aria-live="polite"></span>
      </div>';

        return ob_get_clean();
    }

    // Legacy single-change endpoints
    public function ajax_save_allowed(){
        $this->check_nonce();
        $key = sanitize_text_field($_POST['key'] ?? '');
               $checked = sanitize_text_field($_POST['checked'] ?? '0');
        if (!$key) wp_die('Mangler nøkkel',400);
        $list = array_map('strval',(array)get_option(self::OPT_ALLOWED,[]));
        if ($checked==='1') { if(!in_array($key,$list,true)) $list[]=$key; }
        else { $list = array_values(array_filter($list,function($v) use ($key){ return $v!==$key; })); }
        update_option(self::OPT_ALLOWED,$list,false);
        wp_die('OK');
    }

    public function ajax_save_services(){
        $this->check_nonce();
        $key = sanitize_text_field($_POST['key'] ?? '');
        $list = json_decode(wp_unslash($_POST['services'] ?? '[]'), true);
        if (!$key) wp_die('Mangler nøkkel',400);
        if (!is_array($list)) $list=[];
        $map = (array) get_option(self::OPT_DEFAULT_SERV,[]);
        $map[$key] = array_values(array_unique(array_map('strval',$list)));
        update_option(self::OPT_DEFAULT_SERV, $map, false);
        wp_die('OK');
    }

    // NEW: Bulk save med eksplisitt Save
    public function ajax_save_all(){
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['msg'=>'Ingen tilgang'], 403);
        check_ajax_referer(self::NONCE, '_wpnonce');

        $allowed  = json_decode(wp_unslash($_POST['allowed']  ?? '[]'), true);
        $defaults = json_decode(wp_unslash($_POST['defaults'] ?? '{}'), true);
        if (!is_array($allowed))  $allowed=[];
        if (!is_array($defaults)) $defaults=[];

        $allowed = array_values(array_unique(array_map('strval',$allowed)));
        $norm = [];
        foreach ($defaults as $k=>$arr){
            $norm[$k] = array_values(array_unique(array_map('strval',(array)$arr)));
        }

        update_option(self::OPT_ALLOWED, $allowed, false);
        update_option(self::OPT_DEFAULT_SERV, $norm, false);
        wp_send_json_success(['msg'=>'Lagret']);
    }

    public function ajax_admin_unlock_order(){
        if (!current_user_can('manage_woocommerce')) wp_die('Ingen tilgang',403);
        check_ajax_referer(self::NONCE, '_wpnonce');

        $num = ltrim(sanitize_text_field($_POST['order'] ?? ''), '#');
        $oid = absint($num);
        if (!$oid) wp_send_json_error(['msg'=>'Ugyldig ordrenummer']);
        $o = wc_get_order($oid);
        if (!$o) wp_send_json_error(['msg'=>'Ordre ikke funnet']);
        $o->update_meta_data(self::META_LOCK_OVERRIDE, '1');
        $o->save();
        wp_send_json_success(['msg'=>'Lås overstyrt for ordre #'.$oid]);
    }

    /* ===================== Assets (kun når shortcoden trengs) ===================== */
    public function enqueue_assets(){
        // Base CSS
        wp_register_style('lp-cargo-returns', false, [], LP_CARGO_VERSION);
        wp_enqueue_style('lp-cargo-returns');
        $css = <<<CSS
:root{--lp-green:#6FBE3A;}
.lp-wrap{max-width:900px;margin:0 auto;padding:0 14px}
.lp-step{border:1px solid #e5e7eb;padding:16px;margin:14px 0;border-radius:12px;background:#fff;overflow:visible}
.lp-grid{display:grid;gap:12px}
.lp-two{grid-template-columns:1fr 1fr}
@media(max-width:720px){.lp-two{grid-template-columns:1fr}}
.lp-span-all{grid-column:1/-1}
.lp-btn-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.lp-btn{background:var(--lp-green);color:#fff;border:none;padding:12px 16px;border-radius:10px;cursor:pointer;font-weight:700;display:inline-flex;align-items:center;justify-content:center}
.lp-btn[disabled]{opacity:.55;cursor:not-allowed}
.lp-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:0 -8px;padding:0 8px}
.lp-table-responsive{min-width:520px;width:100%}
.lp-input,.lp-select,textarea{width:100%;padding:12px;border:1px solid #d1d5db;border-radius:10px;font-size:16px;background:#fff}
.lp-input:focus,.lp-select:focus,textarea:focus{outline:none;border-color:var(--lp-green);box-shadow:0 0 0 3px rgba(111,190,58,.15)}
.lp-label{font-weight:700;margin-bottom:6px;display:block}
.lp-admin-card{border:1px solid #e5e7eb;border-radius:16px;padding:20px;background:#fff;box-shadow:0 10px 25px rgba(15,23,42,.06)}
.lp-admin-title{font-size:22px;font-weight:800;margin:0 0 6px}
.lp-admin-sub{margin:0 0 18px;color:#64748b}
.lp-admin-form{display:grid;gap:14px}
.lp-admin-actions{display:flex;flex-direction:column;gap:10px}
.lp-admin-note{font-size:13px;color:#6b7280}
.lp-admin-search{display:none;margin-top:18px}
.lp-admin-search.is-active{display:block}
.lp-admin-toolbar{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}
.lp-admin-toolbar .lp-input{min-width:240px}
.lp-admin-results{display:grid;gap:14px;margin-top:16px}
.lp-admin-order{border:1px solid #e5e7eb;border-radius:16px;padding:16px;background:#fff;box-shadow:0 6px 20px rgba(15,23,42,.06);position:relative;overflow:hidden}
.lp-admin-order:before{content:"";position:absolute;left:0;top:0;bottom:0;width:5px;background:#e2e8f0}
.lp-admin-order-head{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start}
.lp-admin-order-title{font-weight:800;font-size:16px}
.lp-admin-order-date{color:#6b7280;font-size:13px}
.lp-admin-order-meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px}
.lp-admin-order-badge{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:700;border:1px solid #e2e8f0;background:#f8fafc;color:#0f172a}
.lp-admin-order-badge.is-status{background:#f1f5f9;border-color:#e2e8f0;color:#0f172a}
.lp-admin-order-badge.is-return{background:#ecfdf3;border-color:#86efac;color:#166534}
.lp-admin-order-actions{display:flex;gap:8px;flex-wrap:wrap}
.lp-admin-order-toggle{border:1px solid #e2e8f0;background:#fff;color:#0f172a;padding:8px 12px;border-radius:10px;font-weight:700;cursor:pointer;transition:all .2s ease}
.lp-admin-order-toggle:hover{transform:translateY(-1px);box-shadow:0 6px 14px rgba(15,23,42,.12)}
.lp-admin-order-grid{display:grid;gap:10px;margin-top:12px}
.lp-admin-order-row{display:grid;gap:4px}
.lp-admin-order-label{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;font-weight:700}
.lp-admin-order-value{font-size:15px;color:#111827}
.lp-admin-products{display:grid;gap:4px}
.lp-admin-return-list{display:grid;gap:4px}
.lp-admin-return-line{font-size:14px}
.lp-admin-status{font-size:13px;color:#6b7280}
.lp-admin-login{display:block}
.lp-admin-login.is-hidden{display:none}
.lp-admin-order-detail{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);padding:24px;z-index:9999;align-items:flex-start;justify-content:center;overflow:auto}
.lp-admin-order-detail.is-open{display:flex}
.lp-admin-detail-modal{width:100%;max-width:900px;background:#fff;border-radius:18px;box-shadow:0 20px 60px rgba(15,23,42,.24);overflow:hidden;border:1px solid #e2e8f0}
.lp-admin-detail-head{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid #e5e7eb;background:#f8fafc}
.lp-admin-detail-heading{font-weight:800;font-size:16px;color:#0f172a}
.lp-admin-detail-close{border:1px solid #e2e8f0;background:#fff;color:#0f172a;border-radius:10px;padding:6px 10px;font-weight:700;cursor:pointer}
.lp-admin-detail-body{padding:18px 20px;display:grid;gap:16px;max-height:calc(100vh - 140px);overflow:auto}
.lp-admin-detail-grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr))}
.lp-admin-detail-card{border:1px solid #e2e8f0;border-radius:12px;padding:12px;background:#fff}
.lp-admin-detail-title{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#64748b;font-weight:700;margin-bottom:6px}
.lp-admin-detail-text{font-size:14px;color:#0f172a;word-break:break-word}
.lp-admin-detail-text a{color:#0f172a;text-decoration:underline}
.lp-admin-items{display:grid;gap:10px;margin-top:8px}
.lp-admin-item{display:grid;grid-template-columns:56px 1fr auto;gap:12px;align-items:center;border:1px solid #e2e8f0;border-radius:12px;padding:10px;background:#fff}
.lp-admin-item-img{width:56px;height:56px;border-radius:10px;object-fit:cover;border:1px solid #e2e8f0;background:#f8fafc}
.lp-admin-item-name{font-weight:700;font-size:14px}
.lp-admin-item-meta{font-size:12px;color:#64748b}
.lp-admin-item-price{font-weight:700;font-size:14px;color:#0f172a;white-space:nowrap}
.lp-admin-order-total{display:flex;justify-content:space-between;align-items:center;margin-top:12px;border-top:1px dashed #e5e7eb;padding-top:12px;font-weight:800}
.lp-admin-return{border:1px solid #e2e8f0;border-radius:14px;padding:14px;background:#f8fafc;display:grid;gap:12px}
.lp-admin-return-title{font-weight:800;font-size:15px;color:#0f172a}
.lp-admin-return-items{display:grid;gap:6px}
.lp-admin-return-items .lp-admin-return-line{font-size:14px;color:#111827}
.lp-progress{display:flex;height:8px;background:#edf1ee;border-radius:999px;overflow:hidden}
.lp-progress>span{display:block;background:var(--lp-green);width:0;transition:width .25s ease}
.lp-help{color:#6b7280;font-size:14px}
.lp-alert{border:1px solid #fecaca;background:#fff5f5;color:#991b1b;border-radius:12px;padding:12px;margin-bottom:10px}
.lp-note{font-size:14px;color:#374151;margin-top:10px}
.lp-radio-card{border:2px solid #e5e7eb;border-radius:12px;padding:12px;cursor:pointer;margin-bottom:8px;display:flex;gap:10px;align-items:flex-start}
.lp-radio-card.active{border-color:var(--lp-green);background:#f6fbf2}
.lp-radio-row{border:2px solid #e5e7eb;border-radius:12px;padding:12px;cursor:pointer;margin-bottom:8px;display:flex;gap:10px;align-items:flex-start}
.lp-radio-row.active{border-color:var(--lp-green);background:#f6fbf2}
.lp-fs-banner{position:fixed;left:0;right:0;bottom:0;z-index:9999;background:%BGCOL%;color:#fff;padding:10px 14px;font-weight:700;display:flex;gap:10px;align-items:center;justify-content:center;box-shadow:0 -6px 20px rgba(0,0,0,.08)}
.lp-fs-close{all:unset;cursor:pointer;font-size:22px;line-height:1;margin-right:8px;padding:0 6px}
.lp-fs-marquee{white-space:nowrap;overflow:hidden;mask-image:linear-gradient(90deg,transparent 0,black 10%,black 90%,transparent 100%)}
.lp-fs-marquee span{display:inline-block;animation:lpfs 18s linear infinite}
@keyframes lpfs{0%{transform:translateX(0)}100%{transform:translateX(-50%)}}
.lp-body-pad{padding-bottom:56px !important}
.lp-muted{color:#6b7280}
.lp-calc{border:1px dashed #cbd5e1;border-radius:10px;padding:12px;margin-top:8px;background:#f8fafc}
.lp-sm-img{display:flex;gap:10px;align-items:center}
.lp-sm-img img{width:48px;height:48px;border-radius:8px;object-fit:cover;border:1px solid #e5e7eb;background:#fff}
.lp-badge{display:inline-block;font-size:12px;font-weight:700;border-radius:999px;padding:2px 8px;border:1px solid #bfdbfe;background:#eff6ff;vertical-align:middle;margin-left:8px}
@media(max-width:540px){.lp-btn-row{flex-direction:column}.lp-btn{width:100%}.lp-table-wrap{margin:0;padding:0}}
@media(max-width:720px){.lp-admin-card{padding:16px}.lp-admin-title{font-size:20px}.lp-admin-toolbar{flex-direction:column;align-items:stretch}.lp-admin-order-actions{width:100%}.lp-admin-order-toggle{width:100%;justify-content:center}.lp-admin-item{grid-template-columns:48px 1fr}.lp-admin-order-detail{padding:12px}.lp-admin-detail-body{padding:14px}}
CSS;
        $css = str_replace('%BGCOL%', esc_attr(get_option(self::OPT_FS_BANNER_COLOR,'#0ea5e9')), $css);
        wp_add_inline_style('lp-cargo-returns',$css);
    }

    /* ===== PRG helpers (Post/Redirect/Get) ===== */
    private function prg_save_state(array $state){
        $token = wp_generate_password(20,false,false);
        set_transient('lp_rtn_state_'.$token, $state, 30*MINUTE_IN_SECONDS);
        return $token;
    }
    private function prg_load_state($token){
        if (!$token) return null;
        return get_transient('lp_rtn_state_'.$token) ?: null;
    }

    private function admin_token_key($token){
        return 'lp_cargo_admin_token_'.$token;
    }

    private function refresh_admin_token($token){
        if (!$token) return;
        set_transient($this->admin_token_key($token), time(), 30*DAY_IN_SECONDS);
    }

    private function validate_admin_token($token){
        if (!$token) return false;
        return (bool) get_transient($this->admin_token_key($token));
    }

    private function get_return_log_entry($order_id){
        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE order_id = %d ORDER BY created DESC LIMIT 1", (int) $order_id),
            ARRAY_A
        );
    }

    public function ajax_admin_login(){
        if (!function_exists('wc_get_order')) wp_send_json_error(['msg'=>'WooCommerce kreves.'], 400);
        $username = sanitize_text_field($_POST['username'] ?? '');
        $pin = sanitize_text_field($_POST['pin'] ?? '');
        $stored_user = (string) get_option(self::OPT_ADMIN_USERNAME, '');
        $stored_pin = (string) get_option(self::OPT_ADMIN_PIN, '');

        if ($stored_user === '' || $stored_pin === '') {
            wp_send_json_error(['msg'=>'Admin-innlogging er ikke konfigurert.'], 400);
        }

        if (!hash_equals($stored_user, $username) || !hash_equals($stored_pin, $pin)) {
            wp_send_json_error(['msg'=>'Ugyldig brukernavn eller PIN.'], 403);
        }

        $token = wp_generate_password(32, false, false);
        $this->refresh_admin_token($token);
        wp_send_json_success(['token'=>$token]);
    }

    public function ajax_admin_validate(){
        $token = sanitize_text_field($_POST['token'] ?? '');
        if (!$this->validate_admin_token($token)) {
            wp_send_json_error(['msg'=>'Ugyldig sesjon.'], 403);
        }
        $this->refresh_admin_token($token);
        wp_send_json_success(['ok'=>true]);
    }

    public function ajax_admin_search_orders(){
        if (!function_exists('wc_get_order')) wp_send_json_error(['msg'=>'WooCommerce kreves.'], 400);
        $token = sanitize_text_field($_POST['token'] ?? '');
        if (!$this->validate_admin_token($token)) {
            wp_send_json_error(['msg'=>'Ugyldig sesjon.'], 403);
        }
        $this->refresh_admin_token($token);

        $query = trim(preg_replace('/\s+/', ' ', sanitize_text_field($_POST['query'] ?? '')));
        if (strlen($query) > 100) {
            $query = substr($query, 0, 100);
        }
        if ($query === '') {
            wp_send_json_success(['orders'=>[]]);
        }

        $default_statuses = array_keys(wc_get_order_statuses());
        $default_statuses = array_map(function($status){
            return preg_replace('/^wc-/', '', $status);
        }, $default_statuses);
        $statuses = (array) apply_filters('lp_cargo_admin_search_statuses', $default_statuses);
        $statuses = (array) apply_filters('lp_cargo_admin_open_statuses', $statuses);
        $statuses = array_values(array_filter(array_map('sanitize_key', $statuses)));
        if (!$statuses) {
            $statuses = $default_statuses;
        }
        $limit = (int) apply_filters('lp_cargo_admin_search_limit', 50);
        $orders = [];

        $numeric_query = preg_replace('/\D+/', '', $query);
        $do_name_search = !ctype_digit($query);
        if (!$do_name_search) {
            $order_id = absint($query);
            if ($order_id) {
                $order = wc_get_order($order_id);
                if ($order && in_array($order->get_status(), $statuses, true)) {
                    $orders[$order->get_id()] = $order;
                }
            }
        } elseif ($numeric_query !== '') {
            $order_id = absint($numeric_query);
            if ($order_id) {
                $order = wc_get_order($order_id);
                if ($order && in_array($order->get_status(), $statuses, true)) {
                    $orders[$order->get_id()] = $order;
                }
            }
        }

        $number_values = [$query];
        if ($numeric_query !== '' && $numeric_query !== $query) {
            $number_values[] = $numeric_query;
        }

        $number_meta_query = ['relation' => 'OR'];
        foreach ($number_values as $number_value) {
            $number_meta_query[] = [
                'key' => '_order_number',
                'value' => $number_value,
                'compare' => 'LIKE',
            ];
        }

        $number_matches = wc_get_orders([
            'status' => $statuses,
            'limit' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => $number_meta_query,
        ]);

        foreach ($number_matches as $order) {
            $orders[$order->get_id()] = $order;
        }

        if ($do_name_search) {
            $search_term = $query;
            if (strpos($search_term, '*') === false) {
                $search_term = '*' . $search_term . '*';
            }

            $name_matches = wc_get_orders([
                'status' => $statuses,
                'limit' => $limit,
                'orderby' => 'date',
                'order' => 'DESC',
                'search' => $search_term,
                'search_columns' => [
                    'billing_first_name',
                    'billing_last_name',
                    'shipping_first_name',
                    'shipping_last_name',
                ],
            ]);

            foreach ($name_matches as $order) {
                $orders[$order->get_id()] = $order;
            }
        }

        $list = array_values($orders);
        usort($list, function($a, $b){
            $ad = $a->get_date_created() ? $a->get_date_created()->getTimestamp() : 0;
            $bd = $b->get_date_created() ? $b->get_date_created()->getTimestamp() : 0;
            return $bd <=> $ad;
        });

        if ($limit > 0 && count($list) > $limit) {
            $list = array_slice($list, 0, $limit);
        }

        $data = [];
        foreach ($list as $order) {
            $items = [];
            $return_items = [];
            $returned_map = (array) $order->get_meta(self::META_RETURNED_QTY);
            $return_total = 0;
            foreach ($order->get_items() as $item) {
                $item_id = $item->get_id();
                $returned_qty = (int) ($returned_map[$item_id] ?? 0);
                if ($returned_qty > 0) {
                    $return_items[] = [
                        'name' => $item->get_name(),
                        'qty' => $returned_qty,
                    ];
                    $return_total += $returned_qty;
                }
                $items[] = [
                    'name' => $item->get_name(),
                    'qty' => $item->get_quantity(),
                ];
            }
            $customer = $order->get_formatted_billing_full_name();
            if (!$customer) {
                $customer = $order->get_formatted_shipping_full_name();
            }
            if (!$customer) {
                $customer = __('Ukjent kunde', 'lp-cargo');
            }
            $date = $order->get_date_created();
            $status_key = $order->get_status();
            $status_label = wc_get_order_status_name('wc-' . $status_key);
            $created_ts = (int) $order->get_meta(self::META_CREATED_TS);
            $return_registered = ($return_total > 0 || $created_ts > 0);
            $data[] = [
                'id' => $order->get_id(),
                'number' => $order->get_order_number(),
                'customer_name' => $customer,
                'products' => $items,
                'return_items' => $return_items,
                'return_total' => $return_total,
                'return_registered' => $return_registered,
                'order_date' => $date ? $date->date_i18n(get_option('date_format').' '.get_option('time_format')) : '',
                'order_value' => wp_strip_all_tags(wc_price($order->get_total(), ['currency' => $order->get_currency()])),
                'shipping_method' => $order->get_shipping_method() ?: __('Ikke valgt', 'lp-cargo'),
                'status_key' => $status_key,
                'status_label' => $status_label,
            ];
        }

        wp_send_json_success(['orders'=>$data]);
    }

    public function ajax_admin_order_details(){
        if (!function_exists('wc_get_order')) wp_send_json_error(['msg'=>'WooCommerce kreves.'], 400);
        $token = sanitize_text_field($_POST['token'] ?? '');
        if (!$this->validate_admin_token($token)) {
            wp_send_json_error(['msg'=>'Ugyldig sesjon.'], 403);
        }
        $this->refresh_admin_token($token);

        $order_id = absint($_POST['order_id'] ?? 0);
        if (!$order_id) {
            wp_send_json_error(['msg'=>'Ugyldig ordre.'], 400);
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['msg'=>'Ordre ikke funnet.'], 404);
        }

        $items = [];
        $returned_map = (array) $order->get_meta(self::META_RETURNED_QTY);
        $return_items = [];
        $return_total = 0;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $image_id = $product ? $product->get_image_id() : 0;
            $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src();
            $qty = (int) $item->get_quantity();
            $item_id = $item->get_id();
            $returned_qty = (int) ($returned_map[$item_id] ?? 0);
            $line_total = (float) $item->get_total();
            $unit_price = $qty > 0 ? $line_total / $qty : $line_total;
            if ($returned_qty > 0) {
                $return_items[] = [
                    'name' => $item->get_name(),
                    'qty' => $returned_qty,
                ];
                $return_total += $returned_qty;
            }
            $items[] = [
                'name' => $item->get_name(),
                'qty' => $qty,
                'returned_qty' => $returned_qty,
                'image' => $image_url,
                'line_total' => wp_strip_all_tags(wc_price($line_total, ['currency' => $order->get_currency()])),
                'unit_price' => wp_strip_all_tags(wc_price($unit_price, ['currency' => $order->get_currency()])),
            ];
        }

        $status_key = $order->get_status();
        $status_label = wc_get_order_status_name('wc-' . $status_key);
        $date = $order->get_date_created();
        $return_created_ts = (int) $order->get_meta(self::META_CREATED_TS);
        $return_reason = (string) $order->get_meta(self::META_RETURN_REASON);
        $return_refund_method = (string) $order->get_meta(self::META_REFUND_METHOD);
        $return_parcel_size = (string) $order->get_meta(self::META_PARCEL_SIZE);
        $return_carrier = (string) $order->get_meta('_lp_carrier_group');
        $return_label = (string) ($order->get_meta(self::META_LABEL_PUBLIC) ?: $order->get_meta(self::META_LABEL_PRIVATE));
        $log_entry = $this->get_return_log_entry($order_id);
        $tracking_url = $log_entry['tracking_url'] ?? '';
        $return_notes = $log_entry['notes'] ?? '';
        $estimated_refund = (float) $order->get_meta('_lp_estimated_refund');
        $return_registered = ($return_total > 0 || $return_created_ts > 0);
        $data = [
            'id' => $order->get_id(),
            'number' => $order->get_order_number(),
            'status_key' => $status_key,
            'status_label' => $status_label,
            'order_date' => $date ? $date->date_i18n(get_option('date_format').' '.get_option('time_format')) : '',
            'customer_name' => $order->get_formatted_billing_full_name() ?: $order->get_formatted_shipping_full_name(),
            'customer_email' => $order->get_billing_email(),
            'customer_phone' => $order->get_billing_phone(),
            'billing_address' => $order->get_formatted_billing_address(),
            'shipping_address' => $order->get_formatted_shipping_address(),
            'payment_method' => $order->get_payment_method_title(),
            'shipping_method' => $order->get_shipping_method() ?: __('Ikke valgt', 'lp-cargo'),
            'order_total' => wp_strip_all_tags(wc_price($order->get_total(), ['currency' => $order->get_currency()])),
            'order_subtotal' => wp_strip_all_tags(wc_price($order->get_subtotal(), ['currency' => $order->get_currency()])),
            'order_tax' => wp_strip_all_tags(wc_price($order->get_total_tax(), ['currency' => $order->get_currency()])),
            'shipping_total' => wp_strip_all_tags(wc_price($order->get_shipping_total(), ['currency' => $order->get_currency()])),
            'items' => $items,
            'return' => [
                'registered' => $return_registered,
                'items_total' => $return_total,
                'items' => $return_items,
                'created' => $return_created_ts ? date_i18n(get_option('date_format').' '.get_option('time_format'), $return_created_ts) : '',
                'reason' => $return_reason,
                'refund_method' => $return_refund_method ? $this->refund_label($return_refund_method) : '',
                'parcel_size' => $return_parcel_size,
                'carrier' => $return_carrier,
                'label_url' => esc_url_raw($return_label),
                'tracking_url' => esc_url_raw($tracking_url),
                'notes' => $return_notes,
                'estimated_refund' => $estimated_refund > 0 ? wp_strip_all_tags(wc_price($estimated_refund, ['currency' => $order->get_currency()])) : '',
            ],
        ];

        wp_send_json_success(['order' => $data]);
    }

    public function ajax_test_api(){
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['msg'=>'Ingen tilgang'], 403);
        check_ajax_referer(self::NONCE, '_wpnonce');

        // Les usavede verdier fra request
        $keyReq    = trim(sanitize_text_field($_POST['key'] ?? ''));
        $senderReq = trim(sanitize_text_field($_POST['sender'] ?? ''));

        $creds = $this->api_client->require_api_credentials($keyReq ?: null, $senderReq ?: null);
        if (is_wp_error($creds)) {
            wp_send_json_error(['msg'=>$creds->get_error_message()], 400);
        }

        if (defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL) {
            $allowed = defined('WP_ACCESSIBLE_HOSTS') ? WP_ACCESSIBLE_HOSTS : '';
            if (stripos($allowed,'api.cargonizer.no')===false) {
                wp_send_json_error(['msg'=>'WP blokkerer utgående forespørsler. Legg api.cargonizer.no i WP_ACCESSIBLE_HOSTS i wp-config.php.'], 400);
            }
        }

        $url = rtrim($this->api_client->get_endpoint_base(),'/').'/transport_agreements.xml';
        $args = [
            'method'  => 'GET',
            'headers' => $this->api_client->api_headers('application/xml', $creds['key'], $creds['sender']),
            'timeout' => 20,
            'redirection' => 2,
        ];
        $res = wp_remote_request($url, $args);

        if (is_wp_error($res)) {
            $msg = $this->api_client->diagnose_http_error(0, $res->get_error_message(), '');
            wp_send_json_error(['msg'=>$msg], 500);
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $body = (string) wp_remote_retrieve_body($res);
        if ($code>=200 && $code<300) {
            wp_send_json_success(['code'=>$code]);
        } else {
            $msg = $this->api_client->diagnose_http_error($code, '', $body);
            wp_send_json_error(['msg'=>$msg, 'code'=>$code], 500);
        }
    }

    public function render_admin_login_shortcode() {
        if (!headers_sent()) {
            nocache_headers();
        }

        $username_hint = get_option(self::OPT_ADMIN_USERNAME, '');
        $username_placeholder = $username_hint !== '' ? $username_hint : __('Skriv inn brukernavn', 'lp-cargo');
        $ajax_url = admin_url('admin-ajax.php');
        $login_title = get_option(self::OPT_ADMIN_LOGIN_TITLE, __('Admin Login', 'lp-cargo'));
        $login_desc = get_option(self::OPT_ADMIN_LOGIN_DESC, __('Logg inn for å søke opp ordre.', 'lp-cargo'));
        $login_button = get_option(self::OPT_ADMIN_LOGIN_BUTTON, __('Logg inn', 'lp-cargo'));
        $search_title = get_option(self::OPT_ADMIN_SEARCH_TITLE, __('Ordresøk', 'lp-cargo'));
        $search_desc = get_option(self::OPT_ADMIN_SEARCH_DESC, __('Søk på ordrenummer eller kundenavn. Viser ordre sortert etter ordredato.', 'lp-cargo'));
        $search_placeholder = get_option(self::OPT_ADMIN_SEARCH_PLACEHOLDER, __('Ordrenummer eller kundenavn', 'lp-cargo'));
        $status_prompt = get_option(self::OPT_ADMIN_STATUS_PROMPT, __('Logg inn for å søke etter ordre.', 'lp-cargo'));
        $empty_results = get_option(self::OPT_ADMIN_EMPTY_RESULTS, __('Ingen ordre funnet for dette søket.', 'lp-cargo'));

        ob_start();
        echo '<div class="lp-wrap">';
        echo '<div class="lp-admin-card lp-admin-login" id="lp-admin-login">';
        echo '<h2 class="lp-admin-title">' . esc_html( $login_title ) . '</h2>';
        echo '<p class="lp-admin-sub">' . esc_html( $login_desc ) . '</p>';
        echo '<form class="lp-admin-form" autocomplete="off" novalidate>';
        echo '<div>';
        echo '<label class="lp-label" for="lp-admin-username">' . esc_html__('Brukernavn', 'lp-cargo') . '</label>';
        echo '<input class="lp-input" type="text" id="lp-admin-username" name="lp-admin-username" inputmode="text" placeholder="' . esc_attr($username_placeholder) . '">';
        echo '</div>';
        echo '<div>';
        echo '<label class="lp-label" for="lp-admin-pin">' . esc_html__('PIN-kode', 'lp-cargo') . '</label>';
        echo '<input class="lp-input" type="password" id="lp-admin-pin" name="lp-admin-pin" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="' . esc_attr__('4 siffer', 'lp-cargo') . '" aria-describedby="lp-admin-pin-help">';
        echo '<span class="lp-admin-note" id="lp-admin-pin-help">' . esc_html__('PIN må være 4 siffer.', 'lp-cargo') . '</span>';
        echo '</div>';
        echo '<div>';
        echo '<label class="lp-admin-note"><input type="checkbox" id="lp-admin-remember" checked> ' . esc_html__('Husk innlogging på denne enheten', 'lp-cargo') . '</label>';
        echo '</div>';
        echo '<div class="lp-admin-actions">';
        echo '<button type="button" class="lp-btn" id="lp-admin-submit">' . esc_html( $login_button ) . '</button>';
        echo '<span class="lp-admin-note" id="lp-admin-error" role="alert"></span>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
        echo '<div class="lp-admin-card lp-admin-search" id="lp-admin-search">';
        echo '<h2 class="lp-admin-title">' . esc_html( $search_title ) . '</h2>';
        echo '<p class="lp-admin-sub">' . esc_html( $search_desc ) . '</p>';
        echo '<div class="lp-admin-toolbar">';
        echo '<div style="flex:1 1 260px">';
        echo '<label class="lp-label" for="lp-admin-query">' . esc_html__('Søk', 'lp-cargo') . '</label>';
        echo '<input class="lp-input" type="search" id="lp-admin-query" name="lp-admin-query" placeholder="' . esc_attr( $search_placeholder ) . '" inputmode="search" autocomplete="off">';
        echo '</div>';
        echo '</div>';
        echo '<div class="lp-admin-status" id="lp-admin-status" aria-live="polite"></div>';
        echo '<div class="lp-admin-results" id="lp-admin-results"></div>';
        echo '</div>';
        echo '</div>';
        echo '<script>
(function(){
    const ajaxUrl = ' . json_encode($ajax_url) . ';
    const loginCard = document.getElementById("lp-admin-login");
    const searchCard = document.getElementById("lp-admin-search");
    const loginBtn = document.getElementById("lp-admin-submit");
    const usernameInput = document.getElementById("lp-admin-username");
    const pinInput = document.getElementById("lp-admin-pin");
    const rememberInput = document.getElementById("lp-admin-remember");
    const statusEl = document.getElementById("lp-admin-status");
    const resultsEl = document.getElementById("lp-admin-results");
    const queryInput = document.getElementById("lp-admin-query");
    const storageKey = "lpCargoAdminToken";
    let sessionToken = null;
    let activeController = null;
    let debounceTimer = null;
    const detailsCache = new Map();
    const baseUrl = window.location.href.split("#")[0];

    const setStatus = (msg) => { if (statusEl) statusEl.textContent = msg || ""; };
    const setLoginError = (msg) => { const el = document.getElementById("lp-admin-error"); if (el) el.textContent = msg || ""; };
    const showSearch = () => { if (loginCard) loginCard.classList.add("is-hidden"); if (searchCard) searchCard.classList.add("is-active"); if (queryInput) queryInput.focus(); };
    const showLogin = () => { if (loginCard) loginCard.classList.remove("is-hidden"); if (searchCard) searchCard.classList.remove("is-active"); };

    const post = async (action, data) => {
        const form = new URLSearchParams();
        form.append("action", action);
        Object.keys(data).forEach((key) => form.append(key, data[key]));
        const res = await fetch(ajaxUrl, {
            method: "POST",
            headers: {"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},
            body: form.toString()
        });
        const json = await res.json();
        if (!json || !json.success) {
            throw new Error((json && json.data && json.data.msg) ? json.data.msg : "Noe gikk galt.");
        }
        return json.data || {};
    };

    const clearResults = () => { if (resultsEl) resultsEl.innerHTML = ""; };

    const renderDetailCard = (titleText, valueText, useHtml = false) => {
        const card = document.createElement("div");
        card.className = "lp-admin-detail-card";
        const title = document.createElement("div");
        title.className = "lp-admin-detail-title";
        title.textContent = titleText;
        const body = document.createElement("div");
        body.className = "lp-admin-detail-text";
        if (useHtml) {
            body.innerHTML = valueText || "-";
        } else {
            body.textContent = valueText || "-";
        }
        card.appendChild(title);
        card.appendChild(body);
        return card;
    };

    const renderDetailLinkCard = (titleText, url, labelText) => {
        const card = document.createElement("div");
        card.className = "lp-admin-detail-card";
        const title = document.createElement("div");
        title.className = "lp-admin-detail-title";
        title.textContent = titleText;
        const body = document.createElement("div");
        body.className = "lp-admin-detail-text";
        if (url) {
            const link = document.createElement("a");
            link.href = url;
            link.target = "_blank";
            link.rel = "noopener";
            link.textContent = labelText || url;
            body.appendChild(link);
        } else {
            body.textContent = "-";
        }
        card.appendChild(title);
        card.appendChild(body);
        return card;
    };

    const renderOrderDetails = (details, container) => {
        container.innerHTML = "";
        const grid = document.createElement("div");
        grid.className = "lp-admin-detail-grid";
        grid.appendChild(renderDetailCard("Status", details.status_label));
        grid.appendChild(renderDetailCard("Bestilt", details.order_date));
        grid.appendChild(renderDetailCard("Betaling", details.payment_method || "-"));
        grid.appendChild(renderDetailCard("Frakt", details.shipping_method || "-"));
        grid.appendChild(renderDetailCard("Kunde", details.customer_name || "-"));
        grid.appendChild(renderDetailCard("E-post", details.customer_email || "-"));
        grid.appendChild(renderDetailCard("Telefon", details.customer_phone || "-"));
        grid.appendChild(renderDetailCard("Fakturaadresse", details.billing_address || "-", true));
        grid.appendChild(renderDetailCard("Leveringsadresse", details.shipping_address || "-", true));
        grid.appendChild(renderDetailCard("Subtotal", details.order_subtotal || "-"));
        grid.appendChild(renderDetailCard("Fraktkostnad", details.shipping_total || "-"));
        container.appendChild(grid);

        const returnInfo = details.return || {};
        const returnSection = document.createElement("div");
        returnSection.className = "lp-admin-return";
        const returnTitle = document.createElement("div");
        returnTitle.className = "lp-admin-return-title";
        returnTitle.textContent = "Retur";
        returnSection.appendChild(returnTitle);
        if (!returnInfo.registered) {
            const empty = document.createElement("div");
            empty.className = "lp-admin-note";
            empty.textContent = "Ingen retur registrert.";
            returnSection.appendChild(empty);
        } else {
            const returnGrid = document.createElement("div");
            returnGrid.className = "lp-admin-detail-grid";
            returnGrid.appendChild(renderDetailCard("Registrert", returnInfo.created || "Ja"));
            returnGrid.appendChild(renderDetailCard("Antall returnerte varer", returnInfo.items_total ? `${returnInfo.items_total} stk` : "-"));
            returnGrid.appendChild(renderDetailCard("Returårsak", returnInfo.reason || "-"));
            returnGrid.appendChild(renderDetailCard("Refusjon", returnInfo.refund_method || "-"));
            returnGrid.appendChild(renderDetailCard("Pakkestørrelse", returnInfo.parcel_size || "-"));
            returnGrid.appendChild(renderDetailCard("Transportør", returnInfo.carrier || "-"));
            if (returnInfo.estimated_refund) {
                returnGrid.appendChild(renderDetailCard("Estimert refusjon", returnInfo.estimated_refund));
            }
            returnGrid.appendChild(renderDetailLinkCard("Returetikett", returnInfo.label_url, "Åpne etikett"));
            returnGrid.appendChild(renderDetailLinkCard("Sporing", returnInfo.tracking_url, "Åpne sporing"));
            if (returnInfo.notes) {
                returnGrid.appendChild(renderDetailCard("Notat", returnInfo.notes));
            }
            returnSection.appendChild(returnGrid);

            const returnItems = document.createElement("div");
            returnItems.className = "lp-admin-return-items";
            if (returnInfo.items && returnInfo.items.length) {
                returnInfo.items.forEach((item) => {
                    const line = document.createElement("div");
                    line.className = "lp-admin-return-line";
                    line.textContent = `${item.name} × ${item.qty}`;
                    returnItems.appendChild(line);
                });
            } else {
                const emptyReturnItems = document.createElement("div");
                emptyReturnItems.className = "lp-admin-note";
                emptyReturnItems.textContent = "Ingen varer er markert som returnert.";
                returnItems.appendChild(emptyReturnItems);
            }
            returnSection.appendChild(returnItems);
        }
        container.appendChild(returnSection);

        const itemsWrap = document.createElement("div");
        itemsWrap.className = "lp-admin-items";
        if (details.items && details.items.length) {
            details.items.forEach((item) => {
                const row = document.createElement("div");
                row.className = "lp-admin-item";
                const img = document.createElement("img");
                img.className = "lp-admin-item-img";
                img.src = item.image || "";
                img.alt = item.name || "";
                const info = document.createElement("div");
                const name = document.createElement("div");
                name.className = "lp-admin-item-name";
                name.textContent = item.name || "-";
                const meta = document.createElement("div");
                meta.className = "lp-admin-item-meta";
                const metaParts = [`${item.qty} × ${item.unit_price}`];
                if (item.returned_qty && item.returned_qty > 0) {
                    metaParts.push(`Returnert: ${item.returned_qty}`);
                }
                meta.textContent = metaParts.join(" · ");
                info.appendChild(name);
                info.appendChild(meta);
                const price = document.createElement("div");
                price.className = "lp-admin-item-price";
                price.textContent = item.line_total || "-";
                row.appendChild(img);
                row.appendChild(info);
                row.appendChild(price);
                itemsWrap.appendChild(row);
            });
        } else {
            const empty = document.createElement("div");
            empty.className = "lp-admin-note";
            empty.textContent = "Ingen produkter funnet.";
            itemsWrap.appendChild(empty);
        }
        container.appendChild(itemsWrap);

        const totals = document.createElement("div");
        totals.className = "lp-admin-order-total";
        const totalsLabel = document.createElement("div");
        totalsLabel.textContent = "Ordretotal";
        const totalsValue = document.createElement("div");
        totalsValue.textContent = details.order_total || "-";
        totals.appendChild(totalsLabel);
        totals.appendChild(totalsValue);
        container.appendChild(totals);
    };

    const toggleOrderDetail = async (orderId, button, detailEl, skipHistory = false) => {
        if (!detailEl) return;
        const isOpen = detailEl.classList.contains("is-open");
        if (isOpen) {
            detailEl.classList.remove("is-open");
            detailEl.setAttribute("aria-hidden", "true");
            button.textContent = "Åpne ordre";
            if (!skipHistory) {
                history.pushState({}, "", baseUrl);
            }
            return;
        }
        if (resultsEl) {
            const openDetails = resultsEl.querySelectorAll(".lp-admin-order-detail.is-open");
            openDetails.forEach((detail) => {
                detail.classList.remove("is-open");
                detail.setAttribute("aria-hidden", "true");
                const btn = detail.parentElement ? detail.parentElement.querySelector(".lp-admin-order-toggle") : null;
                if (btn) btn.textContent = "Åpne ordre";
            });
        }
        detailEl.classList.add("is-open");
        detailEl.setAttribute("aria-hidden", "false");
        button.textContent = "Lukk ordre";
        if (!skipHistory) {
            history.pushState({orderId}, "", `${baseUrl}#order-${orderId}`);
        }
        const detailBody = detailEl.querySelector(".lp-admin-detail-body") || detailEl;

        if (detailsCache.has(orderId)) {
            renderOrderDetails(detailsCache.get(orderId), detailBody);
            return;
        }
        detailBody.innerHTML = "<div class=\"lp-admin-note\">Laster ordre...</div>";
        try {
            const data = await post("lp_cargo_admin_order_details", {token: sessionToken, order_id: orderId});
            if (data && data.order) {
                detailsCache.set(orderId, data.order);
                renderOrderDetails(data.order, detailBody);
            }
        } catch (err) {
            detailBody.innerHTML = "<div class=\"lp-admin-note\">Kunne ikke hente ordre.</div>";
        }
    };

    const renderResults = (orders) => {
        clearResults();
        if (!resultsEl) return;
        if (!orders.length) {
            setStatus(' . json_encode( $empty_results ) . ');
            return;
        }
        setStatus(`Viser ${orders.length} ordre.`);
        orders.forEach((order) => {
            const card = document.createElement("div");
            card.className = "lp-admin-order";

            const head = document.createElement("div");
            head.className = "lp-admin-order-head";
            const title = document.createElement("div");
            title.className = "lp-admin-order-title";
            title.textContent = `${order.customer_name} · #${order.number}`;
            const date = document.createElement("div");
            date.className = "lp-admin-order-date";
            date.textContent = order.order_date || "";
            const meta = document.createElement("div");
            meta.className = "lp-admin-order-meta";
            if (order.status_label) {
                const statusBadge = document.createElement("div");
                statusBadge.className = "lp-admin-order-badge is-status";
                statusBadge.textContent = order.status_label;
                meta.appendChild(statusBadge);
            }
            if (order.return_registered) {
                const returnBadge = document.createElement("div");
                returnBadge.className = "lp-admin-order-badge is-return";
                returnBadge.textContent = order.return_total > 0 ? `Retur: ${order.return_total} varer` : "Retur registrert";
                meta.appendChild(returnBadge);
            }
            if (order.order_value) {
                const totalBadge = document.createElement("div");
                totalBadge.className = "lp-admin-order-badge";
                totalBadge.textContent = `Total: ${order.order_value}`;
                meta.appendChild(totalBadge);
            }
            if (order.shipping_method) {
                const shipBadge = document.createElement("div");
                shipBadge.className = "lp-admin-order-badge";
                shipBadge.textContent = order.shipping_method;
                meta.appendChild(shipBadge);
            }

            const actions = document.createElement("div");
            actions.className = "lp-admin-order-actions";
            const toggleBtn = document.createElement("button");
            toggleBtn.type = "button";
            toggleBtn.className = "lp-admin-order-toggle";
            toggleBtn.textContent = "Åpne ordre";
            actions.appendChild(toggleBtn);
            head.appendChild(title);
            head.appendChild(date);
            head.appendChild(actions);

            const grid = document.createElement("div");
            grid.className = "lp-admin-order-grid";

            const productsRow = document.createElement("div");
            productsRow.className = "lp-admin-order-row";
            const productsLabel = document.createElement("div");
            productsLabel.className = "lp-admin-order-label";
            productsLabel.textContent = "Produkter";
            const productsValue = document.createElement("div");
            productsValue.className = "lp-admin-order-value lp-admin-products";
            if (order.products && order.products.length) {
                order.products.forEach((item) => {
                    const line = document.createElement("div");
                    line.textContent = `${item.name} × ${item.qty}`;
                    productsValue.appendChild(line);
                });
            } else {
                productsValue.textContent = "Ingen produkter funnet.";
            }
            productsRow.appendChild(productsLabel);
            productsRow.appendChild(productsValue);
            grid.appendChild(productsRow);

            const addRow = (label, value) => {
                const row = document.createElement("div");
                row.className = "lp-admin-order-row";
                const lab = document.createElement("div");
                lab.className = "lp-admin-order-label";
                lab.textContent = label;
                const val = document.createElement("div");
                val.className = "lp-admin-order-value";
                val.textContent = value || "-";
                row.appendChild(lab);
                row.appendChild(val);
                grid.appendChild(row);
            };

            addRow("Ordreverdi", order.order_value);
            addRow("Fraktmetode", order.shipping_method);

            const returnRow = document.createElement("div");
            returnRow.className = "lp-admin-order-row";
            const returnLabel = document.createElement("div");
            returnLabel.className = "lp-admin-order-label";
            returnLabel.textContent = "Retur";
            const returnValue = document.createElement("div");
            returnValue.className = "lp-admin-order-value";
            if (order.return_items && order.return_items.length) {
                const list = document.createElement("div");
                list.className = "lp-admin-return-list";
                order.return_items.forEach((item) => {
                    const line = document.createElement("div");
                    line.className = "lp-admin-return-line";
                    line.textContent = `${item.name} × ${item.qty}`;
                    list.appendChild(line);
                });
                returnValue.appendChild(list);
            } else {
                returnValue.textContent = "Ingen retur registrert.";
            }
            returnRow.appendChild(returnLabel);
            returnRow.appendChild(returnValue);
            grid.appendChild(returnRow);

            const detail = document.createElement("div");
            detail.className = "lp-admin-order-detail";
            detail.setAttribute("data-order-id", order.id);
            detail.setAttribute("aria-hidden", "true");
            const modal = document.createElement("div");
            modal.className = "lp-admin-detail-modal";
            const modalHead = document.createElement("div");
            modalHead.className = "lp-admin-detail-head";
            const modalTitle = document.createElement("div");
            modalTitle.className = "lp-admin-detail-heading";
            modalTitle.textContent = `Ordre #${order.number}`;
            const closeBtn = document.createElement("button");
            closeBtn.type = "button";
            closeBtn.className = "lp-admin-detail-close";
            closeBtn.textContent = "Lukk";
            closeBtn.addEventListener("click", () => toggleOrderDetail(order.id, toggleBtn, detail));
            modalHead.appendChild(modalTitle);
            modalHead.appendChild(closeBtn);
            const modalBody = document.createElement("div");
            modalBody.className = "lp-admin-detail-body";
            modal.appendChild(modalHead);
            modal.appendChild(modalBody);
            detail.appendChild(modal);
            detail.addEventListener("click", (event) => {
                if (event.target === detail) {
                    toggleOrderDetail(order.id, toggleBtn, detail);
                }
            });

            card.appendChild(head);
            card.appendChild(meta);
            card.appendChild(grid);
            card.appendChild(detail);
            resultsEl.appendChild(card);

            toggleBtn.addEventListener("click", () => toggleOrderDetail(order.id, toggleBtn, detail));
        });
    };

    const runSearch = async (value) => {
        if (!sessionToken) return;
        const query = value.trim();
        if (query.length < 2) {
            clearResults();
            setStatus("Skriv minst to tegn for å søke på ordrenummer eller kundenavn.");
            return;
        }
        if (activeController) activeController.abort();
        activeController = new AbortController();
        let timedOut = false;
        const timeoutId = window.setTimeout(() => {
            timedOut = true;
            if (activeController) activeController.abort();
        }, 15000);
        setStatus("Søker...");
        try {
            const form = new URLSearchParams();
            form.append("action", "lp_cargo_admin_search_orders");
            form.append("token", sessionToken);
            form.append("query", query);
            const res = await fetch(ajaxUrl, {
                method: "POST",
                headers: {"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},
                body: form.toString(),
                signal: activeController.signal
            });
            const json = await res.json();
            if (!json || !json.success) {
                throw new Error((json && json.data && json.data.msg) ? json.data.msg : "Kunne ikke hente ordre.");
            }
            renderResults(json.data.orders || []);
        } catch (err) {
            if (err.name === "AbortError") {
                if (timedOut) {
                    setStatus("Søket tok for lang tid. Prøv å spesifisere søket mer.");
                }
                return;
            }
            setStatus(err.message || "Kunne ikke hente ordre.");
        } finally {
            window.clearTimeout(timeoutId);
        }
    };

    const login = async () => {
        setLoginError("");
        const username = (usernameInput && usernameInput.value || "").trim();
        const pin = (pinInput && pinInput.value || "").trim();
        if (!username || !pin) {
            setLoginError("Fyll inn brukernavn og PIN.");
            return;
        }
        if (loginBtn) loginBtn.disabled = true;
        try {
            const data = await post("lp_cargo_admin_login", {username, pin});
            sessionToken = data.token || null;
            if (rememberInput && rememberInput.checked && sessionToken) {
                localStorage.setItem(storageKey, sessionToken);
            }
            showSearch();
            setStatus("Skriv inn et søk for å hente ordre.");
        } catch (err) {
            setLoginError(err.message || "Ugyldig brukernavn eller PIN.");
        } finally {
            if (loginBtn) loginBtn.disabled = false;
        }
    };

    const validateToken = async (token) => {
        try {
            await post("lp_cargo_admin_validate", {token});
            sessionToken = token;
            showSearch();
            setStatus("Skriv inn et søk for å hente ordre.");
        } catch (err) {
            localStorage.removeItem(storageKey);
            showLogin();
        }
    };

    if (loginBtn) {
        loginBtn.addEventListener("click", (event) => {
            event.preventDefault();
            login();
        });
    }
    if (pinInput) {
        pinInput.addEventListener("keydown", (event) => {
            if (event.key === "Enter") {
                event.preventDefault();
                login();
            }
        });
    }
    if (queryInput) {
        queryInput.addEventListener("input", (event) => {
            const value = event.target.value || "";
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => runSearch(value), 250);
        });
    }

    window.addEventListener("popstate", (event) => {
        if (!resultsEl) return;
        const stateOrderId = event.state && event.state.orderId ? event.state.orderId : null;
        const openDetails = resultsEl.querySelectorAll(".lp-admin-order-detail.is-open");
        openDetails.forEach((detail) => {
            detail.classList.remove("is-open");
            detail.setAttribute("aria-hidden", "true");
            const btn = detail.parentElement ? detail.parentElement.querySelector(".lp-admin-order-toggle") : null;
            if (btn) btn.textContent = "Åpne ordre";
        });
        if (stateOrderId) {
            const targetDetail = resultsEl.querySelector(`.lp-admin-order-detail[data-order-id="${stateOrderId}"]`);
            const targetBtn = targetDetail ? targetDetail.parentElement.querySelector(".lp-admin-order-toggle") : null;
            if (targetDetail && targetBtn) {
                toggleOrderDetail(stateOrderId, targetBtn, targetDetail, true);
            }
        }
    });

    const savedToken = localStorage.getItem(storageKey);
    if (savedToken) {
        validateToken(savedToken);
    } else {
        setStatus(' . json_encode( $status_prompt ) . ');
    }
})();
</script>';

        return ob_get_clean();
    }

    public function render_shortcode(){
        if (!function_exists('wc_get_order')) return '<p>WooCommerce kreves.</p>';
        if (!headers_sent()) nocache_headers();

        $fee_small = (int) get_option(self::OPT_FEE_SMALL, 69);
        $fee_large = (int) get_option(self::OPT_FEE_LARGE, 129);
        $label_valid_days = (int) get_option(self::OPT_LABEL_VALID_DAYS, 14);

        $step = isset($_POST['lp_step']) ? (int) $_POST['lp_step'] : ( isset($_GET['lp_step']) ? (int)$_GET['lp_step'] : 1 );
        $errors = [];
        $state  = [
            'order_id'=>0,'email'=>'','lines'=>[],
            'service'=>'','agreement'=>'','parcel_size'=>'small',
            'return_note'=>'','carrier_group'=>'postnord',
            'accept_terms'=>'0','refund_method'=>'giftcard','return_reason'=>'',
        ];

        // PRG token
        if (!empty($_GET['lp_token'])) {
            $get_state = $this->prg_load_state(sanitize_text_field($_GET['lp_token']));
            if (is_array($get_state)) $state = array_merge($state, $get_state);
        }
        if (!empty($_POST['lp_state'])) {
            $decoded = json_decode(stripslashes($_POST['lp_state']), true);
            if (is_array($decoded)) $state = array_merge($state, $decoded);
        }

        // Done-page
        if ($step === 999 && !empty($state['_done'])) {
            if ($state['parcel_size']==='oversize') return $this->view_oversize_success($state['email']);
            $order = !empty($state['order_id']) ? wc_get_order($state['order_id']) : null;
            return $this->view_success($state['email'], ($state['_success_label']??''), (int)($state['_valid_days']??$label_valid_days), $order, $state['lines']);
        }

        // POST handling
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (empty($_POST['lp_cargo_return_nonce']) || !wp_verify_nonce($_POST['lp_cargo_return_nonce'], 'lp_cargo_return_flow')) {
                $errors[] = 'Ugyldig forespørsel.';
            }
            if (!empty($_POST['company_website'] ?? '')) { $errors[] = 'Ugyldig forespørsel.'; }

            if ($step === 1) {
                // Rate-limit på IP
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
                $rk = 'lp_cargo_rate_'.md5($ip);
                $hit = (int) get_transient($rk);
                $limit = (int) apply_filters('lp_cargo_rate_limit_attempts', 20);
                $ttl   = (int) apply_filters('lp_cargo_rate_limit_ttl', 15*MINUTE_IN_SECONDS);
                if ($hit >= $limit) { $errors[] = 'For mange forsøk, prøv igjen om litt.'; }
                else { set_transient($rk, $hit+1, $ttl); }

                $order_number = ltrim(sanitize_text_field($_POST['order_number'] ?? ''), '#');
                $email        = sanitize_email($_POST['email'] ?? '');
                $order_id     = absint($order_number);
                if (!$order_id) {
                    $maybe_key = preg_replace('/^order_/','',$order_number);
                    $by_key = wc_get_order_id_by_order_key($maybe_key);
                    if ($by_key) $order_id = (int)$by_key;
                }
                $order = $order_id ? wc_get_order($order_id) : null;
                if (!$order) $errors[] = 'Kombinasjonen av ordrenummer og e-post er ikke gyldig for retur.';
                elseif (strtolower($order->get_billing_email()) !== strtolower($email)) $errors[] = 'Kombinasjonen av ordrenummer og e-post er ikke gyldig for retur.';
                else {
                    $allowed_statuses = apply_filters('lp_cargo_allowed_statuses_for_return', ['processing','completed']);
                    if ( !$order->is_paid() || !in_array($order->get_status(), $allowed_statuses, true) ) {
                        $errors[] = 'Kombinasjonen av ordrenummer og e-post er ikke gyldig for retur.';
                    } else {
                        $country = $order->get_billing_country() ?: $order->get_shipping_country();
                        if (strtoupper((string)$country) !== 'NO') {
                            $errors[] = 'Vi støtter for øyeblikket kun returer fra Norge.';
                        } else {
                            if ($this->order_locked_for_returns($order)) {
                                $errors[] = 'Det er allerede opprettet en retur for denne ordren.';
                            } else {
                                $err = $this->check_return_window($order);
                                if (is_wp_error($err)) { $errors[] = $err->get_error_message(); }
                                else {
                                    $state['order_id'] = $order_id; $state['email'] = $email;
                                    if (!$errors){
                                        $token = $this->prg_save_state($state);
                                        wp_safe_redirect(add_query_arg(['lp_step'=>2,'lp_token'=>$token], get_permalink()));
                                        exit;
                                    }
                                }
                            }
                        }
                    }
                }

            } elseif ($step === 2) {
                $order = wc_get_order($state['order_id']);
                if (!$order) { $errors[] = 'Ordre mangler.'; }
                $lines = [];
                foreach ((array)($_POST['qty'] ?? []) as $item_id => $qty) {
                    $item = $order ? $order->get_item($item_id) : null;
                    if (!$item) continue;
                    $prev = (array) ($order ? $order->get_meta(self::META_RETURNED_QTY) : []);
                    $already = (int)($prev[$item_id] ?? 0);
                    $max = max(0, (int)$item->get_quantity() - $already);
                    $q = min(max(0, (int)$qty), $max);
                    if ($q > 0) $lines[$item_id] = $q;
                }
                if (!$lines) $errors[] = 'Velg minst ett produkt å returnere.';
                $state['lines'] = $lines;

                if (isset($_POST['lp_back'])) {
                    $token = $this->prg_save_state($state);
                    wp_safe_redirect(add_query_arg(['lp_step'=>1,'lp_token'=>$token], get_permalink()));
                    exit;
                }

                if (!$errors){
                    $token = $this->prg_save_state($state);
                    wp_safe_redirect(add_query_arg(['lp_step'=>3,'lp_token'=>$token], get_permalink()));
                    exit;
                }

            } elseif ($step === 3) {
                $state['parcel_size']  = in_array($_POST['parcel_size'] ?? 'small',['small','large','oversize'],true) ? $_POST['parcel_size'] : 'small';
                $state['carrier_group']= in_array($_POST['carrier_group'] ?? 'postnord',['posten','postnord','oversize'],true) ? $_POST['carrier_group'] : 'postnord';
                $state['service']      = sanitize_text_field($_POST['service'] ?? '');
                $state['agreement']    = sanitize_text_field($_POST['agreement'] ?? '');
                $state['return_note']  = sanitize_textarea_field($_POST['return_note'] ?? '');

                if (isset($_POST['lp_back'])) {
                    $token = $this->prg_save_state($state);
                    wp_safe_redirect(add_query_arg(['lp_step'=>2,'lp_token'=>$token], get_permalink()));
                    exit;
                }

                // Server-side fallback for 1 tjeneste
                if ($state['parcel_size'] !== 'oversize' && (empty($state['service']) || empty($state['agreement']))) {
                    $allowed = $this->get_transport_agreements(true);
                    $groups = ['postnord'=>[],'posten'=>[]];
                    if (!is_wp_error($allowed)) {
                        foreach ($allowed as $ag) {
                            $carrier = strtolower($ag['carrier_name']);
                            $g = (strpos($carrier,'postnord')!==false) ? 'postnord' : 'posten';
                            foreach ($ag['products'] as $p) {
                                $groups[$g][] = ['a'=>$ag['id'], 'p'=>$p['id']];
                            }
                        }
                    }
                    $gsel = $state['carrier_group'];
                    if (!empty($groups[$gsel]) && count($groups[$gsel])===1) {
                        $state['agreement'] = (string)$groups[$gsel][0]['a'];
                        $state['service']   = (string)$groups[$gsel][0]['p'];
                    }
                }

                if ($state['parcel_size'] !== 'oversize') {
                    if (empty($state['service']) || empty($state['agreement'])) $errors[] = 'Velg frakttjeneste.';
                }

                if (!$errors){
                    $token = $this->prg_save_state($state);
                    wp_safe_redirect(add_query_arg(['lp_step'=>4,'lp_token'=>$token], get_permalink()));
                    exit;
                }

            } elseif ($step === 4) {
                $state['refund_method'] = in_array($_POST['refund_method'] ?? 'giftcard',['giftcard','original'],true) ? $_POST['refund_method'] : 'giftcard';
                $state['accept_terms'] = ($_POST['accept_terms'] ?? '') === '1' ? '1' : '0';
                $state['return_reason'] = sanitize_text_field($_POST['return_reason'] ?? '');
                $state['return_reason_other'] = sanitize_text_field($_POST['return_reason_other'] ?? '');

                if ($state['accept_terms'] !== '1') $errors[] = 'Du må godta returvilkårene for å fortsette.';

                if (isset($_POST['lp_back'])) {
                    $token = $this->prg_save_state($state);
                    wp_safe_redirect(add_query_arg(['lp_step'=>3,'lp_token'=>$token], get_permalink()));
                    exit;
                }

                $order = wc_get_order($state['order_id']);
                if ($order && $this->order_locked_for_returns($order)) $errors[] = 'Det er allerede opprettet en retur for denne ordren.';

                // Fresh duplicate check
                if (!$errors && $order) {
                  $just = (int)$order->get_meta(self::META_CREATED_TS);
                  $valid = (int)$order->get_meta(self::META_LABEL_VALID_TS);
                  if ($just && (time() - $just) < 120 && $valid && time() < $valid) {
                    $label_url = $order->get_meta(self::META_LABEL_PUBLIC) ?: $order->get_meta(self::META_LABEL_PRIVATE);
                    if ($label_url) {
                      $state['_done']=1; $state['_success_label']=$label_url; $state['_valid_days']=$label_valid_days;
                      $token = $this->prg_save_state($state);
                      wp_safe_redirect(add_query_arg(['lp_step'=>999,'lp_token'=>$token], get_permalink()));
                      exit;
                    }
                  }
                }

                // Normaliser årsak
                $reason = $state['return_reason'];
                if (strtolower($reason)==='annet' && !empty($state['return_reason_other'])) {
                    $reason .= ': '.$state['return_reason_other'];
                }

                if (!$errors && $state['parcel_size']==='oversize') {
                    if ($order) {
                        $first = $order->get_billing_first_name();
                        $refundLabel = $this->refund_label($state['refund_method']);
                        $lines_text = $this->format_return_lines($order, $state['lines']);
                        $estimated = $this->estimate_refund_total($order, $state['lines']);
                        $estimated_text = ($estimated['raw'] > 0) ? "Estimert refusjon: ".$estimated['formatted']."\n" : '';
                        $msg = "Forespørsel om retur (overdimensjonert)\nOrdre: #".$order->get_id()."\nKunde: ".$first.' '.$order->get_billing_last_name()." <".$order->get_billing_email().">\nTelefon: ".$order->get_billing_phone()."\nAdresse: ".$order->get_billing_address_1()." ".$order->get_billing_postcode()." ".$order->get_billing_city()."\nProdukter:\n".$lines_text."\nÅrsak: ".($reason?:'-')."\nMelding: ".($state['return_note']?:'-')."\nBehandling: ".$refundLabel."\n".$estimated_text;
                        $headers = ['Content-Type: text/plain; charset=UTF-8','From: '.get_bloginfo('name').' <kundeservice@'.wp_parse_url(home_url(), PHP_URL_HOST).'>'];
                        $toSupport = get_option(self::OPT_SUPPORT_EMAIL, get_option('admin_email'));
                        wp_mail($toSupport,'Retur – overdimensjonert forespørsel (ordre #'.$order->get_id().')',$msg,$headers);

                        // Meta
                        $note  = "Retur forespurt (overdimensjonert).\n";
                        if ($estimated['raw'] > 0) $note .= "Estimert refusjon (varer): ".$estimated['formatted']."\n";
                        $note .= "Produkter:\n".$lines_text."\n";
                        $note .= "Årsak: ".($reason?:'-')."\n";
                        $note .= "Returmelding: ".$state['return_note']."\n";
                        $note .= "Behandling: ".$refundLabel;
                        $order->add_order_note($note, false, true);
                        $order->update_meta_data(self::META_REFUND_METHOD, $state['refund_method']);
                        $order->update_meta_data(self::META_PARCEL_SIZE, 'oversize');
                        if ($estimated['raw'] > 0) $order->update_meta_data('_lp_estimated_refund', $estimated['raw']);
                        $order->update_meta_data('_lp_carrier_group', $state['carrier_group']); // lagre valgt transportør
                        if (!empty($reason)) $order->update_meta_data(self::META_RETURN_REASON, $reason);
                        $order->update_meta_data(self::META_CREATED_TS, time());

                        // Lock & mark quantities
                        $order->update_meta_data(self::META_LOCKED, '1');
                        $prev = (array) $order->get_meta(self::META_RETURNED_QTY);
                        foreach ($state['lines'] as $item_id => $qty_ret) $prev[$item_id] = max(0, (int)($prev[$item_id] ?? 0) + (int)$qty_ret);
                        $order->update_meta_data(self::META_RETURNED_QTY, $prev);
                        $order->save();

                        // Grant fraktfri (cookie/IP)
                        $this->grant_freeship_bonus('', 0);

                        // Logg
                        do_action('lp_cargo_return_created', [
                          'created'=>current_time('mysql'),
                          'order_id'=>$order->get_id(),
                          'email'=>$order->get_billing_email(),
                          'reason'=>$reason,
                          'carrier'=>$state['carrier_group'],
                          'parcel_size'=>$state['parcel_size'],
                          'label_url'=>'',
                          'tracking_url'=>'',
                          'notes' => ($estimated['raw'] > 0) ? ('Estimert refusjon: '.$estimated['formatted']) : '',
                          'fs_nonce'=>$this->last_granted_fs['nonce'] ?? '',
                        ]);
                    }
                    $state['_done']=1; $state['_success_label']=''; $state['_valid_days']=$label_valid_days;
                    $token = $this->prg_save_state($state);
                    wp_safe_redirect(add_query_arg(['lp_step'=>999,'lp_token'=>$token], get_permalink()));
                    exit;

                } elseif (!$errors) {
                    $res = $this->create_consignment($state);
                    if (is_wp_error($res)) {
                        $errors[] = $res->get_error_message();
                    } else {
                        $label_url  = $res['public_label_url'] ?: $res['label_url'];
                        $tracking   = $res['tracking_url'] ?? '';
                        $service_label = $this->get_service_label($state['agreement'], $state['service']);

                        if ($order) {
                            $fee = $state['parcel_size']==='large' ? $fee_large : $fee_small;
                            $lines_text = $this->format_return_lines($order, $state['lines']);
                            $valid_until = (new DateTime('+'.$label_valid_days.' days'))->getTimestamp();
                            // store
                            if (!empty($res['id'])) $order->update_meta_data(self::META_CONSIGNMENT_ID, $res['id']);
                            if (!empty($res['public_label_url'])) $order->update_meta_data(self::META_LABEL_PUBLIC, $res['public_label_url']);
                            if (!empty($res['label_url'])) $order->update_meta_data(self::META_LABEL_PRIVATE, $res['label_url']);
                            $order->update_meta_data(self::META_LABEL_VALID_TS, $valid_until);
                            $order->update_meta_data(self::META_REFUND_METHOD, $state['refund_method']);
                            $order->update_meta_data(self::META_PARCEL_SIZE, $state['parcel_size']);
                            $order->update_meta_data('_lp_carrier_group', $state['carrier_group']);
                            if (!empty($reason)) $order->update_meta_data(self::META_RETURN_REASON, $reason);
                            $order->update_meta_data(self::META_CREATED_TS, time());

                            $estimated = $this->estimate_refund_total($order, $state['lines']);
                            $refundLabel = $this->refund_label($state['refund_method']);
                            $note  = "Retur opprettet.\n";
                            if ($tracking) $note .= "Sporing: ".$tracking."\n";
                            if ($label_url) $note .= "Etikett: ".$label_url."\n";
                            $note .= "Tjeneste: ".$service_label."\n";
                            $note .= "Returavgift (anslag): kr ".$fee."\n";
                            if ($estimated['raw'] > 0) $note .= "Estimert refusjon (varer): ".$estimated['formatted']."\n";
                            $note .= "Produkter:\n".$lines_text."\n";
                            $note .= "Årsak: ".($reason?:'-')."\n";
                            if (!empty($state['return_note'])) $note .= "Returmelding: ".$state['return_note']."\n";
                            $note .= "Behandling: ".$refundLabel."\n";
                            $note .= "Etiketten er gyldig i ".$label_valid_days." dager fra i dag.";
                            $order->add_order_note($note, false, true);
                            if ($estimated['raw'] > 0) $order->update_meta_data('_lp_estimated_refund', $estimated['raw']);
                            // E-post
                            $this->send_customer_confirmation($order, $service_label, 'kr '.$fee, $lines_text, $res['label_url'] ?? '', $res['public_label_url'] ?? '', $tracking, $label_valid_days, $refundLabel, $state['lines']);
                            // Lock & qty
                            $order->update_meta_data(self::META_LOCKED,'1');
                            $prev = (array) $order->get_meta(self::META_RETURNED_QTY);
                            foreach ($state['lines'] as $item_id => $qty_ret) $prev[$item_id] = max(0, (int)($prev[$item_id] ?? 0) + (int)$qty_ret);
                            $order->update_meta_data(self::META_RETURNED_QTY, $prev);
                            $order->save();
                            // Bonus (cookie/IP)
                            $this->grant_freeship_bonus('', 0);
                            // Logg
                        do_action('lp_cargo_return_created', [
                          'created'=>current_time('mysql'),
                          'order_id'=>$order->get_id(),
                          'email'=>$order->get_billing_email(),
                          'reason'=>$reason,
                          'carrier'=>$state['carrier_group'],
                          'parcel_size'=>$state['parcel_size'],
                          'label_url'=>$label_url,
                          'tracking_url'=>$tracking,
                          'fs_nonce'=>$this->last_granted_fs['nonce'] ?? '',
                          'notes' => ($estimated['raw'] > 0) ? ('Estimert refusjon: '.$estimated['formatted']) : '',
                        ]);
                        }
                        $state['_done']=1; $state['_success_label']=$label_url; $state['_valid_days']=$label_valid_days;
                        $token = $this->prg_save_state($state);
                        wp_safe_redirect(add_query_arg(['lp_step'=>999,'lp_token'=>$token], get_permalink()));
                        exit;
                    }
                }
            }
        }

        /* ===== UI ===== */
        ob_start(); echo '<div class="lp-wrap">';
        $pct = [1=>25,2=>50,3=>75,4=>100][$step] ?? 25;
        echo '<div class="lp-progress" aria-hidden="true"><span style="width:'.$pct.'%"></span></div>';
        if ($errors) echo '<div class="lp-step lp-alert" aria-live="polite"><p>'.implode('<br/>', array_map('esc_html',$errors)).'</p></div>';

        $nonce = wp_nonce_field('lp_cargo_return_flow','lp_cargo_return_nonce', true, false);
        $state_json = esc_attr(wp_json_encode($state));
        // STEP 1
        if ($step === 1) {
            $exInfo = get_option(self::OPT_EXCHANGE_INFO,'Ønsker du bytte? Vi dekker frakt på ny forsendelse.');
            echo '<div class="lp-step"><h3>Start retur</h3>';
            if ($exInfo) echo '<p class="lp-note">'.wp_kses_post($exInfo).'</p>';
            echo '<form method="post" class="lp-grid lp-two">';
            echo $nonce;
            echo '<input type="hidden" name="lp_step" value="1">';
            echo '<input type="hidden" name="lp_state" value="'.$state_json.'">';
            echo '<label class="lp-label" for="order_number">Ordrenummer</label>';
            echo '<input class="lp-input" id="order_number" name="order_number" placeholder="#1234" required>';
            echo '<label class="lp-label" for="email">E-post</label>';
            echo '<input class="lp-input" id="email" type="email" name="email" placeholder="din@epost.no" required>';
            echo '<input type="text" name="company_website" value="" style="position:absolute;left:-9999px" tabindex="-1" aria-hidden="true">';
            echo '<div class="lp-btn-row lp-span-all"><button class="lp-btn" type="submit">Fortsett</button></div>';
            echo '</form></div>';
        }

        // STEP 2
        if ($step === 2) {
            $order = $state['order_id'] ? wc_get_order($state['order_id']) : null;
            echo '<div class="lp-step"><h3>Velg produkter for retur</h3>';
            if (!$order) {
                echo '<p>Ordre mangler.</p>';
            } else {
                $prev = (array)$order->get_meta(self::META_RETURNED_QTY);
                echo '<form method="post">';
                echo $nonce;
                echo '<input type="hidden" name="lp_step" value="2">';
                echo '<input type="hidden" name="lp_state" value="'.$state_json.'">';
                echo '<div class="lp-table-wrap"><table class="shop_table lp-table-responsive"><thead><tr><th>Produkt</th><th>Kjøpt</th><th>Tilgjengelig</th><th>Retur</th></tr></thead><tbody>';
                foreach ($order->get_items() as $item_id=>$item) {
                    $qty_total = (int)$item->get_quantity();
                    $already = (int)($prev[$item_id] ?? 0);
                    $max = max(0, $qty_total - $already);

                    $thumb = '';
                    if ($prod = $item->get_product()) {
                        $img = $prod->get_image('thumbnail');
                        $thumb = $img ? '<span class="lp-sm-img">'.wp_kses_post($img).'<span>'.$this->h($item->get_name()).'</span></span>' : $this->h($item->get_name());
                    } else {
                        $thumb = $this->h($item->get_name());
                    }

                    echo '<tr>';
                    echo '<td>'.$thumb.'</td>';
                    echo '<td>'.intval($qty_total).'</td>';
                    echo '<td>'.intval($max).'</td>';
                    echo '<td>'.($max>0 ? '<input class="lp-input" type="number" name="qty['.intval($item_id).']" min="0" max="'.intval($max).'" value="0" style="width:100px">' : '<span class="lp-muted">Allerede returnert</span>').'</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
                echo '<div class="lp-btn-row">';
                echo '<button class="lp-btn" type="submit" name="lp_back" value="1">Tilbake</button>';
                echo '<button class="lp-btn" type="submit">Fortsett</button>';
                echo '</div></form>';
            }
            echo '</div>';
        }

        // STEP 3
        if ($step === 3) {
            $allowed = $this->get_transport_agreements(true);
            $groups = ['postnord'=>[],'posten'=>[]];
            if (!is_wp_error($allowed)) {
                foreach ($allowed as $ag) {
                    $carrier = strtolower($ag['carrier_name']);
                    $g = (strpos($carrier,'postnord')!==false) ? 'postnord' : 'posten';
                    foreach ($ag['products'] as $p) {
                        $groups[$g][] = [
                            'a'=>$ag['id'],
                            'p'=>$p['id'],
                            'label'=>$this->pretty_service_label($ag['carrier_name'],$p['name'])
                        ];
                    }
                }
            }
            $sel_group = $state['carrier_group'];
            if ($sel_group==='postnord' && empty($groups['postnord']) && !empty($groups['posten'])) $sel_group='posten';
            if ($sel_group==='posten' && empty($groups['posten']) && !empty($groups['postnord'])) $sel_group='postnord';

            $svc_json = wp_json_encode($groups, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);

            echo '<div class="lp-step"><h3>Frakt & pakke</h3>';
            echo '<form method="post" class="lp-grid lp-two">';
            echo $nonce;
            echo '<input type="hidden" name="lp_step" value="3">';
            echo '<input type="hidden" name="lp_state" value="'.$state_json.'">';

            // Parcel size
            echo '<div><span class="lp-label">Pakkestørrelse</span>';
            $sizes = [
                'small'=>'Liten (≤2 kg) Passer i typisk postkasse',
                'large'=>'Stor (2–10 kg)',
                'oversize'=>'Overdimensjonert (>10 kg)'
            ];
            foreach ($sizes as $val=>$label) {
                $checked = ($state['parcel_size']===$val)?' checked':'';
                echo '<label class="lp-radio-row"><input type="radio" name="parcel_size" value="'.esc_attr($val).'"'.$checked.'> '.$this->h($label).'</label>';
            }
            echo '<p class="lp-help">Pris/valg baseres på <strong>høyeste av faktisk vekt og volumvekt.</strong> <em>Faktisk fraktpris kalkuleres av transportør etter innlevering.</em> </p>';
            echo '<button type="button" class="lp-btn" id="lp-calc-toggle" style="padding:8px 12px;margin-top:6px">Usikker på størrelse? Vis kalkulator</button>';
            echo '<div id="lp-calc" class="lp-calc" style="display:none">
                    <div class="lp-grid lp-two">
                        <div><label class="lp-label" for="lp-calc-l">Lengde (cm)</label><input id="lp-calc-l" class="lp-input" type="number" min="0" step="1" placeholder="f.eks. 30"></div>
                        <div><label class="lp-label" for="lp-calc-w">Bredde (cm)</label><input id="lp-calc-w" class="lp-input" type="number" min="0" step="1" placeholder="f.eks. 20"></div>
                        <div><label class="lp-label" for="lp-calc-h">Høyde (cm)</label><input id="lp-calc-h" class="lp-input" type="number" min="0" step="1" placeholder="f.eks. 10"></div>
                        <div style="align-self:end"><button type="button" class="lp-btn" id="lp-calc-btn">Beregn volumvekt</button></div>
                    </div>
                    <p class="lp-note" id="lp-calc-out">Volumvekt = (L×B×H) ÷ 5000. Verdien vises her.</p>
                  </div>';
            echo '</div>';

            // Carrier group
            echo '<div><span class="lp-label">Transportør</span>';
            foreach (['postnord'=>'PostNord','posten'=>'Posten/Bring'] as $val=>$label) {
                if (empty($groups[$val])) continue;
                $checked = ($sel_group===$val)?' checked':'';
                $extra = ($val==='postnord') ? ' <span class="lp-badge">QR‑kode</span>' : '';
                echo '<label class="lp-radio-row"><input type="radio" name="carrier_group" value="'.esc_attr($val).'"'.$checked.'> '.$this->h($label).$extra.'</label>';
            }
            echo '<div id="lp-qr-hint" class="lp-note" style="display:none">📱 Med PostNord får du en <strong>QR-kode</strong> på e-post/SMS. Vis koden hos utleveringsstedet – de skriver ut etiketten for deg.</div>';
            echo '</div>';

            // Service select + hidden agreement
            echo '<div style="grid-column:1/-1">
                    <div id="lp-service-wrap">
                        <span class="lp-label">Frakttjeneste</span>
                        <select class="lp-select" id="lp-service" name="service"></select>
                    </div>
                    <div id="lp-service-static" class="lp-note" style="display:none"></div>
                    <input type="hidden" id="lp-agreement" name="agreement" value="'.esc_attr($state['agreement']).'">
                  </div>';

            // Note
            echo '<div class="lp-span-all"><span class="lp-label">Melding (valgfritt)</span><textarea class="lp-input" name="return_note" rows="3">'.esc_textarea($state['return_note']).'</textarea></div>';

            // Buttons
            echo '<div class="lp-btn-row lp-span-all">';
            echo '<button class="lp-btn" type="submit" name="lp_back" value="1">Tilbake</button>';
            echo '<button class="lp-btn" type="submit">Fortsett</button>';
            echo '</div>';

            // Inline JS (inkl. QR-hint)
            $sel_service = esc_js($state['service']);
            $sel_group_js = esc_js($sel_group);
            echo '<script>(function(){
              var services='.$svc_json.';
              var selEl=document.getElementById("lp-service");
              var agr=document.getElementById("lp-agreement");
              var wrap=document.getElementById("lp-service-wrap");
              var staticLbl=document.getElementById("lp-service-static");
              var qrHint=document.getElementById("lp-qr-hint");

              function updateQrHint(){
                var g=document.querySelector(\'input[name="carrier_group"]:checked\');
                var isPN = g && g.value==="postnord";
                if(qrHint){ qrHint.style.display = isPN ? "block" : "none"; }
              }

              function rebuild(){
                var g=document.querySelector(\'input[name="carrier_group"]:checked\'); g=g?g.value:"'.$sel_group_js.'";
                var list=services[g]||[];
                selEl.innerHTML="";
                list.forEach(function(it){
                  var opt=document.createElement("option");
                  opt.value=it.p; opt.textContent=it.label; opt.setAttribute("data-agreement", it.a);
                  selEl.appendChild(opt);
                });

                if(list.length===1){
                  selEl.selectedIndex=0;
                  agr.value=selEl.options[0].getAttribute("data-agreement");
                  wrap.style.display="none";
                  staticLbl.style.display="none";
                } else if(list.length===0){
                  wrap.style.display="none";
                  staticLbl.textContent="Ingen frakttjenester tilgjengelig";
                  staticLbl.style.display="block";
                  agr.value="";
                } else {
                  wrap.style.display="block";
                  staticLbl.style.display="none";
                  var want="'.$sel_service.'", found=false;
                  for(var i=0;i<selEl.options.length;i++){
                    if(selEl.options[i].value===want){ selEl.selectedIndex=i; agr.value=selEl.options[i].getAttribute("data-agreement"); found=true; break; }
                  }
                  if(!found && selEl.options.length){ selEl.selectedIndex=0; agr.value=selEl.options[0].getAttribute("data-agreement"); }
                }

                updateQrHint();
              }

              document.querySelectorAll(\'input[name="carrier_group"]\').forEach(function(r){ r.addEventListener("change", rebuild); });
              selEl.addEventListener("change", function(){ var o=selEl.options[selEl.selectedIndex]; agr.value=o?o.getAttribute("data-agreement"):""; });

              rebuild();

              var calcBtn=document.getElementById("lp-calc-toggle");
              var calc=document.getElementById("lp-calc");
              var out=document.getElementById("lp-calc-out");
              var bBtn=document.getElementById("lp-calc-btn");
              function compute(){
                var L=parseFloat(document.getElementById("lp-calc-l").value)||0;
                var W=parseFloat(document.getElementById("lp-calc-w").value)||0;
                var H=parseFloat(document.getElementById("lp-calc-h").value)||0;
                if(L<=0||W<=0||H<=0){ out.textContent="Volumvekt = (L×B×H) ÷ 5000. Fyll inn alle mål."; return; }
                var vol=((L*W*H)/5000);
                out.textContent="Volumvekt: "+vol.toFixed(2)+" kg (størrelsesvalg bør baseres på høyeste av faktisk vekt og volumvekt).";
              }
              if(calcBtn){ calcBtn.addEventListener("click", function(){ calc.style.display = (calc.style.display==="none"||!calc.style.display) ? "block":"none"; }); }
              if(bBtn){ bBtn.addEventListener("click", compute); }
            })();</script>';

            echo '</form></div>';
        }

        // STEP 4
        if ($step === 4) {
            $reasons = (array)get_option(self::OPT_RETURN_REASONS, []);
            $hasAnnet = false;
            foreach($reasons as $r){ if (mb_strtolower(trim($r))==='annet') { $hasAnnet=true; break; } }
            if (!$hasAnnet) $reasons[]='Annet';
            echo '<div class="lp-step"><h3>Bekreft retur</h3>';
            echo '<form method="post" class="lp-grid lp-two">';
            echo $nonce;
            echo '<input type="hidden" name="lp_step" value="4">';
            echo '<input type="hidden" name="lp_state" value="'.$state_json.'">';

            // Refund method
            echo '<div><span class="lp-label">Refusjonsmetode</span>';
            $methods = ['giftcard'=>'Gavekort / butikkreditt','original'=>'Opprinnelig betalingsmåte'];
            foreach ($methods as $val=>$label) {
                $checked = ($state['refund_method']===$val)?' checked':'';
                echo '<label class="lp-radio-row"><input type="radio" name="refund_method" value="'.esc_attr($val).'"'.$checked.'> '.$this->h($label).'</label>';
            }
            echo '</div>';

            // Reason
            echo '<div><label class="lp-label" for="return_reason">Årsak</label><select class="lp-select" name="return_reason" id="return_reason">';
            foreach ($reasons as $r) {
                $sel = (mb_strtolower($state['return_reason'])===mb_strtolower($r)) ? ' selected':'';
                echo '<option value="'.esc_attr($r).'"'.$sel.'>'.$this->h($r).'</option>';
            }
            echo '</select>';
            echo '<input class="lp-input" id="return_reason_other" name="return_reason_other" placeholder="Skriv årsak..." style="margin-top:6px;display:none;max-width:100%;">';
            echo '<script>(function(){var sel=document.getElementById("return_reason");var other=document.getElementById("return_reason_other");function t(){ other.style.display=(sel.value.toLowerCase()==="annet")?"block":"none"; } sel.addEventListener("change",t); t();})();</script>';
            echo '</div>';

            // Terms
            echo '<div style="grid-column:1/-1;margin-top:6px"><label class="lp-radio-row"><input type="checkbox" name="accept_terms" value="1"> Jeg bekrefter at varene er ubrukte/ubrukt i henhold til returvilkårene, og at etiketten brukes innen '.$this->h($label_valid_days).' dager.</label></div>';

            // Buttons
            echo '<div style="grid-column:1/-1;display:flex;gap:8px;margin-top:6px">';
            echo '<button class="lp-btn" type="submit" name="lp_back" value="1">Tilbake</button>';
            echo '<button class="lp-btn" type="submit">Fullfør retur</button>';
            echo '</div>';

            echo '</form></div>';
        }

        echo '</div>'; // wrap
        return ob_get_clean();
    }

    private function pretty_service_label($carrier, $name){
        $label = trim($name);
        $label = preg_replace('/[\[\(].*?[\]\)]$/','',$label);
        $label = str_replace(['_','-'],' ', $label);
        $label = preg_replace('/\s+return\s*/i',' Return ', $label);
        $label = ucwords(trim(preg_replace('/\s{2,}/',' ', $label)));
        $isPostNord = stripos($carrier,'postnord') !== false;
        return ($isPostNord ? 'PostNord – ' : 'Posten/Bring – ') . $label;
    }

    private function get_service_label($agreementId, $productId){
        $agreements = $this->get_transport_agreements(); // alle
        foreach ($agreements as $ag) {
            if ((string)$ag['id'] !== (string)$agreementId) continue;
            foreach ($ag['products'] as $p) {
                if ((string)$p['id'] === (string)$productId) {
                    return $this->pretty_service_label($ag['carrier_name'], $p['name']);
                }
            }
        }
        return $productId;
    }

    private function refund_label($method){
        return ($method === 'original') ? 'Tilbakebetaling til opprinnelig betalingsmåte' : 'Gavekort / butikkreditt';
    }

    private function estimate_refund_total($order, array $lines){
        $total = 0.0;
        if (!$order || !method_exists($order, 'get_items')) return ['raw'=>0.0,'formatted'=>''];
        foreach ($order->get_items() as $item_id => $item) {
            if (empty($lines[$item_id])) continue;
            $qty_total = (int) $item->get_quantity();
            if ($qty_total <= 0) continue;
            $per_unit = (((float)$item->get_total()) + ((float)$item->get_total_tax())) / $qty_total;
            $qty_ret = max(0, min($qty_total, (int)$lines[$item_id]));
            $total  += $per_unit * $qty_ret;
        }

        $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;
        $raw = (float) wc_format_decimal($total, $decimals);
        $formatted = function_exists('wc_price') ? wc_price($raw, ['currency'=>$order->get_currency()]) : number_format($raw, $decimals);
        return ['raw'=>$raw,'formatted'=>$formatted];
    }

    private function view_success($email, $label_url, $valid_days, $order, array $lines = []){
        $btn = $label_url ? '<p><a target="_blank" rel="noopener" class="lp-btn" href="'.esc_url($label_url).'">Last ned etikett (PDF)</a></p>' : '';
        $order_id = $order ? $order->get_id() : 0;
        $token = $order ? $this->issue_signed_token($order_id, $order->get_billing_email(), max(HOUR_IN_SECONDS, (int)get_option(self::OPT_LABEL_VALID_DAYS,14)*DAY_IN_SECONDS)) : '';
        $regen_btn = $order_id ? '<p><button class="lp-btn" id="lp-regen">Forny etikettlenke</button><input type="hidden" id="lp_regen_token" value="'.esc_attr($token).'"></p>' : '';
        $valid_note = '<p class="lp-help">Etiketten er gyldig i '.$valid_days.' dager. Trenger du ny lenke, klikk «Forny etikettlenke».</p>';

        $estimated_html = '';
        if ($order) {
            $estimated = $this->estimate_refund_total($order, $lines);
            if ($estimated['raw'] > 0) {
                $estimated_html = '<p><strong>Estimert refusjon (varer):</strong> '.$this->h($estimated['formatted']).'. Endelig beløp beregnes etter mottak og kontroll.</p>';
            }
        }

        $qr_success = '';
        if ($order && $order->get_meta('_lp_carrier_group') === 'postnord') {
            $qr_success = '<p class="lp-note">📱 PostNord sender deg en <strong>QR-kode</strong>. Vis koden hos utleveringsstedet – de skriver ut etiketten for deg.</p>';
        }

        // 🎁 Fraktfri aktiveringslenke for andre enheter (cookie/IP)
        $bonus_hours = (int)get_option(self::OPT_FS_BONUS_HOURS,24);
        $until_ts    = time() + ($bonus_hours*HOUR_IN_SECONDS);
        $activate_url = $this->build_fs_activate_url('', $until_ts, home_url('/checkout/'));
        $fs_html = '<div class="lp-step" style="border-color:#6FBE3A;background:#f2fbf0"><h3>Fraktfri i 24t</h3><p>Bestill innen <strong>'.$this->h($bonus_hours).' timer</strong> for fraktfri neste ordre. <span id="lp-fs-finish-countdown" data-until="'.intval($until_ts).'" style="font-weight:600"></span></p><p style="margin-top:6px"><a class="lp-btn" href="'.esc_url($activate_url).'">Aktiver fraktfri på denne enheten</a></p></div><script>(function(){function c(){var el=document.getElementById("lp-fs-finish-countdown");if(!el) return;var t=parseInt(el.getAttribute("data-until"),10)*1000;var d=t-Date.now();if(d<=0){el.textContent="• utløpt";return;}var s=Math.floor(d/1000),h=Math.floor(s/3600),m=Math.floor((s%3600)/60),ss=s%60;el.textContent="• gjenstår "+h+"t "+m+"m "+ss+"s";}setInterval(c,1000);c();})();</script>';

        $js = '';
        if ($order_id) {
            $js = '<script>(function(){const btn=document.getElementById("lp-regen"); if(!btn) return; btn.addEventListener("click", async (e)=>{e.preventDefault(); btn.disabled=true; btn.textContent="Fornyer..."; try{const fd=new FormData(); fd.append("action","lp_cargo_regen_label"); fd.append("order_id","'.intval($order_id).'"); fd.append("token",document.getElementById("lp_regen_token").value); const r=await fetch("'.esc_url(admin_url('admin-ajax.php')).'", {method:"POST", body:fd}); const j=await r.json(); if(j&&j.success&&j.data&&j.data.url){ window.location.href = j.data.url; } else { alert("Kunne ikke fornye etikett."); btn.disabled=false; btn.textContent="Forny etikettlenke"; } }catch(err){ alert("Noe gikk galt."); btn.disabled=false; btn.textContent="Forny etikettlenke"; } });})();</script>';
        }

        return '<div class="lp-wrap"><div class="lp-progress"><span style="width:100%"></span></div><div class="lp-step"><h3>Returetikett er opprettet</h3><p>Vi har sendt etiketten til <strong>'.esc_html($email).'</strong>.</p>'.$btn.$valid_note.$estimated_html.$qr_success.$regen_btn.'</div>'.$fs_html.'</div>'.$js;
    }

    private function view_oversize_success($email){
        $bonus_hours = (int)get_option(self::OPT_FS_BONUS_HOURS,24);
        $until_ts    = time() + ($bonus_hours*HOUR_IN_SECONDS);
        $activate_url = $this->build_fs_activate_url('', $until_ts, home_url('/checkout/'));
        $fs_html = '<div class="lp-step" style="border-color:#6FBE3A;background:#f2fbf0"><h3>Fraktfri i 24t</h3><p>Bestill innen <strong>'.$this->h($bonus_hours).' timer</strong> for fraktfri neste ordre. <span id="lp-fs-finish-countdown" data-until="'.intval($until_ts).'" style="font-weight:600"></span></p><p style="margin-top:6px"><a class="lp-btn" href="'.esc_url($activate_url).'">Aktiver fraktfri på denne enheten</a></p></div><script>(function(){function c(){var el=document.getElementById("lp-fs-finish-countdown");if(!el) return;var t=parseInt(el.getAttribute("data-until"),10)*1000;var d=t-Date.now();if(d<=0){el.textContent="• utløpt";return;}var s=Math.floor(d/1000),h=Math.floor(s/3600),m=Math.floor((s%3600)/60),ss=s%60;el.textContent="• gjenstår "+h+"t "+m+"m "+ss+"s";}setInterval(c,1000);c();})();</script>';
        return '<div class="lp-wrap"><div class="lp-progress"><span style="width:100%"></span></div><div class="lp-step"><h3>Forespørsel sendt</h3><p>Vi har registrert returen din. Du vil få svar på e-post (<strong>'.esc_html($email).'</strong>).</p></div>'.$fs_html.'</div>';
    }

    /* ===================== Email ===================== */

    public function send_label_email($order_id, $to, $label_url, $api_response){
        $subject = sprintf(__('Returetikett for ordre #%d','lp-cargo'), $order_id);
        $message = "Hei!\n\nReturetiketten din er klar.\n";
        $message .= "Etiketten er gyldig i ".(int)get_option(self::OPT_LABEL_VALID_DAYS,14)." dager fra i dag.\n";

        $order = wc_get_order($order_id);
        $refund_line = $order ? "Viktig: Refusjon: ".$this->refund_label($order->get_meta(self::META_REFUND_METHOD) ?: 'giftcard')." etter mottak og kontroll.\n" : "Viktig: Refusjon utstedes etter mottak og kontroll.\n";
        $message .= "\n".$refund_line;

        $bonus_hours = (int)get_option(self::OPT_FS_BONUS_HOURS,24);
        $until_ts    = time() + ($bonus_hours*HOUR_IN_SECONDS);
        $activate_url = $this->build_fs_activate_url('', $until_ts, home_url('/checkout/'));
        $message .= "Fraktfri i ".$bonus_hours."t fra nå (gjelder neste ordre).\n";
        $message .= "Aktiver fraktfri i denne nettleseren: ".$activate_url."\n";

        // Attach PDF kun fra whitelist host
        $attachments = [];
        $host = $label_url ? parse_url($label_url, PHP_URL_HOST) : '';
        $allow_attach = in_array($host, ['api.cargonizer.no','cargonizer.no'], true);
        $headers = ['Content-Type: text/plain; charset=UTF-8','From: '.get_bloginfo('name').' <kundeservice@'.wp_parse_url(home_url(), PHP_URL_HOST).'>'];

        if (get_option(self::OPT_ATTACH_PDF,'0')==='1' && $label_url && $allow_attach) {
            $tmp = $this->safe_download_pdf($label_url);
            if (!is_wp_error($tmp)) $attachments[] = $tmp;
            else $message .= "Last ned etiketten her:\n".$label_url."\n";
        } else {
            if ($label_url) $message .= "Last ned etiketten her:\n".$label_url."\n";
        }
        wp_mail($to, $subject, $message, $headers, $attachments);
        foreach ($attachments as $f) { if (strpos($f, sys_get_temp_dir()) !== false) @unlink($f); }
    }

    private function send_customer_confirmation($order, $service_label, $fee_text, $lines_text, $label_url, $public_label_url, $tracking_url, $valid_days, $refund_label, array $lines = []){
        $to = $order->get_billing_email();
        $subject = sprintf('Retur registrert – ordre #%d', $order->get_id());

        // Prefer our hosted Media URL
        $attach_id = (int) $order->get_meta(self::META_LABEL_ATTACHMENT);
        $media_url = $public_label_url ?: ($label_url ?: '');
        if (!$media_url && $attach_id) $media_url = wp_get_attachment_url($attach_id);

        $bonus_hours = (int)get_option(self::OPT_FS_BONUS_HOURS,24);
        $until_ts    = time() + ($bonus_hours*HOUR_IN_SECONDS);
        $activate_url = $this->build_fs_activate_url('', $until_ts, home_url('/checkout/'));

        $order_lines_html = nl2br(esc_html($lines_text));
        $estimated = $this->estimate_refund_total($order, $lines);
        $estimated_html = $estimated['raw'] > 0 ? '<li><strong>Estimert refusjon (varer):</strong> '.esc_html($estimated['formatted']).'</li>' : '';

        $tracking_html = $tracking_url ? '<li><strong>Sporing:</strong> <a href="'.esc_url($tracking_url).'">'.esc_html($tracking_url).'</a><br><small style="color:#4b5563">Sporingen aktiveres etter at pakken er innlevert.</small></li>' : '';

        $html = '
        <div style="font:14px/1.45 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111">
          <h2 style="margin:0 0 12px">Retur registrert</h2>
          <p>Ordre <strong>#'.esc_html($order->get_id()).'</strong></p>
          <ul>
            <li><strong>Frakttjeneste:</strong> '.esc_html($service_label).'</li>
            <li><strong>Returavgift (anslag):</strong> '.esc_html($fee_text).'</li>'.
            $tracking_html.
            $estimated_html.
            ($media_url ? '<li><strong>Etikett:</strong> <a href="'.esc_url($media_url).'">Last ned PDF</a></li>' : '').
          '</ul>
          <p>Etiketten er gyldig i '.intval($valid_days).' dager.</p>
          <h3 style="margin:18px 0 8px">Slik gjør du</h3>
          <ol style="padding-left:20px;margin:0 0 12px;color:#111">
            <li>Legg ved returskjemaet ditt om du har det tilgjengelig.</li>
            <li>Pakk varen godt og fest etiketten eller vis QR-koden hos utleveringsstedet.</li>
            <li>Lever pakken og ta vare på kvittering/sporingsnummer.</li>
          </ol>
          <h3 style="margin:18px 0 8px">Valgte produkter</h3>
          <pre style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px;white-space:pre-wrap">'.$order_lines_html.'</pre>
          <p><strong>Refusjon:</strong> '.esc_html($refund_label).' etter mottak og kontroll.</p>
          <p style="margin-top:14px;background:#f2fbf0;border:1px solid #d3f1cc;border-radius:8px;padding:10px">
            <strong>Bonus:</strong> Fraktfri i '.intval($bonus_hours).'t fra nå på din neste ordre.<br/>
            <a href="'.esc_url($activate_url).'" style="display:inline-block;margin-top:8px;background:#6FBE3A;color:#fff;text-decoration:none;padding:10px 16px;border-radius:8px;font-weight:700">
               Aktiver fraktfri i denne nettleseren
            </a>
          </p>'.
          ($media_url ? '<p style="margin-top:16px"><a href="'.esc_url($media_url).'" style="display:inline-block;background:#6FBE3A;color:#fff;text-decoration:none;padding:10px 16px;border-radius:8px;font-weight:700">Last ned returetikett (PDF)</a></p>' : '').
                '</div>';

        $headers = [
          'Content-Type: text/html; charset=UTF-8',
          'From: '.get_bloginfo('name').' <kundeservice@'.wp_parse_url(home_url(), PHP_URL_HOST).'>'
        ];

        $attachments = [];
        if ($attach_id) {
            $path = get_attached_file($attach_id);
            if (is_readable($path)) $attachments[] = $path;
        } elseif ($media_url) {
            $host = parse_url($media_url, PHP_URL_HOST) ?: '';
            $allow_attach = in_array($host, ['api.cargonizer.no','cargonizer.no'], true);
            if ($allow_attach) {
                $tmp = $this->safe_download_pdf($media_url);
                if (!is_wp_error($tmp)) $attachments[] = $tmp;
            }
        }

        wp_mail($to, $subject, $html, $headers, $attachments);
        foreach ($attachments as $f) { if (strpos($f, sys_get_temp_dir()) !== false) @unlink($f); }
    }

    private function format_return_lines($order, array $lines){
        $out = [];
        if (!$order) return "–";
        foreach ($order->get_items() as $item_id => $item) {
            if (!isset($lines[$item_id])) continue;
            $out[] = sprintf("• %s × %d", $item->get_name(), (int)$lines[$item_id]);
        }
        return $out ? implode("\n", $out) : "–";
    }

    private function parse_agreements_response($xml){
        $doc = $this->api_client->load_xml($xml);
        if (is_wp_error($doc)) return $doc;

        $out=[];
        foreach ($doc->{'transport-agreement'} as $ag) {
            $ag_id = (string)$ag->id; $carrier_name=(string)$ag->carrier->name; $prods=[];
            if (isset($ag->products->product)) {
                foreach ($ag->products->product as $p) {
                    $services=[];
                    if (isset($p->services->service)) foreach ($p->services->service as $svc) {
                        $sid = (string)$svc->identifier ?: (string)$svc->id;
                        $sname = (string)$svc->name ?: (string)$svc;
                        if ($sid) $services[] = ['id'=>$sid,'name'=>$sname?:$sid];
                    }
                    $pid=(string)$p->identifier; $pname=(string)$p->name;
                    $prods[]=['id'=>$pid,'name'=>$pname,'services'=>$services];
                }
            }
            $out[]=['id'=>$ag_id,'carrier_name'=>$carrier_name,'products'=>$prods];
        }

        return $out;
    }

    private function agreements_cache_key(){
        return 'lp_cargo_agreements_cache_'.md5((string)get_option(self::OPT_SENDER_ID,'').home_url('/'));
    }

    private function agreements_cache_ttl(){
        $minutes = (int) apply_filters('lp_cargo_agreements_cache_minutes', 30);
        $minutes = max(10, min(60, $minutes));
        return $minutes * MINUTE_IN_SECONDS;
    }

    private function clear_agreements_cache(){
        $this->agreements_cache_runtime = null;
        delete_transient($this->agreements_cache_key());
    }

    private function get_transport_agreements($filter_allowed=false, $force_refresh=false){
        if ($this->agreements_cache_runtime !== null && !$force_refresh) {
            $all = $this->agreements_cache_runtime;
            return $filter_allowed ? $this->filter_allowed_products($all) : $all;
        }

        $cache_key = $this->agreements_cache_key();
        $cached = $force_refresh ? null : get_transient($cache_key);

        if (is_array($cached)) {
            $this->agreements_cache_runtime = $cached;
            return $filter_allowed ? $this->filter_allowed_products($cached) : $cached;
        }

        $xml = $cached;
        if (!$xml) {
            $xml = $this->api_client->http('GET','transport_agreements.xml');
        }

        if (is_wp_error($xml)) return $xml;

        $parsed = $this->parse_agreements_response($xml);
        if (is_wp_error($parsed)) return $parsed;

        set_transient($cache_key, $parsed, $this->agreements_cache_ttl());
        $this->agreements_cache_runtime = $parsed;

        return $filter_allowed ? $this->filter_allowed_products($parsed) : $parsed;
    }

    private function filter_allowed_products(array $all){
        $allowed=(array)get_option(self::OPT_ALLOWED,[]);
        $filtered = [];
        foreach ($all as $ag) {
            $agCopy = $ag;
            $agCopy['products'] = array_values(array_filter($ag['products'], function($p) use ($ag, $allowed){
                return in_array($ag['id'].'|'.$p['id'], $allowed, true);
            }));
            $filtered[] = $agCopy;
        }
        return $filtered;
    }

    private function build_party_block($tag, array $data){
        $fields = [
            'name','company','address1','address2','postcode','city','country','email','mobile'
        ];
        $parts = [];
        foreach ($fields as $field) {
            if (empty($data[$field])) continue;
            $parts[] = sprintf('<%1$s>%2$s</%1$s>', $field, esc_xml((string)$data[$field]));
        }
        return '<'.$tag.'>'.implode('', $parts).'</'.$tag.'>';
    }

    private function build_parts_xml(array $parts){
        $segments = [];
        foreach ($parts as $tag => $data) {
            $segments[] = $this->build_party_block($tag, $data);
        }
        return '<parts>'.implode('', $segments).'</parts>';
    }

    private function create_consignment(array $state){
        $order = wc_get_order($state['order_id']);
        if (!$order) return new WP_Error('no_order','Fant ikke ordren.');

        // Validate allowed agreement|product
        $allowed = $this->get_transport_agreements(true);
        $ok = false;
        foreach ($allowed as $ag) {
            if ((string)$ag['id'] === (string)$state['agreement']) {
                foreach ($ag['products'] as $p) {
                    if ((string)$p['id'] === (string)$state['service']) { $ok = true; break 2; }
                }
            }
        }
        if (!$ok) return new WP_Error('not_allowed','Valgt frakttjeneste er ikke tilgjengelig.');

        $base = wc_get_base_location();
        $store_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $store_addr1= get_option('woocommerce_store_address');
        $store_addr2= get_option('woocommerce_store_address_2');
        $store_city = get_option('woocommerce_store_city');
        $store_post = get_option('woocommerce_store_postcode');
        $store_country = $base['country'];
        if (!$store_addr1 || !$store_city || !$store_post) return new WP_Error('store_addr_missing','Butikkadresse mangler i WooCommerce-innstillinger.');

        $cust = [
            'name'     => $order->get_formatted_billing_full_name(),
            'company'  => $order->get_billing_company(),
            'address1' => $order->get_billing_address_1(),
            'address2' => $order->get_billing_address_2(),
            'postcode' => $order->get_billing_postcode(),
            'city'     => $order->get_billing_city(),
            'country'  => $order->get_billing_country(),
            'phone'    => $order->get_billing_phone(),
            'email'    => $order->get_billing_email(),
        ];

        // Weight
        $weight_total=0.0;
        foreach ($order->get_items() as $item_id=>$item) {
            $qty_ret = isset($state['lines'][$item_id]) ? (int)$state['lines'][$item_id] : 0;
            if ($qty_ret<=0) continue;
            $wkg = (float) ($item->get_meta(self::META_ITEM_WKG) ?: 0);
            if ($wkg <= 0 && ($prod = $item->get_product())) {
                $wkg = (float) wc_get_weight($prod->get_weight() ?: 0, 'kg');
            }
            $weight_total += max(0.0,(float)$wkg)*$qty_ret;
        }
        $weight_total = max(0.1, round($weight_total, 2));

        $agreement = sanitize_text_field($state['agreement']);
        $product   = sanitize_text_field($state['service']);
        $swap      = (get_option(self::OPT_SWAP_PARTIES,'0')==='1');

        $default_services=(array)get_option(self::OPT_DEFAULT_SERV,[]);
        $services_for_product = isset($default_services[$agreement.'|'.$product]) ? (array)$default_services[$agreement.'|'.$product] : [];

        // filter services against availability
        $avail = [];
        foreach ($this->get_transport_agreements(false) as $ag) {
          if ((string)$ag['id'] === (string)$agreement) {
            foreach ($ag['products'] as $p) {
              if ((string)$p['id'] === (string)$product) {
                $avail = array_map(function($s){return (string)$s['id'];}, (array)$p['services']);
                break 2;
              }
            }
          }
        }
        $services_for_product = array_values(array_intersect($services_for_product, $avail));

        $customer_party = [
            'name'     => $cust['name'],
            'company'  => $cust['company'],
            'address1' => $cust['address1'],
            'address2' => $cust['address2'],
            'postcode' => $cust['postcode'],
            'city'     => $cust['city'],
            'country'  => $cust['country'],
            'email'    => $cust['email'],
            'mobile'   => $cust['phone'],
        ];
        $store_party = [
            'name'     => $store_name,
            'address1' => $store_addr1,
            'address2' => $store_addr2,
            'postcode' => $store_post,
            'city'     => $store_city,
            'country'  => $store_country,
        ];

        $partsXml = $this->build_parts_xml([
            'consignor' => $swap ? $store_party : $customer_party,
            'consignee' => $swap ? $customer_party : $store_party,
        ]);

        $itemsXml = sprintf('<items><item type="package" amount="1" weight="%s" /></items>', esc_xml(number_format($weight_total,2,'.','')));

        $servicesXml = '';
        if ($services_for_product) {
            $servicesXml = '<services>'.implode('', array_map(function($sid){ return '<service id="'.esc_xml($sid).'" />'; }, $services_for_product)).'</services>';
        }

        $valuesXml = '<values><value name="provider" value="'.esc_xml(get_bloginfo('name')).'"/><value name="provider-email" value="'.esc_xml(get_option('admin_email')).'"/></values>';
        $referencesXml = sprintf('<references><consignor>%s</consignor><consignee>%s</consignee></references>',
            esc_xml($order->get_order_number()),
            esc_xml('Return for order #'.$order->get_id())
        );

        // Use hyphenated tags (matches examples)
        $email_label_xml = (get_option(self::OPT_EMAIL_VIA_LOG,'1')==='1')
            ? '<email-label-to-consignee>true</email-label-to-consignee>'
            : '<email-label-to-consignee>false</email-label-to-consignee>';

        $tt_email_xml = (get_option(self::OPT_TT_NOTIFY,'0')==='1')
            ? '<email-notification-to-consignee>true</email-notification-to-consignee>'
            : '<email-notification-to-consignee>false</email-notification-to-consignee>';

        // Build XML — transfer attribute kept false; we call transfer endpoint if toggle is ON
        $consignmentsXml = sprintf('<?xml version="1.0" encoding="UTF-8"?><consignments><consignment transport_agreement="%s" print="false" estimate="false" transfer="false">%s%s<product>%s</product>%s%s%s%s</consignment></consignments>',
            esc_xml($agreement), $valuesXml, $email_label_xml,
            esc_xml($product), $tt_email_xml, $partsXml, $itemsXml, $servicesXml.$referencesXml
        );

        $resp = $this->api_client->http('POST','consignments.xml',$consignmentsXml);
        if (is_wp_error($resp)) return $resp;
        $doc = $this->api_client->load_xml($resp);
        if (is_wp_error($doc)) return $doc;

        $consignmentId = '';
        if ($doc->xpath('//consignment/id')) $consignmentId = (string)$doc->xpath('//consignment/id')[0];
        if (!$consignmentId && $doc->xpath('//id')) $consignmentId = (string)$doc->xpath('//id')[0];

        $labelUrl = '';
        if ($doc->xpath('//consignment-pdf')) $labelUrl = (string)$doc->xpath('//consignment-pdf')[0];
        if (!$labelUrl && $doc->xpath('//label')) $labelUrl = (string)$doc->xpath('//label')[0];
        if (!$labelUrl && $doc->xpath('//document[contains(.,".pdf")]')) $labelUrl = (string)$doc->xpath('//document[contains(.,".pdf")]')[0];

        $tracking_url = '';
        if ($doc->xpath('//tracking-link')) $tracking_url = (string)$doc->xpath('//tracking-link')[0];
        elseif ($doc->xpath('//tracking-url')) $tracking_url = (string)$doc->xpath('//tracking-url')[0];

        // Auto transfer if enabled
        if (get_option(self::OPT_AUTO_TRANSFER,'0')==='1' && $consignmentId) {
            $tx = $this->transfer_via_endpoint([$consignmentId]);
            if (is_wp_error($tx)) {
                if ($order && method_exists($order,'add_order_note')) {
                    $order->add_order_note('Cargonizer transfer feilet: '.$tx->get_error_message(), false, true);
                }
            }
        }

        // Prefer our own hosted label: fetch via official label_pdf and store as Media
        $this->last_order_id_for_attachment = $order ? (int)$order->get_id() : 0;
        $publicUrl = '';
        if ($consignmentId) $publicUrl = $this->download_and_host_label($consignmentId, $labelUrl);

        return ['label_url'=>$labelUrl,'public_label_url'=>$publicUrl,'id'=>$consignmentId,'tracking_url'=>$tracking_url];
    }

    // Transfer endpoint: POST /consignments/transfer.xml?consignment_ids[]=X&...
    private function transfer_via_endpoint(array $ids){
        $ids = array_filter(array_map('strval', $ids));
        if (!$ids) return new WP_Error('no_ids','Ingen consignment IDs gitt');
        $qs = '?'.implode('&', array_map(function($id){
            return 'consignment_ids[]='.rawurlencode($id);
        }, $ids));
        $res = $this->api_client->http('POST', 'consignments/transfer.xml'.$qs, null, []);
        if (is_wp_error($res)) return $res;
        return true;
    }

    private function download_and_host_label($consignmentId, $fallbackLabelUrl=''){
        // Official endpoint: /consignments/label_pdf?consignment_ids[]=ID
        $pdf = $this->api_client->http(
            'GET',
            'consignments/label_pdf',
            null,
            ['consignment_ids[]' => (string)$consignmentId],
            'application/pdf'
        );

        // Fallback: provided label url
        if (is_wp_error($pdf) || !$this->api_client->looks_like_pdf($pdf)) {
            $pdf = '';
            if ($fallbackLabelUrl) {
                $p = parse_url($fallbackLabelUrl);
                $h = $p ? ($p['host'] ?? '') : '';
                $path = $p ? ($p['path'] ?? '') : '';
                $q = [];
                if (!empty($p['query'])) parse_str($p['query'], $q);

                if ($this->api_client->is_allowed_host($h) && $path) {
                    $pdf = $this->api_client->http('GET', ltrim($path,'/'), null, $q, 'application/pdf');
                }
            }
        }

        if (is_wp_error($pdf) || !$this->api_client->looks_like_pdf($pdf)) return '';

        // Write to uploads
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']).'cargonizer-labels';
        if (!is_dir($dir)) { 
            wp_mkdir_p($dir); 
            @file_put_contents($dir.'/.htaccess', "Options -Indexes\n"); 
            @file_put_contents($dir.'/index.html', ''); 
        }
        if (!wp_is_writable($dir)) return '';

        $rand = bin2hex(random_bytes(16));
        $file = $dir.'/label-'.$rand.'.pdf';
        if (file_put_contents($file, $pdf) === false) return '';

        $url = trailingslashit($upload['baseurl']).'cargonizer-labels/label-'.$rand.'.pdf';

        // Insert as Media Library attachment and parent to order if known
        $filetype = wp_check_filetype(basename($file), null);
        $attachment = [
            'guid'           => $url,
            'post_mime_type' => $filetype['type'] ?: 'application/pdf',
            'post_title'     => 'Returetikett '.$consignmentId,
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_parent'    => $this->last_order_id_for_attachment > 0 ? $this->last_order_id_for_attachment : 0,
        ];

        $attach_id = wp_insert_attachment($attachment, $file, $this->last_order_id_for_attachment);
        if ($attach_id && !is_wp_error($attach_id)) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $meta = wp_generate_attachment_metadata($attach_id, $file);
            if ($meta) wp_update_attachment_metadata($attach_id, $meta);
            if ($this->last_order_id_for_attachment) {
                $o = wc_get_order($this->last_order_id_for_attachment);
                if ($o) {
                    $o->update_meta_data(self::META_LABEL_ATTACHMENT, $attach_id);
                    $o->update_meta_data(self::META_LABEL_PUBLIC, $url);
                    $o->save();
                }
            }
        }

        return $url;
    }

    /* ===================== Business rules helpers ===================== */

    private function check_return_window($order){
        $days = (int) get_option(self::OPT_RETURN_WINDOW, 30);
        if ($days <= 0) return true;
        if ($order->get_meta(self::META_OVERRIDE) === '1') return true;
        $anchor = $order->get_date_completed() ?: ($order->get_date_paid() ?: $order->get_date_created());
        if (!$anchor) return new WP_Error('no_anchor','Kan ikke verifisere returfrist (mangler dato).');
        $deadline = clone $anchor; $deadline->modify("+{$days} days");
        $now = new DateTime('now', $anchor->getTimezone());
        if ($now > $deadline) {
            $deadline_str = $deadline->date_i18n(get_option('date_format').' H:i');
            return new WP_Error('deadline_passed', 'Returfristen er utløpt. Fristen var: '.$deadline_str.'.');
        }
        return true;
    }
    private function order_locked_for_returns($order){
        if (current_user_can('manage_woocommerce')) return false;
        if ($order->get_meta(self::META_LOCK_OVERRIDE)==='1') return false;
        return ($order->get_meta(self::META_LOCKED) === '1');
    }
    private function is_recently_created($order_id, $email){
        $key = 'lp_cargo_recent_'.$order_id.'_'.md5(strtolower($email));
        if (get_transient($key)) return true;
        set_transient($key, 1, 60);
        return false;
    }
    private function safe_download_pdf($url) {
        $tmp = download_url($url, 30);
        if (is_wp_error($tmp)) return $tmp;
        $h = @fopen($tmp,'rb'); $sig = $h ? fread($h,4) : ''; if ($h) fclose($h);
        if ($sig !== '%PDF') { @unlink($tmp); return new WP_Error('not_pdf','Ikke gyldig PDF'); }
        return $tmp;
    }

    /* ===================== Label regeneration (signed token + rate-limit) ===================== */
    private function issue_signed_token($order_id, $email, $ttl = 86400){
        $exp = time() + max(60, (int)$ttl);
        $payload = $order_id.'|'.strtolower(trim((string)$email)).'|'.$exp;
        $sig = hash_hmac('sha256', $payload, wp_salt('auth'));
        return rtrim(strtr(base64_encode($payload.'|'.$sig), '+/','-_'),'=');
    }
    private function verify_signed_token($token, $order){
        if (!$token || !$order) return false;
        $t = strtr((string)$token, '-_', '+/');
        $pad = strlen($t) % 4; if ($pad) $t .= str_repeat('=', 4 - $pad);
        $raw = base64_decode($t, true);
        if ($raw === false) return false;
        $parts = explode('|', $raw);
        if (count($parts) !== 4) return false;
        list($oid, $email, $exp, $sig) = $parts;
        if ((int)$oid !== (int)$order->get_id()) return false;
        if (time() > (int)$exp) return false;
        $payload = $oid.'|'.strtolower(trim((string)$email)).'|'.$exp;
        $calc    = hash_hmac('sha256', $payload, wp_salt('auth'));
        if (!hash_equals($calc, $sig)) return false;
        return (strtolower(trim((string)$order->get_billing_email())) === strtolower(trim((string)$email)));
    }

    public function ajax_regen_label(){
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if (!$order_id) wp_send_json_error(['msg'=>'order_id mangler']);
        $order = wc_get_order($order_id);
        if (!$order) wp_send_json_error(['msg'=>'Ordre ikke funnet']);

        $token = sanitize_text_field($_POST['token'] ?? '');
        if (!$this->verify_signed_token($token, $order)) wp_send_json_error(['msg'=>'Ugyldig forespørsel']);

        // rate-limit pr IP + ordre
        $rk = 'lp_regen_rate_'.md5(($_SERVER['REMOTE_ADDR'] ?? 'x').'|'.$order_id);
        $hits = (int)get_transient($rk);
        if($hits > 20) wp_send_json_error(['msg'=>'For mange forsøk']);
        set_transient($rk, $hits+1, 10 * MINUTE_IN_SECONDS);

        // blokkér fornyelse etter utløp
        $valid_until = (int)$order->get_meta(self::META_LABEL_VALID_TS);
        if ($valid_until && time() > $valid_until) {
            wp_send_json_error(['msg'=>'Etiketten er utløpt og kan ikke fornyes. Kontakt kundeservice.'], 400);
        }

        $consignment_id = $order->get_meta(self::META_CONSIGNMENT_ID);
        $private = $order->get_meta(self::META_LABEL_PRIVATE);
        $public  = $order->get_meta(self::META_LABEL_PUBLIC);
        $url = '';

        if ($consignment_id) {
            $this->last_order_id_for_attachment = $order_id;
            $new_public = $this->download_and_host_label($consignment_id, $private ?: $public);
            if ($new_public) {
                $order->update_meta_data(self::META_LABEL_PUBLIC, $new_public);
                $order->update_meta_data(self::META_LAST_REGEN, time());
                $order->save();
                $url = $new_public;
            } else {
                $url = $public ?: $private;
            }
        } else {
            $url = $public ?: $private;
        }
        if (!$url) wp_send_json_error(['msg'=>'Ingen etikett tilgjengelig.']);
        wp_send_json_success(['url'=>$url]);
    }

    /* ===================== Free-shipping bonus (Fraktfri i 24t) — Cookie/IP-basert ===================== */

    /** Hasher IP til kort nett-prefiks for mild personvernvennlig matching */
    private function fs_ip_hash($ip){
        $ip = trim((string)$ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $ip);
            $net = $p[0].'.'.$p[1].'.'.$p[2]; // /24
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $p = explode(':', $ip);
            $net = strtolower(implode(':', array_slice($p, 0, 4))); // /64-ish
        } else {
            $net = 'unknown';
        }
        return substr(hash_hmac('sha256', 'lpfs|'.$net, wp_salt('auth')), 0, 16);
    }

    /** Setter fraktfri-cookie med WooCommerce/WordPress-vennlige parametre */
    private function set_fs_cookie($key, $until){
        $expire  = (int)$until;
        $path    = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $secure  = is_ssl();
        $httponly= true;

        // Bruk wc_setcookie i tillegg for best kompatibilitet
        if (function_exists('wc_setcookie')) {
            wc_setcookie(self::COOKIE_FS_KEY, $key, $expire, $secure);
        }

        $opts = [
          'expires'=>$expire,
          'path'=>$path,
          'secure'=>$secure,
          'httponly'=>$httponly,
          'samesite'=>'Lax',
        ];
        if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) {
            $opts['domain'] = COOKIE_DOMAIN;
        }
        @setcookie(self::COOKIE_FS_KEY, $key, $opts);
    }

    /** 🎟️ Utseder enhetstoken (ignorerer e‑post), binder nonce + IP‑hash */
    private function issue_fs_token($ignored_email, $until){
        $exp = (int)$until;
        $nonce = bin2hex(random_bytes(10));
        $iph   = $this->fs_ip_hash($_SERVER['REMOTE_ADDR'] ?? '');
        $payload = $exp.'|'.$nonce.'|'.$iph;
        $sig = hash_hmac('sha256', $payload, wp_salt('auth'));
        return rtrim(strtr(base64_encode($payload.'|'.$sig), '+/','-_'),'=');
    }

    /** Verifiserer enhetstoken */
    private function verify_fs_token($token){
        $t = strtr((string)$token, '-_', '+/');
        $pad = strlen($t) % 4; if ($pad) $t .= str_repeat('=', 4 - $pad);
        $raw = base64_decode($t, true);
        if ($raw === false) return false;
        $parts = explode('|', $raw);
        if (count($parts)!==4) return false;
        list($exp, $nonce, $iph, $sig) = $parts;
        $payload = $exp.'|'.$nonce.'|'.$iph;
        $calc = hash_hmac('sha256', $payload, wp_salt('auth'));
        if (!hash_equals($calc, $sig)) return false;
        if (time() > (int)$exp) return false;
        return [(int)$exp, (string)$nonce, (string)$iph];
    }

    /** Bygger HMAC‑signert aktiveringslenke (cookie/IP) */
    private function build_fs_activate_url($ignored_email, $until, $redirect=''){
        $token = $this->issue_fs_token('', $until);
        $url   = add_query_arg(self::QUERY_FS_PARAM, rawurlencode($token), home_url('/'));
        if ($redirect && $this->is_safe_redirect($redirect)) {
            $url = add_query_arg('redirect', rawurlencode($redirect), $url);
        }
        return $url;
    }

    private function is_safe_redirect($url){
        $host_site = wp_parse_url(home_url(), PHP_URL_HOST);
        $host_tgt  = wp_parse_url($url, PHP_URL_HOST);
        if (!$host_tgt) return true; // relative ok
        return (strcasecmp($host_site, $host_tgt) === 0);
    }

    /** Leser ?lp_fs=TOKEN og setter fraktfri-cookie + IP-fallback */
    public function maybe_accept_fs_token(){
        if (empty($_GET[self::QUERY_FS_PARAM])) return;
        $payload = $this->verify_fs_token(sanitize_text_field($_GET[self::QUERY_FS_PARAM]));
        if (!$payload) return;

        list($until,$nonce,$iph) = $payload;

        // Sett cookie
        $this->set_fs_cookie($_GET[self::QUERY_FS_PARAM], $until);

        // Lagre IP/nonce som fallback og for "brukt"-markering
        set_transient('lpfs_ip_'.$iph,      ['until'=>$until,'nonce'=>$nonce,'used'=>0], max(60, $until - time() + 3600));
        set_transient('lpfs_nonce_'.$nonce, ['until'=>$until,'iph'=>$iph,   'used'=>0], max(60, $until - time() + 3600));

        // Rydd URL og redirect
        $redir = isset($_GET['redirect']) ? esc_url_raw($_GET['redirect']) : home_url('/');
        if (!$this->is_safe_redirect($redir)) $redir = home_url('/');
        wp_safe_redirect(remove_query_arg([self::QUERY_FS_PARAM, 'redirect'], $redir));
        exit;
    }

    /** Status for nåværende besøk: cookie → IP-fallback (ingen e‑post) */
    private function fs_status_for_current_visitor(){
        // Cookie først
        $cookie = $_COOKIE[self::COOKIE_FS_KEY] ?? '';
        if ($cookie) {
            $v = $this->verify_fs_token($cookie);
            if ($v) {
                list($until,$nonce,$iph) = $v;
                if (!get_transient('lpfs_used_'.$nonce)) {
                    return ['active'=>true,'until'=>$until,'key'=>$cookie,'source'=>'cookie','nonce'=>$nonce,'iph'=>$iph];
                }
            }
        }
        // IP fallback
        $iph = $this->fs_ip_hash($_SERVER['REMOTE_ADDR'] ?? '');
        $row = get_transient('lpfs_ip_'.$iph);
        if (is_array($row) && !empty($row['until']) && empty($row['used']) && time() < (int)$row['until']) {
            return ['active'=>true,'until'=>(int)$row['until'],'key'=>null,'source'=>'ip','nonce'=> (string)($row['nonce'] ?? ''),'iph'=>$iph];
        }
        return ['active'=>false,'until'=>0,'key'=>null,'source'=>null];
    }

    /** Tildel fraktfri på *denne enheten* (cookie) + mild IP-fallback */
    private function grant_freeship_bonus($ignored_email, $user_id = 0){
        if (get_option(self::OPT_FS_BONUS_ENABLE,'1')!=='1') return;
        $hours = (int)get_option(self::OPT_FS_BONUS_HOURS,24);
        $until = time() + max(1,$hours)*HOUR_IN_SECONDS;

        // Cookie-token
        $token = $this->issue_fs_token('', $until);
        $this->set_fs_cookie($token, $until);

        // IP fallback + nonce oppslag
        $decoded = $this->verify_fs_token($token);
        $iph = $decoded ? $decoded[2] : $this->fs_ip_hash($_SERVER['REMOTE_ADDR'] ?? '');
        $nonce = $decoded ? $decoded[1] : bin2hex(random_bytes(8));

        set_transient('lpfs_ip_'.$iph,      ['until'=>$until,'nonce'=>$nonce,'used'=>0], max(60, $until - time() + 3600));
        set_transient('lpfs_nonce_'.$nonce, ['until'=>$until,'iph'=>$iph,   'used'=>0], max(60, $until - time() + 3600));

        $this->last_granted_fs = ['nonce'=>$nonce,'until'=>$until];

        $this->stats_log('_lp_fs_granted');
    }

    public function maybe_show_freeship_banner(){
        if (get_option(self::OPT_FS_BONUS_ENABLE,'1')!=='1') return;
        if (function_exists('is_cart') && is_cart()) return;
        if (function_exists('is_checkout') && is_checkout()) return;
        $st = $this->fs_status_for_current_visitor();
        if (!$st['active']) return;
        if (!empty($_COOKIE['lp_fs_dismissed']) && $_COOKIE['lp_fs_dismissed']==='1') return;
        $until = (int)$st['until'];
        echo '<div class="lp-fs-banner" role="status" aria-live="polite">
                <button type="button" class="lp-fs-close" aria-label="Lukk">&times;</button>
                <div class="lp-fs-marquee"><span>🎁 Fraktfri i 24t &nbsp;•&nbsp; Bestill innen '.esc_html(get_option(self::OPT_FS_BONUS_HOURS,24)).' timer &nbsp;•&nbsp;</span></div>
                <span id="lp-fs-countdown" data-until="'.esc_attr($until).'"></span>
              </div>
<script>(function(){
  function fmt(ms){if(ms<=0)return"Utløpt";var s=Math.floor(ms/1000),h=Math.floor(s/3600),m=Math.floor((s%3600)/60),ss=s%60;return h+"t "+m+"m "+ss+"s";}
  function tick(){var el=document.getElementById("lp-fs-countdown");if(!el)return;var until=(parseInt(el.getAttribute("data-until"),10)||0)*1000;el.textContent="Gjenstår: "+fmt(until-Date.now());}
  function closeBanner(){var b=document.querySelector(".lp-fs-banner"); if(b){b.remove();} document.body.classList.remove("lp-body-pad"); try{localStorage.setItem("lp_fs_closed","1");}catch(e){} try{document.cookie="lp_fs_dismissed=1;path=/;max-age="+(365*24*60*60)+";SameSite=Lax"+(location.protocol==="https:"?";Secure":"");}catch(e){}}
  var dismissed=false; try{dismissed=localStorage.getItem("lp_fs_closed")==="1";}catch(e){}
  if(dismissed){ var b=document.querySelector(".lp-fs-banner"); if(b) b.remove(); return; }
  document.body.classList.add("lp-body-pad");
  var c=document.querySelector(".lp-fs-close"); if(c) c.addEventListener("click", closeBanner);
  setInterval(tick,1000); tick();
})();</script>';
    }

    /** ✅ Tving valgt metode til fraktfri når bonus er aktiv (riktig API + automatisk valg) */
    public function maybe_force_free_method($default_method, $available_methods){
        if (get_option(self::OPT_FS_BONUS_ENABLE,'1')!=='1') return $default_method;
        $st = $this->fs_status_for_current_visitor(); if (!$st['active']) return $default_method;

        $chosen = $default_method;
        foreach ((array)$available_methods as $id => $rate) {
            if ($rate instanceof WC_Shipping_Rate) {
                if ($rate->get_method_id()==='lp_fs_free' || (float)$rate->get_cost() <= 0.0) { $chosen = $id; break; }
            }
        }
        return $chosen;
    }

    /** ✅ Gjør rater gratis med WooCommerce‑API (set_cost/set_taxes/set_label) */
    public function apply_return_bonus_free_shipping($rates, $package){
        if (get_option(self::OPT_FS_BONUS_ENABLE,'1')!=='1') return $rates;
        $st = $this->fs_status_for_current_visitor(); if (!$st['active']) return $rates;

        // Default: gjør alle rater gratis. Kan justeres via filter.
        $threshold = (float) apply_filters('lp_cargo_fs_threshold', PHP_FLOAT_MAX);
        $label     = apply_filters('lp_cargo_fs_label', 'Fraktfri (bonus)');
        $applied_any = false;

        foreach ($rates as $id => $rate) {
            if (!($rate instanceof WC_Shipping_Rate)) continue;
            $cost = (float)$rate->get_cost();
            if ($cost <= $threshold) {
                if (method_exists($rate,'set_cost')) $rate->set_cost(0.0);
                if (method_exists($rate,'get_taxes') && method_exists($rate,'set_taxes')) {
                    $taxes = (array)$rate->get_taxes();
                    foreach ($taxes as $k=>$v) { $taxes[$k] = 0.0; }
                    $rate->set_taxes($taxes);
                }
                if (method_exists($rate,'set_label')) $rate->set_label($label); else $rate->label = $label;
                $applied_any = true;
            }
        }

        if (!$applied_any) {
            $free_id    = 'lp_fs_free:bonus';
            $free_label = $label;
            $new_rate   = new WC_Shipping_Rate($free_id, $free_label, 0, [], 'lp_fs_free');
            $rates[$free_id] = $new_rate;
            $applied_any = true;
        }

        if ($applied_any && function_exists('WC') && WC()->session) {
            WC()->session->set('lp_fs_applied', 1);
        }
        return $rates;
    }

    public function maybe_mark_freeship_used($order_id, $posted, $order){
        if (get_option(self::OPT_FS_BONUS_ENABLE,'1')!=='1') return;
        $applied = (function_exists('WC') && WC()->session) ? WC()->session->get('lp_fs_applied') : null;
        $st = $this->fs_status_for_current_visitor();
        if (!$applied || !$st['active']) return;

        $nonce = (string)($st['nonce'] ?? '');
        if ($nonce) {
            set_transient('lpfs_used_'.$nonce, 1, DAY_IN_SECONDS);
            $row = get_transient('lpfs_nonce_'.$nonce);
            if (is_array($row)) { $row['used']=1; set_transient('lpfs_nonce_'.$nonce, $row, max(60, (int)$row['until']-time())); }
        }

        $iph = (string)($st['iph'] ?? '');
        if ($iph) {
            $row = get_transient('lpfs_ip_'.$iph);
            if (is_array($row)) { $row['used']=1; set_transient('lpfs_ip_'.$iph, $row, max(60, (int)$row['until']-time())); }
        }

        if ($order && method_exists($order,'add_order_note')) $order->add_order_note('Fraktfri i 24t brukt.', false, true);
        if ($order && method_exists($order,'update_meta_data')) {
            $order->update_meta_data('_lp_fs_used', 1);
            $order->update_meta_data('_lp_fs_used_ts', time());
            if ($nonce) $order->update_meta_data('_lp_fs_nonce', $nonce);
            $order->save();
        }

        if (function_exists('WC') && WC()->session) WC()->session->__unset('lp_fs_applied');

        // Fjern cookie på denne enheten
        if (function_exists('wc_setcookie')) wc_setcookie(self::COOKIE_FS_KEY, '', time()-3600, is_ssl());
        @setcookie(self::COOKIE_FS_KEY, '', ['expires'=>time()-3600,'path'=>COOKIEPATH ?: '/', 'secure'=>is_ssl(), 'httponly'=>true, 'samesite'=>'Lax']);

        $this->stats_log('_lp_fs_used');
    }

    /* ===================== Dashboard widget (lett) ===================== */
    private function stats_log($key){
        $opt = get_option($key, []);
        if (!is_array($opt)) $opt = [];
        $opt[] = time();
        if (count($opt) > 5000) $opt = array_slice($opt, -2000);
        update_option($key, $opt, false);
    }
    private function stats_count_last_days($key,$days){
        $list = get_option($key, []);
        if (!is_array($list) || !$list) return 0;
        $cut = time() - ($days*DAY_IN_SECONDS);
        $n=0; foreach($list as $ts){ if ((int)$ts >= $cut) $n++; }
        return $n;
    }
    public function register_dashboard_widget(){
        if (!current_user_can('manage_woocommerce')) return;
        wp_add_dashboard_widget('lp_cargo_returns_widget','Cargonizer Retur – nøkkeltall',[$this,'render_dashboard_widget']);
    }
    public function render_dashboard_widget(){
        if (!class_exists('WC_Order_Query')) { echo '<p>WooCommerce ikke tilgjengelig.</p>'; return; }
        $cache = get_transient('lp_cargo_dash_cache');
        if ($cache && is_array($cache)) { $total_returns=$cache['total_returns']; $items_total=$cache['items_total']; $small=$cache['small']; $large=$cache['large']; $oversize=$cache['oversize']; }
        else {
            $since = (new DateTime('-30 days'))->format('Y-m-d H:i:s');
            $total_returns=0; $items_total=0; $small=0; $large=0; $oversize=0;
            $page=1; $per=200;
            do{
                $query = new WC_Order_Query(['limit'=>$per,'page'=>$page,'type'=>'shop_order','date_created'=>'>'.$since,'return'=>'ids','meta_query'=>[['key'=>self::META_LOCKED,'value'=>'1','compare'=>'=']]]);
                $ids = $query->get_orders();
                foreach ($ids as $oid) {
                    $o = wc_get_order($oid); if (!$o) continue;
                    $total_returns++;
                    $map = (array)$o->get_meta(self::META_RETURNED_QTY);
                    foreach ($map as $qty) $items_total += (int)$qty;
                    $ps = (string)$o->get_meta(self::META_PARCEL_SIZE);
                    if ($ps === 'oversize') $oversize++; elseif ($ps === 'large') $large++; else $small++;
                }
                $page++;
            } while (!empty($ids) && count($ids)===$per);
            set_transient('lp_cargo_dash_cache', compact('total_returns','items_total','small','large','oversize'), 10*MINUTE_IN_SECONDS);
        }
        $g = $this->stats_count_last_days('_lp_fs_granted', 30);
        $u = $this->stats_count_last_days('_lp_fs_used', 30);
        $conv = ($g>0) ? round(($u/$g)*100) : 0;
        echo '<ul style="margin:0;padding-left:18px">';
        echo '<li><strong>Siste 30 dager:</strong></li>';
        echo '<li>Antall returer: '.intval($total_returns).'</li>';
        echo '<li>Antall varer returnert: '.intval($items_total).'</li>';
        echo '<li>Fordeling: Liten '.intval($small).' – Stor '.intval($large).' – Overdim. '.intval($oversize).'</li>';
        echo '<li style="margin-top:8px"><strong>Fraktfri i 24t:</strong></li>';
        echo '<li>Tildelt: '.intval($g).'</li>';
        echo '<li>Brukt: '.intval($u).' (konvertering '.$conv.'%)</li>';
        echo '</ul>';
    }

    /* ===================== Returlogg: DB, logg, admin-liste, CSV ===================== */

    private function maybe_create_log_table(){
        global $wpdb;
        $ver = get_option(self::LOG_DBVER);
        if ($ver === '2') return;
        $table = $wpdb->prefix . self::LOG_TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created DATETIME NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(190) NOT NULL,
            reason VARCHAR(190) DEFAULT NULL,
            carrier VARCHAR(60) DEFAULT NULL,
            parcel_size VARCHAR(20) DEFAULT NULL,
            label_url TEXT DEFAULT NULL,
            tracking_url TEXT DEFAULT NULL,
            received DATETIME DEFAULT NULL,
            refunded DATETIME DEFAULT NULL,
            new_order_id BIGINT UNSIGNED DEFAULT NULL,
            fs_nonce VARCHAR(32) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY email (email),
            KEY created (created)
        ) $charset;";
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option(self::LOG_DBVER,'2', true);
    }

    private function find_new_order_after_return($customer_email, $after_datetime, $fs_nonce = ''){
        $hours = (int)get_option(self::OPT_FS_BONUS_HOURS,24);
        $after_ts = is_numeric($after_datetime) ? (int)$after_datetime : strtotime($after_datetime);
        $cutoff_ts = $after_ts + ($hours * HOUR_IN_SECONDS);

        $queries = [];

        // Primær: match på nonce + brukt fraktfri
        if ($fs_nonce !== '') {
            $queries[] = [
                'meta_query' => [
                    'relation' => 'AND',
                    [ 'key' => '_lp_fs_used',  'value' => 1,          'compare' => '=' ],
                    [ 'key' => '_lp_fs_nonce', 'value' => $fs_nonce,  'compare' => '=' ],
                ],
            ];
        }

        // Sekundær: match på e-post + brukt fraktfri
        $queries[] = [
            'meta_query' => [
                'relation' => 'AND',
                [ 'key' => '_lp_fs_used', 'value' => 1, 'compare' => '=' ],
                [ 'key' => '_billing_email', 'value' => $customer_email, 'compare' => '=' ],
            ],
        ];

        foreach ($queries as $args) {
            $q = new WC_Order_Query(array_merge([
                'limit'        => 20,
                'orderby'      => 'date',
                'order'        => 'ASC',
                'return'       => 'ids',
                'date_created' => '>' . date('Y-m-d H:i:s', $after_ts),
            ], $args));

            $ids = $q->get_orders();
            if (!$ids) continue;
            foreach ($ids as $oid) {
                $o = wc_get_order($oid);
                if (!$o) continue;
                $created = $o->get_date_created();
                $mail    = $o->get_billing_email();
                if ($created && $created->getTimestamp() <= $cutoff_ts) {
                    if ($fs_nonce !== '' && $o->get_meta('_lp_fs_nonce') !== $fs_nonce) continue;
                    if (!empty($customer_email) && !empty($mail) && strcasecmp(trim($mail), trim($customer_email)) !== 0) continue;
                    return (int)$oid;
                }
            }
        }

        return 0;
    }

    public function render_returns_log_page(){
        if (!current_user_can('manage_woocommerce')) return;
        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;

        // CSV export
        if (!empty($_GET['download']) && $_GET['download']==='csv' && check_admin_referer('lp_log_csv','_wpnonce')) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=returlogg-'.date('Ymd-His').'.csv');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID','Dato','Order','Email','Årsak','Carrier','Pakkestørrelse','Label','Tracking','Notat','Ny ordre','FS nonce']);
            $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY created DESC LIMIT 5000");
            foreach ($rows as $r) {
                fputcsv($out, [$r->id,$r->created,$r->order_id,$r->email,$r->reason,$r->carrier,$r->parcel_size,$r->label_url,$r->tracking_url,$r->notes,$r->new_order_id,$r->fs_nonce]);
            }
            fclose($out);
            exit;
        }

        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $where = '1=1';
        if ($search!=='') {
            $like = '%'.$wpdb->esc_like($search).'%';
            $where .= $wpdb->prepare(" AND (email LIKE %s OR order_id = %d)", $like, (int)$search);
        }
        $rows = $wpdb->get_results("SELECT * FROM $table WHERE $where ORDER BY created DESC LIMIT 300");

        echo '<div class="wrap"><h1>Returlogg</h1>';
        echo '<form method="get" style="margin:10px 0">';
        echo '<input type="hidden" name="page" value="lp-cargo-returns-log">';
        echo '<input type="search" name="s" value="'.esc_attr($search).'" placeholder="E-post eller ordrenr." style="width:220px"> ';
        echo '<a class="button button-primary" href="'.esc_url(wp_nonce_url(admin_url('admin.php?page=lp-cargo-returns-log&download=csv'),'lp_log_csv','_wpnonce')).'">Eksporter CSV</a>';
        echo '</form>';

        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Dato</th><th>Ordre</th><th>E-post</th><th>Årsak</th><th>Carrier</th><th>Pakke</th><th>Label</th><th>Notat</th><th>Ny ordre?</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            // Lazy «ny ordre?»
            if (empty($r->new_order_id)) {
                $newId = $this->find_new_order_after_return($r->email, $r->created, $r->fs_nonce ?? '');
                if ($newId) $wpdb->update($table, ['new_order_id'=>$newId], ['id'=>$r->id]);
                $r->new_order_id = $newId;
            }
            $order_link = admin_url('post.php?post='.intval($r->order_id).'&action=edit');
            $new_link   = $r->new_order_id ? admin_url('post.php?post='.intval($r->new_order_id).'&action=edit') : '';
            echo '<tr>';
            echo '<td>'.intval($r->id).'</td>';
            echo '<td>'.esc_html($r->created).'</td>';
            echo '<td><a href="'.esc_url($order_link).'">#'.intval($r->order_id).'</a></td>';
            echo '<td>'.esc_html($r->email).'</td>';
            echo '<td>'.esc_html($r->reason ?: '-').'</td>';
            echo '<td>'.esc_html($r->carrier ?: '-').'</td>';
            echo '<td>'.esc_html($r->parcel_size ?: '-').'</td>';
            echo '<td>'.($r->label_url?'<a href="'.esc_url($r->label_url).'" target="_blank" rel="noopener">PDF</a>':'-').'</td>';
            echo '<td>'.($r->notes ? esc_html($r->notes) : '–').'</td>';
            echo '<td>'.($new_link?'<a href="'.esc_url($new_link).'">#'.intval($r->new_order_id).'</a>':'–').'</td>';
            echo '</tr>';
        }
        if (!$rows) echo '<tr><td colspan="10">Ingen funn.</td></tr>';
        echo '</tbody></table></div>';
    }

    /* ===================== Cron & perf ===================== */

    public function cron_cleanup_labels(){
        $retDays = (int)get_option(self::OPT_LABEL_RETENTION_DAYS, 30);
        $maxAge  = time() - max(7,$retDays)*DAY_IN_SECONDS;
        $upload  = wp_upload_dir();
        $dir     = trailingslashit($upload['basedir']).'cargonizer-labels';
        if (!is_dir($dir) || !wp_is_writable($dir)) return;
        foreach (glob($dir.'/*.pdf') as $file) {
            $mt = @filemtime($file);
            if ($mt && $mt < $maxAge) @unlink($file);
        }
    }
    public function cron_warm_agreements(){
        $this->clear_agreements_cache();
        $this->get_transport_agreements(false, true);
    }

    /** ✅ Alltid aktiv hook: skriv returer til DB-tabellen */
    public function log_return_created($d){
        global $wpdb; 
        $table = $wpdb->prefix . self::LOG_TABLE;
        $wpdb->insert($table, [
            'created'      => $d['created'],
            'order_id'     => (int)$d['order_id'],
            'email'        => sanitize_email($d['email']),
            'reason'       => sanitize_text_field($d['reason']),
            'carrier'      => sanitize_text_field($d['carrier']),
            'parcel_size'  => sanitize_text_field($d['parcel_size']),
            'label_url'    => esc_url_raw($d['label_url']),
            'tracking_url' => esc_url_raw($d['tracking_url']),
            'notes'        => sanitize_text_field($d['notes'] ?? ''),
            'fs_nonce'     => sanitize_text_field($d['fs_nonce'] ?? ''),
        ]);
    }

} // end class

} // End if class exists.
