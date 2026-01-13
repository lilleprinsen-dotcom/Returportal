<?php
/**
 * Admin settings handler for LP Cargonizer Return Portal.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'LP_Cargonizer_Settings' ) ) {

class LP_Cargonizer_Settings {

    /** @var LP_Cargonizer_Returns */
    private $returns;

    public function __construct( LP_Cargonizer_Returns $returns ) {
        $this->returns = $returns;

        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function admin_menu() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }
        add_menu_page(
            'Cargonizer Retur',
            'Cargonizer Retur',
            'manage_woocommerce',
            'lp-cargo-returns',
            [ $this, 'settings_page' ],
            'dashicons-rewind',
            56
        );
        add_submenu_page(
            'lp-cargo-returns',
            'Returlogg',
            'Returlogg',
            'manage_woocommerce',
            'lp-cargo-returns-log',
            [ $this->returns, 'render_returns_log_page' ]
        );
        add_submenu_page(
            'lp-cargo-returns',
            'PO Import',
            'PO Import',
            'manage_woocommerce',
            'lp-cargo-po-import',
            [ $this, 'po_import_page' ]
        );
    }

    public function register_settings() {
        // Kjerne
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_API_KEY, [
            'sanitize_callback' => function ( $v ) {
                $v = trim( (string) $v );
                if ( $v === '' ) {
                    return get_option( LP_Cargonizer_Returns::OPT_API_KEY, '' );
                }
                return sanitize_text_field( $v );
            },
        ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_SENDER_ID, [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_AUTO_TRANSFER, [ 'sanitize_callback' => function ( $v ) { return $v === '1' ? '1' : '0'; } ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_ATTACH_PDF, [ 'sanitize_callback' => function ( $v ) { return $v === '1' ? '1' : '0'; } ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_SWAP_PARTIES, [ 'sanitize_callback' => function ( $v ) { return $v === '1' ? '1' : '0'; } ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_EMAIL_VIA_LOG, [ 'sanitize_callback' => function ( $v ) { return $v === '1' ? '1' : '0'; } ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_TT_NOTIFY, [ 'sanitize_callback' => function ( $v ) { return $v === '1' ? '1' : '0'; } ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_DEFAULT_SERV, [ 'sanitize_callback' => function ( $v ) {
            if ( ! is_array( $v ) ) {
                return [];
            }
            foreach ( $v as $k => &$arr ) {
                $arr = array_values( array_unique( array_map( 'sanitize_text_field', (array) $arr ) ) );
            }
            return $v;
        } ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_ALLOWED, [ 'sanitize_callback' => function ( $v ) {
            return array_values( array_unique( array_map( 'sanitize_text_field', (array) $v ) ) );
        } ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_FEE_SMALL, [ 'sanitize_callback' => function ( $v ) { return (string) max( 0, (int) $v ); } ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_FEE_LARGE, [ 'sanitize_callback' => function ( $v ) { return (string) max( 0, (int) $v ); } ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_RETURN_WINDOW, [ 'sanitize_callback' => function ( $v ) { return (string) max( 0, (int) $v ); } ] );

        // FS + bannerfarge
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_FS_BONUS_ENABLE, [ 'sanitize_callback' => function ( $v ) { return $v === '1' ? '1' : '0'; } ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_FS_BONUS_HOURS, [ 'sanitize_callback' => function ( $v ) { return (string) max( 1, (int) $v ); } ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_FS_BANNER_COLOR, [ 'sanitize_callback' => function ( $v ) {
            $v = trim( (string) $v );
            if ( $v === '' ) {
                return '#0ea5e9';
            }
            return preg_match( '/^#([A-Fa-f0-9]{6})$/', $v ) ? $v : '#0ea5e9';
        } ] );

        // Label gyldighet/retensjon
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_LABEL_VALID_DAYS, [ 'sanitize_callback' => function ( $v ) { return (string) max( 7, (int) $v ); } ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_LABEL_RETENTION_DAYS, [ 'sanitize_callback' => function ( $v ) { return (string) max( 7, (int) $v ); } ] );

        // Support-epost, årsaker, bytte-info
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_SUPPORT_EMAIL, [ 'sanitize_callback' => 'sanitize_email' ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_ADMIN_USERNAME, [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_ADMIN_PIN, [ 'sanitize_callback' => function ( $v ) {
            $pin = preg_replace( '/\D/', '', (string) $v );
            if ( $pin === '' ) {
                return '';
            }
            if ( strlen( $pin ) !== 4 ) {
                return get_option( LP_Cargonizer_Returns::OPT_ADMIN_PIN, '' );
            }
            return $pin;
        } ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_ADMIN_PAGE_TITLE, [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_ADMIN_LOGIN_TITLE, [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_ADMIN_LOGIN_DESC, [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_ADMIN_LOGIN_BUTTON, [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_ADMIN_SEARCH_TITLE, [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_ADMIN_SEARCH_DESC, [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_ADMIN_SEARCH_PLACEHOLDER, [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_ADMIN_STATUS_PROMPT, [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_ADMIN_EMPTY_RESULTS, [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_RETURN_REASONS, [ 'sanitize_callback' => function ( $v ) {
            if ( is_array( $v ) ) {
                $lines = $v;
            } else {
                $lines = preg_split( '/\r\n|\r|\n/', (string) $v );
            }
            $lines = array_values( array_filter( array_map( 'trim', (array) $lines ) ) );
            return $lines ?: [ 'Feil størrelse', 'Ikke som forventet', 'Defekt / varefeil', 'Angrer kjøp', 'Annet' ];
        } ] );
        register_setting( 'lp_cargo_settings', LP_Cargonizer_Returns::OPT_EXCHANGE_INFO, [ 'sanitize_callback' => function ( $v ) {
            $v = trim( (string) $v );
            return $v !== '' ? wp_kses_post( $v ) : 'Ønsker du bytte? Vi dekker frakt på ny forsendelse.';
        } ] );

        // Defaults (autoload)
        if ( get_option( LP_Cargonizer_Returns::OPT_AUTO_TRANSFER, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_AUTO_TRANSFER, '0', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_ATTACH_PDF, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_ATTACH_PDF, '0', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_SWAP_PARTIES, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_SWAP_PARTIES, '0', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_EMAIL_VIA_LOG, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_EMAIL_VIA_LOG, '1', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_TT_NOTIFY, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_TT_NOTIFY, '0', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_DEFAULT_SERV, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_DEFAULT_SERV, [], false );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_ALLOWED, null ) === null ) {
            update_option( LP_Cargonizer_Returns::OPT_ALLOWED, [], false );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_FEE_SMALL, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_FEE_SMALL, '69', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_FEE_LARGE, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_FEE_LARGE, '129', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_RETURN_WINDOW, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_RETURN_WINDOW, '30', true );
        }

        if ( get_option( LP_Cargonizer_Returns::OPT_FS_BONUS_ENABLE, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_FS_BONUS_ENABLE, '1', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_FS_BONUS_HOURS, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_FS_BONUS_HOURS, '24', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_FS_BANNER_COLOR, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_FS_BANNER_COLOR, '#0ea5e9', true );
        }

        if ( get_option( LP_Cargonizer_Returns::OPT_LABEL_VALID_DAYS, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_LABEL_VALID_DAYS, '14', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_LABEL_RETENTION_DAYS, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_LABEL_RETENTION_DAYS, '30', true );
        }

        if ( get_option( LP_Cargonizer_Returns::OPT_SUPPORT_EMAIL, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_SUPPORT_EMAIL, get_option( 'admin_email' ), true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_ADMIN_USERNAME, null ) === null ) {
            update_option( LP_Cargonizer_Returns::OPT_ADMIN_USERNAME, '', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_ADMIN_PIN, null ) === null ) {
            update_option( LP_Cargonizer_Returns::OPT_ADMIN_PIN, '', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_ADMIN_PAGE_TITLE, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_ADMIN_PAGE_TITLE, 'Returadmin', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_ADMIN_LOGIN_TITLE, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_ADMIN_LOGIN_TITLE, 'Admin Login', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_ADMIN_LOGIN_DESC, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_ADMIN_LOGIN_DESC, 'Logg inn for å søke opp ordre.', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_ADMIN_LOGIN_BUTTON, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_ADMIN_LOGIN_BUTTON, 'Logg inn', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_ADMIN_SEARCH_TITLE, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_ADMIN_SEARCH_TITLE, 'Ordresøk', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_ADMIN_SEARCH_DESC, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_ADMIN_SEARCH_DESC, 'Søk på ordrenummer eller kundenavn. Viser ordre sortert etter ordredato.', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_ADMIN_SEARCH_PLACEHOLDER, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_ADMIN_SEARCH_PLACEHOLDER, 'Ordrenummer eller kundenavn', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_ADMIN_STATUS_PROMPT, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_ADMIN_STATUS_PROMPT, 'Logg inn for å søke etter ordre.', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_ADMIN_EMPTY_RESULTS, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_ADMIN_EMPTY_RESULTS, 'Ingen ordre funnet for dette søket.', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_EXCHANGE_INFO, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_EXCHANGE_INFO, 'Ønsker du bytte? Vi dekker frakt på ny forsendelse.', true );
        }
        if ( get_option( LP_Cargonizer_Returns::OPT_RETURN_REASONS, '' ) === '' ) {
            update_option( LP_Cargonizer_Returns::OPT_RETURN_REASONS, [ 'Feil størrelse', 'Ikke som forventet', 'Defekt / varefeil', 'Angrer kjøp', 'Annet' ], false );
        }

        // API-key autoload=no
        $val = get_option( LP_Cargonizer_Returns::OPT_API_KEY, null );
        if ( $val === null ) {
            add_option( LP_Cargonizer_Returns::OPT_API_KEY, '', '', 'no' );
        } else {
            update_option( LP_Cargonizer_Returns::OPT_API_KEY, $val, false );
        }
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $nonce_fetch = wp_create_nonce( LP_Cargonizer_Returns::NONCE );
        $api         = get_option( LP_Cargonizer_Returns::OPT_API_KEY, '' );
        $sender      = get_option( LP_Cargonizer_Returns::OPT_SENDER_ID, '' );
        $feeS        = get_option( LP_Cargonizer_Returns::OPT_FEE_SMALL, '69' );
        $feeL        = get_option( LP_Cargonizer_Returns::OPT_FEE_LARGE, '129' );
        $window      = get_option( LP_Cargonizer_Returns::OPT_RETURN_WINDOW, '30' );
        $fsOn        = get_option( LP_Cargonizer_Returns::OPT_FS_BONUS_ENABLE, '1' );
        $fsHrs       = get_option( LP_Cargonizer_Returns::OPT_FS_BONUS_HOURS, '24' );
        $fsCol       = get_option( LP_Cargonizer_Returns::OPT_FS_BANNER_COLOR, '#0ea5e9' );
        $labelV      = get_option( LP_Cargonizer_Returns::OPT_LABEL_VALID_DAYS, '14' );
        $labelR      = get_option( LP_Cargonizer_Returns::OPT_LABEL_RETENTION_DAYS, '30' );
        $support     = get_option( LP_Cargonizer_Returns::OPT_SUPPORT_EMAIL, get_option( 'admin_email' ) );
        $admin_user  = get_option( LP_Cargonizer_Returns::OPT_ADMIN_USERNAME, '' );
        $admin_pin   = get_option( LP_Cargonizer_Returns::OPT_ADMIN_PIN, '' );
        $admin_page_title = get_option( LP_Cargonizer_Returns::OPT_ADMIN_PAGE_TITLE, 'Returadmin' );
        $admin_login_title = get_option( LP_Cargonizer_Returns::OPT_ADMIN_LOGIN_TITLE, 'Admin Login' );
        $admin_login_desc = get_option( LP_Cargonizer_Returns::OPT_ADMIN_LOGIN_DESC, 'Logg inn for å søke opp ordre.' );
        $admin_login_button = get_option( LP_Cargonizer_Returns::OPT_ADMIN_LOGIN_BUTTON, 'Logg inn' );
        $admin_search_title = get_option( LP_Cargonizer_Returns::OPT_ADMIN_SEARCH_TITLE, 'Ordresøk' );
        $admin_search_desc = get_option( LP_Cargonizer_Returns::OPT_ADMIN_SEARCH_DESC, 'Søk på ordrenummer eller kundenavn. Viser ordre sortert etter ordredato.' );
        $admin_search_placeholder = get_option( LP_Cargonizer_Returns::OPT_ADMIN_SEARCH_PLACEHOLDER, 'Ordrenummer eller kundenavn' );
        $admin_status_prompt = get_option( LP_Cargonizer_Returns::OPT_ADMIN_STATUS_PROMPT, 'Logg inn for å søke etter ordre.' );
        $admin_empty_results = get_option( LP_Cargonizer_Returns::OPT_ADMIN_EMPTY_RESULTS, 'Ingen ordre funnet for dette søket.' );
        $reasons     = (array) get_option( LP_Cargonizer_Returns::OPT_RETURN_REASONS, [] );
        $exInfo      = get_option( LP_Cargonizer_Returns::OPT_EXCHANGE_INFO, '' );

        echo '<div class="wrap"><h1>Cargonizer Retur</h1><form method="post" action="options.php">';
        settings_fields( 'lp_cargo_settings' );
        echo '<table class="form-table" role="presentation">';

        // API/key
        $hasKey = $api !== '';
        echo '<tr><th>API-nøkkel (X-Cargonizer-Key)</th><td><input type="password" name="' . LP_Cargonizer_Returns::OPT_API_KEY . '" value="" autocomplete="off" style="width:420px" placeholder="' . ( $hasKey ? '••••••••' : '' ) . '"><p class="description">' . ( $hasKey ? 'La felt tom for å beholde eksisterende nøkkel.' : 'Lim inn nøkkel.' ) . '</p></td></tr>';
        echo '<tr><th>Avsender-ID (X-Cargonizer-Sender)</th><td><input type="text" name="' . LP_Cargonizer_Returns::OPT_SENDER_ID . '" value="' . esc_attr( $sender ) . '" style="width:320px"></td></tr>';
        echo '<tr><th>Test API</th><td><button type="button" class="button" id="lp-cargo-test">Test API-tilkobling</button> <span id="lp-cargo-test-result" style="margin-left:8px"></span><p class="description">Tips: Hvis <code>WP_HTTP_BLOCK_EXTERNAL</code> er aktivert må <code>WP_ACCESSIBLE_HOSTS</code> inkludere <code>api.cargonizer.no</code>, ellers blokkeres kall.</p></td></tr>';

        // Overføring
        $auto   = get_option( LP_Cargonizer_Returns::OPT_AUTO_TRANSFER, '0' ) === '1' ? 'checked' : '';
        $attach = get_option( LP_Cargonizer_Returns::OPT_ATTACH_PDF, '0' ) === '1' ? 'checked' : '';
        $swap   = get_option( LP_Cargonizer_Returns::OPT_SWAP_PARTIES, '0' ) === '1' ? 'checked' : '';
        $via    = get_option( LP_Cargonizer_Returns::OPT_EMAIL_VIA_LOG, '1' ) === '1' ? 'checked' : '';
        $tt     = get_option( LP_Cargonizer_Returns::OPT_TT_NOTIFY, '0' ) === '1' ? 'checked' : '';
        echo '<tr><th>Automatisk overføring</th><td><label><input type="checkbox" name="' . LP_Cargonizer_Returns::OPT_AUTO_TRANSFER . '" value="1" ' . $auto . '> Overfør automatisk til Logistra/Cargonizer når kunde har bekreftet retur</label><p class="description">Huk av hvis dere ønsker at returer skal videresendes automatisk til Logistra/Cargonizer og merkelapper genereres uten manuell oppfølging.</p></td></tr>';
        echo '<tr><th>Overførings-innstillinger</th><td><label><input type="checkbox" name="' . LP_Cargonizer_Returns::OPT_ATTACH_PDF . '" value="1" ' . $attach . '> Lagre PDF i Media Library og send/vis lenke til kunde</label><br><label><input type="checkbox" name="' . LP_Cargonizer_Returns::OPT_SWAP_PARTIES . '" value="1" ' . $swap . '> Bytt avsender/mottaker på returlabel (kunde som avsender)</label><br><label><input type="checkbox" name="' . LP_Cargonizer_Returns::OPT_EMAIL_VIA_LOG . '" value="1" ' . $via . '> Send returepost via Logistra/Cargonizer (anbefalt)</label><br><label><input type="checkbox" name="' . LP_Cargonizer_Returns::OPT_TT_NOTIFY . '" value="1" ' . $tt . '> Send tracking-varsler til kunde via Logistra/Transsmart</label></td></tr>';

        echo '<tr><th>Gebyrer</th><td><label>Lite kolli (≤ 35x25x12 cm) <input type="number" name="' . LP_Cargonizer_Returns::OPT_FEE_SMALL . '" min="0" step="1" value="' . esc_attr( $feeS ) . '" style="width:120px"> kr</label><br><label>Stort kolli (over minstegrense) <input type="number" name="' . LP_Cargonizer_Returns::OPT_FEE_LARGE . '" min="0" step="1" value="' . esc_attr( $feeL ) . '" style="width:120px"> kr</label><br><small>Gebyr settes på ordren som tilpasset refusjon.</small></td></tr>';

        echo '<tr><th>Returvindu</th><td><input type="number" name="' . LP_Cargonizer_Returns::OPT_RETURN_WINDOW . '" value="' . esc_attr( $window ) . '" min="1" step="1" style="width:120px"> dager</td></tr>';

        // Gratis frakt-bonus (f.eks. 24t etter kjøp)
        $fsChecked = $fsOn === '1' ? 'checked' : '';
        echo '<tr><th>Fraktfri bonus</th><td><label><input type="checkbox" name="' . LP_Cargonizer_Returns::OPT_FS_BONUS_ENABLE . '" value="1" ' . $fsChecked . '> Aktiver fraktfri bonus etter kjøp</label><p class="description">Gir kunder et tidsbegrenset fraktfritt retur-alternativ.</p></td></tr>';
        echo '<tr><th>Bonus-varighet</th><td><input type="number" name="' . LP_Cargonizer_Returns::OPT_FS_BONUS_HOURS . '" value="' . esc_attr( $fsHrs ) . '" min="1" max="120" step="1" style="width:120px"> timer</td></tr>';
        echo '<tr><th>Bannerfarge</th><td><input type="color" name="' . LP_Cargonizer_Returns::OPT_FS_BANNER_COLOR . '" value="' . esc_attr( $fsCol ) . '" style="width:160px"></td></tr>';

        echo '<tr><th>Label-gyldighet</th><td><input type="number" name="' . LP_Cargonizer_Returns::OPT_LABEL_VALID_DAYS . '" value="' . esc_attr( $labelV ) . '" min="7" max="60" step="1" style="width:120px"> dager</td></tr>';
        echo '<tr><th>Slett gamle labels</th><td><input type="number" name="' . LP_Cargonizer_Returns::OPT_LABEL_RETENTION_DAYS . '" value="' . esc_attr( $labelR ) . '" min="7" max="180" step="1" style="width:120px"> dager</td></tr>';

        echo '<tr><th>Support-epost</th><td><input type="email" name="' . LP_Cargonizer_Returns::OPT_SUPPORT_EMAIL . '" value="' . esc_attr( $support ) . '" style="width:320px"><p class="description">Vises til kunden i returportalen.</p></td></tr>';
        echo '<tr><th colspan="2"><h2>Admin-frontend</h2><p class="description">Innstillinger for adminvisningen som er tilgjengelig på <code>' . esc_html( home_url( '/' . LP_Cargonizer_Returns::ADMIN_ROUTE_SLUG . '/' ) ) . '</code>.</p></th></tr>';
        echo '<tr><th>Side-overskrift</th><td><input type="text" name="' . LP_Cargonizer_Returns::OPT_ADMIN_PAGE_TITLE . '" value="' . esc_attr( $admin_page_title ) . '" style="width:320px"></td></tr>';
        echo '<tr><th>Innloggingstekst</th><td><label>Tittel <input type="text" name="' . LP_Cargonizer_Returns::OPT_ADMIN_LOGIN_TITLE . '" value="' . esc_attr( $admin_login_title ) . '" style="width:320px"></label><br><label>Beskrivelse<br><textarea name="' . LP_Cargonizer_Returns::OPT_ADMIN_LOGIN_DESC . '" rows="2" cols="40" style="width:420px">' . esc_textarea( $admin_login_desc ) . '</textarea></label><br><label>Knappetekst <input type="text" name="' . LP_Cargonizer_Returns::OPT_ADMIN_LOGIN_BUTTON . '" value="' . esc_attr( $admin_login_button ) . '" style="width:200px"></label></td></tr>';
        echo '<tr><th>Innlogging</th><td><label>Brukernavn <input type="text" name="' . LP_Cargonizer_Returns::OPT_ADMIN_USERNAME . '" value="' . esc_attr( $admin_user ) . '" style="width:240px"></label><br><label>PIN (4 siffer) <input type="password" name="' . LP_Cargonizer_Returns::OPT_ADMIN_PIN . '" value="' . esc_attr( $admin_pin ) . '" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" style="width:120px"></label><p class="description">Brukes på admin-innloggingssiden i frontend.</p></td></tr>';
        echo '<tr><th>Ordresøk</th><td><label>Tittel <input type="text" name="' . LP_Cargonizer_Returns::OPT_ADMIN_SEARCH_TITLE . '" value="' . esc_attr( $admin_search_title ) . '" style="width:320px"></label><br><label>Beskrivelse<br><textarea name="' . LP_Cargonizer_Returns::OPT_ADMIN_SEARCH_DESC . '" rows="2" cols="40" style="width:420px">' . esc_textarea( $admin_search_desc ) . '</textarea></label><br><label>Placeholder <input type="text" name="' . LP_Cargonizer_Returns::OPT_ADMIN_SEARCH_PLACEHOLDER . '" value="' . esc_attr( $admin_search_placeholder ) . '" style="width:320px"></label></td></tr>';
        echo '<tr><th>Statusmeldinger</th><td><label>Starttekst<br><textarea name="' . LP_Cargonizer_Returns::OPT_ADMIN_STATUS_PROMPT . '" rows="2" cols="40" style="width:420px">' . esc_textarea( $admin_status_prompt ) . '</textarea></label><br><label>Ingen treff<br><textarea name="' . LP_Cargonizer_Returns::OPT_ADMIN_EMPTY_RESULTS . '" rows="2" cols="40" style="width:420px">' . esc_textarea( $admin_empty_results ) . '</textarea></label></td></tr>';
        echo '<tr><th>Årsaker</th><td><textarea name="' . LP_Cargonizer_Returns::OPT_RETURN_REASONS . '" rows="4" cols="40" style="width:320px">' . esc_textarea( implode( "\n", $reasons ) ) . '</textarea><p class="description">En per linje.</p></td></tr>';
        echo '<tr><th>Bytte-informasjon</th><td><textarea name="' . LP_Cargonizer_Returns::OPT_EXCHANGE_INFO . '" rows="3" cols="40" style="width:420px">' . esc_textarea( $exInfo ) . '</textarea><p class="description">Vises når kunde velger bytte.</p></td></tr>';

        // Dynamic agreements/services UI via AJAX
        echo '<tr><th>Avtaler</th><td><button class="button" id="lp-cargo-load">Last avtaler og tjenester</button><div id="lp-cargo-agreements" style="margin-top:10px; padding:12px; background:#fff; border:1px solid #ccd0d4; max-width:760px"></div></td></tr>';

        echo '<tr><th>Lås opp ordre</th><td><p><label for="lp_unlock_order_id">Ordrenummer</label><br><input type="text" id="lp_unlock_order_id" value="" style="width:140px" placeholder="#1234"> <button type="button" class="button" id="lp_unlock_btn">Lås opp</button></p><p class="description">Hvis kunden har fått beskjed om at returen er låst, kan du låse opp her.</p></td></tr>';

        echo '</table>';
        submit_button();
        echo '</form></div>';

        // Inline admin JS (opprinnelig bundet i PHP)
        $ajax = <<<'JS'
(function(){
 const btn=document.getElementById('lp-cargo-load');
 const box=document.getElementById('lp-cargo-agreements');
 const nonce='%NONCE%';
 const AJ='%AJAX%';
 let dirty=false;
 function post(action, payload){
   const form=new URLSearchParams();
   form.append('action',action); form.append('_wpnonce',nonce);
   Object.keys(payload).forEach(function(k){
     if(payload[k]===undefined) return;
     if(payload[k] instanceof Array || typeof payload[k]==='object'){
       form.append(k, JSON.stringify(payload[k]));
     } else {
       form.append(k,payload[k]);
     }
   });
   return fetch(AJ,{
     method:'POST',
     headers:{'Content-Type':'application/x-www-form-urlencoded'},
     body:form.toString(),
     credentials:'same-origin'
   }).then(function(r){
     if(!r.ok) throw new Error('HTTP '+r.status);
     return r.text();
   });
 }

 async function load(force){
   box.innerHTML='Laster...';
   const action = force ? 'lp_cargo_refresh_agreements' : 'lp_cargo_fetch_agreements';
   const html=await post(action,{});
   box.innerHTML=html;
   dirty=false;

   // mark dirty on change
  if(!box.dataset.boundChange){
    box.addEventListener('change', function(){
      box.querySelectorAll('.lp-cargo-agree-status').forEach(function(s){
        s.textContent='Endringer ikke lagret';
      });
      dirty=true;
    });
    box.dataset.boundChange='1';
  }

   // save all
   box.querySelectorAll('.lp-cargo-agree-save').forEach(function(saveBtn){
     saveBtn.addEventListener('click', async function(ev){
       ev.preventDefault();
       var container = saveBtn.closest ? saveBtn.closest('.lp-agree-actions') : null;
       var status = container ? container.querySelector('.lp-cargo-agree-status')
                              : box.querySelector('.lp-cargo-agree-status');
       if(status){ status.textContent='Lagrer...'; }

       const allowed=[].slice.call(box.querySelectorAll('.lp-cargo-checkbox:checked')).map(function(cb){return cb.value;});
       const defaults={};
       [].slice.call(box.querySelectorAll('.lp-cargo-serv-cb:checked')).forEach(function(cb){
         const k=cb.getAttribute('data-key'); if(!defaults[k]) defaults[k]=[];
         defaults[k].push(cb.value);
       });

       try{
         const t=await post('lp_cargo_save_all',{allowed:allowed, defaults:defaults});
         let j=null; try{ j=JSON.parse(t); }catch(e){}
         if(j && j.success){
           box.querySelectorAll('.lp-cargo-agree-status').forEach(function(s){ s.textContent='Lagret ✓'; });
           dirty=false;
         } else {
           if(status){ status.textContent='Kunne ikke lagre'; }
           alert('Kunne ikke lagre');
         }
       }catch(e){
         if(status){ status.textContent='Feil under lagring'; }
         alert('Feil under lagring');
       }
     });
   });

  box.querySelectorAll('.lp-cargo-agree-refresh').forEach(function(refreshBtn){
    refreshBtn.addEventListener('click', function(ev){
      ev.preventDefault();
      var status = refreshBtn.closest ? refreshBtn.closest('.lp-agree-actions') : null;
      status = status ? status.querySelector('.lp-cargo-agree-status') : box.querySelector('.lp-cargo-agree-status');
      if(status){ status.textContent='Oppdaterer...'; }
      load(true);
    });
  });

  // Unlock
   const ub=document.getElementById('lp_unlock_btn');
   if(ub){ ub.addEventListener('click', async function(){
     const order=(document.getElementById('lp_unlock_order_id').value||'').replace(/^#/,'');
     if(!order){ alert('Skriv ordrenummer'); return; }
     const form=new URLSearchParams(); form.append('action','lp_cargo_admin_unlock_order'); form.append('_wpnonce',nonce); form.append('order',order);
const r=await fetch(AJ,{
  method:'POST',
  headers:{'Content-Type':'application/x-www-form-urlencoded'},
  body:form.toString(),
  credentials:'same-origin'
});     const j=await r.json().catch(function(){return null;});
     alert(j&&j.success?j.data.msg:(j&&j.data&&j.data.msg?j.data.msg:'Kunne ikke låse opp.'));
   });}
 }

// Init
if (btn) {
  btn.addEventListener('click', function(ev){ ev.preventDefault(); load(false); });
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){ load(false); });
  } else {
    load(false);
  }
}

 // ✅ Test API-tilkobling — virker uavhengig av "Last avtaler"
 (function(){
   const testBtn = document.getElementById('lp-cargo-test');
   if(!testBtn) return;
   testBtn.addEventListener('click', async function(ev){
     ev.preventDefault();
     const out = document.getElementById('lp-cargo-test-result');
     if(out) out.textContent='Tester...';
     const keyEl = document.querySelector('input[name="%OPT_API_KEY%"]');
     const sndEl = document.querySelector('input[name="%OPT_SENDER_ID%"]');
     const form = new URLSearchParams();
     form.append('action','lp_cargo_test_api');
     form.append('_wpnonce',nonce);
     form.append('key', (keyEl ? (keyEl.value||'') : '').trim());
     form.append('sender',(sndEl ? (sndEl.value||'') : '').trim());
     try{
       const r = await fetch(AJ,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:form.toString()});
       const j = await r.json().catch(function(){return null;});
       if(j && j.success){ if(out) out.textContent = 'OK ('+(j.data.code||200)+')'; }
       else { if(out) out.textContent = (j && j.data && j.data.msg) ? j.data.msg : 'Feil'; }
     }catch(e){
       if(out) out.textContent = 'Nettverksfeil';
     }
   });
 })();
})();
JS;

        $ajax = str_replace( '%NONCE%', $nonce_fetch, $ajax );
        $ajax = str_replace( '%AJAX%', admin_url( 'admin-ajax.php' ), $ajax );
        $ajax = str_replace( '%OPT_API_KEY%', LP_Cargonizer_Returns::OPT_API_KEY, $ajax );
        $ajax = str_replace( '%OPT_SENDER_ID%', LP_Cargonizer_Returns::OPT_SENDER_ID, $ajax );
        echo '<script>' . $ajax . '</script>';
    }

    public function po_import_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        if ( isset( $_GET['download'] ) && $_GET['download'] === 'po-template' && check_admin_referer( 'lp_po_template', '_wpnonce' ) ) {
            $this->download_po_template();
            exit;
        }

        $messages = [];
        $errors   = [];
        $review   = [];

        if ( isset( $_POST['lp_po_upload'] ) && check_admin_referer( 'lp_po_upload', 'lp_po_nonce' ) ) {
            $parse = $this->parse_po_import_file( $_FILES['po_file'] ?? [] );
            if ( ! empty( $parse['errors'] ) ) {
                $errors = array_merge( $errors, $parse['errors'] );
            } else {
                $review = $this->build_po_review_data( $parse['rows'] );
                if ( ! empty( $review['errors'] ) ) {
                    $errors = array_merge( $errors, $review['errors'] );
                }
                $review = $review['data'] ?? [];
            }
        } elseif ( isset( $_POST['lp_po_import'] ) && check_admin_referer( 'lp_po_import', 'lp_po_nonce' ) ) {
            $submitted = $this->normalize_po_submission( $_POST['po'] ?? [] );
            $review    = $this->build_po_review_from_submission( $submitted );

            if ( empty( $review['errors'] ) ) {
                $import = $this->import_purchase_orders( $review['data'] );
                if ( ! empty( $import['errors'] ) ) {
                    $errors = array_merge( $errors, $import['errors'] );
                    $review = $import['review'];
                } else {
                    $messages[] = sprintf( 'Importerte %d purchase orders.', $import['imported'] );
                    if ( $import['skipped'] ) {
                        $messages[] = sprintf( 'Hoppet over %d purchase orders.', $import['skipped'] );
                    }
                }
            } else {
                $errors = array_merge( $errors, $review['errors'] );
                $review = $review['data'];
            }
        }

        echo '<div class="wrap"><h1>PO Import</h1>';
        echo '<p>Importer purchase orders fra en Excel-kompatibel fil med én verdi per kolonne.</p>';
        echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=lp-cargo-po-import&download=po-template' ), 'lp_po_template' ) ) . '">Last ned Excel-mal (CSV)</a></p>';

        if ( $messages ) {
            foreach ( $messages as $message ) {
                echo '<div class="notice notice-success"><p>' . esc_html( $message ) . '</p></div>';
            }
        }
        if ( $errors ) {
            foreach ( $errors as $error ) {
                echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
            }
        }

        echo '<form method="post" enctype="multipart/form-data" style="margin-top:16px">';
        wp_nonce_field( 'lp_po_upload', 'lp_po_nonce' );
        echo '<label for="po_file"><strong>Velg fil</strong></label><br>';
        echo '<input type="file" id="po_file" name="po_file" accept=".csv,.xlsx" required> ';
        echo '<button type="submit" class="button button-primary" name="lp_po_upload" value="1">Last opp</button>';
        echo '</form>';

        if ( $review ) {
            $this->render_po_review_form( $review );
        }

        echo '</div>';
    }

    private function download_po_template() {
        $header = [ 'PO Number', 'SKU', 'Product Name', 'Quantity' ];
        $rows   = [
            $header,
            [ 'PO-1001', 'SKU-123', 'Produktnavn', '10' ],
        ];
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=po-import-template.csv' );
        $out = fopen( 'php://output', 'w' );
        foreach ( $rows as $row ) {
            fputcsv( $out, $row );
        }
        fclose( $out );
    }

    private function parse_po_import_file( array $file ) {
        $errors = [];
        if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
            $errors[] = 'Ingen fil valgt eller opplasting feilet.';
            return [ 'errors' => $errors ];
        }
        $ext = strtolower( pathinfo( $file['name'] ?? '', PATHINFO_EXTENSION ) );
        $upload = wp_handle_upload( $file, [
            'test_form' => false,
            'mimes'     => [
                'csv'  => 'text/csv',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        ] );
        if ( isset( $upload['error'] ) ) {
            return [ 'errors' => [ $upload['error'] ] ];
        }

        $path = $upload['file'];
        if ( $ext === 'xlsx' ) {
            $rows = $this->parse_po_import_xlsx( $path );
        } else {
            $rows = $this->parse_po_import_csv( $path );
        }

        if ( ! $rows ) {
            return [ 'errors' => [ 'Fant ingen rader i importfilen.' ] ];
        }

        return [ 'rows' => $rows ];
    }

    private function parse_po_import_csv( $path ) {
        $rows = [];
        if ( ! file_exists( $path ) ) {
            return $rows;
        }
        if ( ( $handle = fopen( $path, 'r' ) ) === false ) {
            return $rows;
        }
        while ( ( $data = fgetcsv( $handle ) ) !== false ) {
            $rows[] = $data;
        }
        fclose( $handle );
        return $rows;
    }

    private function parse_po_import_xlsx( $path ) {
        $rows = [];
        if ( ! class_exists( 'ZipArchive' ) ) {
            return $rows;
        }
        $zip = new ZipArchive();
        if ( $zip->open( $path ) !== true ) {
            return $rows;
        }
        $sharedStrings = [];
        $sharedXml = $zip->getFromName( 'xl/sharedStrings.xml' );
        if ( $sharedXml ) {
            $shared = simplexml_load_string( $sharedXml );
            if ( $shared && isset( $shared->si ) ) {
                foreach ( $shared->si as $si ) {
                    $text = '';
                    if ( isset( $si->t ) ) {
                        $text = (string) $si->t;
                    } elseif ( isset( $si->r ) ) {
                        foreach ( $si->r as $run ) {
                            $text .= (string) $run->t;
                        }
                    }
                    $sharedStrings[] = $text;
                }
            }
        }

        $sheetXml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
        if ( ! $sheetXml ) {
            $zip->close();
            return $rows;
        }
        $sheet = simplexml_load_string( $sheetXml );
        if ( ! $sheet || ! isset( $sheet->sheetData->row ) ) {
            $zip->close();
            return $rows;
        }

        foreach ( $sheet->sheetData->row as $row ) {
            $cells = [];
            foreach ( $row->c as $cell ) {
                $ref = (string) $cell['r'];
                $col = preg_replace( '/\d+/', '', $ref );
                $index = $this->xlsx_column_index( $col );
                $value = '';
                if ( isset( $cell->v ) ) {
                    $value = (string) $cell->v;
                    if ( (string) $cell['t'] === 's' ) {
                        $value = $sharedStrings[ (int) $value ] ?? '';
                    }
                } elseif ( isset( $cell->is->t ) ) {
                    $value = (string) $cell->is->t;
                }
                $cells[ $index ] = $value;
            }
            if ( $cells ) {
                $max = max( array_keys( $cells ) );
                $line = [];
                for ( $i = 0; $i <= $max; $i++ ) {
                    $line[] = $cells[ $i ] ?? '';
                }
                $rows[] = $line;
            }
        }

        $zip->close();
        return $rows;
    }

    private function xlsx_column_index( $letters ) {
        $letters = strtoupper( $letters );
        $index = 0;
        $len = strlen( $letters );
        for ( $i = 0; $i < $len; $i++ ) {
            $index = $index * 26 + ( ord( $letters[ $i ] ) - 64 );
        }
        return max( 0, $index - 1 );
    }

    private function build_po_review_data( array $rows ) {
        $errors = [];
        $header = array_shift( $rows );
        $map = $this->map_po_headers( $header );
        $required = [ 'po', 'sku', 'product_name', 'quantity' ];
        foreach ( $required as $req ) {
            if ( ! isset( $map[ $req ] ) ) {
                $errors[] = 'Mangler kolonne: ' . strtoupper( str_replace( '_', ' ', $req ) );
            }
        }
        if ( $errors ) {
            return [ 'errors' => $errors ];
        }

        $data = [];
        foreach ( $rows as $row ) {
            $po = trim( $row[ $map['po'] ] ?? '' );
            if ( $po === '' ) {
                continue;
            }
            $sku = trim( $row[ $map['sku'] ] ?? '' );
            $name = trim( $row[ $map['product_name'] ] ?? '' );
            $qtyRaw = trim( $row[ $map['quantity'] ] ?? '' );
            $qty = (int) preg_replace( '/[^\d]/', '', $qtyRaw );
            if ( $qty <= 0 ) {
                $qty = 0;
            }
            if ( ! isset( $data[ $po ] ) ) {
                $data[ $po ] = [
                    'number'    => $po,
                    'collision' => false,
                    'items'     => [],
                ];
            }
            $data[ $po ]['items'][] = [
                'sku'        => $sku,
                'name'       => $name,
                'qty'        => $qty,
                'missing'    => false,
                'product_id' => 0,
            ];
        }

        return $this->finalize_po_review_data( $data );
    }

    private function build_po_review_from_submission( array $submitted ) {
        $data = [];
        foreach ( $submitted as $entry ) {
            if ( empty( $entry['number'] ) ) {
                continue;
            }
            $po = $entry['number'];
            $items = [];
            foreach ( $entry['items'] ?? [] as $item ) {
                $items[] = [
                    'sku'        => $item['sku'] ?? '',
                    'name'       => $item['name'] ?? '',
                    'qty'        => (int) ( $item['qty'] ?? 0 ),
                    'missing'    => false,
                    'product_id' => 0,
                    'ignore'     => ! empty( $item['ignore'] ),
                ];
            }
            $data[ $po ] = [
                'number'    => $po,
                'collision' => false,
                'ignore'    => ! empty( $entry['ignore'] ),
                'items'     => $items,
            ];
        }
        return $this->finalize_po_review_data( $data );
    }

    private function finalize_po_review_data( array $data ) {
        $errors = [];
        $po_numbers = array_keys( $data );
        $existing = $this->fetch_existing_po_numbers( $po_numbers );
        foreach ( $data as $po => &$entry ) {
            if ( isset( $existing[ $po ] ) ) {
                $entry['collision'] = true;
                $errors[] = sprintf( 'PO-nummer %s kolliderer med eksisterende PO.', $po );
            }
            foreach ( $entry['items'] as &$item ) {
                if ( $item['sku'] === '' ) {
                    $item['missing'] = true;
                    $errors[] = sprintf( 'SKU mangler for PO %s.', $po );
                    continue;
                }
                $product_id = wc_get_product_id_by_sku( $item['sku'] );
                if ( ! $product_id ) {
                    $item['missing'] = true;
                    $errors[] = sprintf( 'Fant ikke produkt for SKU %s i PO %s.', $item['sku'], $po );
                } else {
                    $item['product_id'] = $product_id;
                }
                if ( (int) $item['qty'] <= 0 ) {
                    $errors[] = sprintf( 'Ugyldig antall for SKU %s i PO %s.', $item['sku'], $po );
                }
            }
        }
        unset( $entry, $item );
        return [ 'data' => array_values( $data ), 'errors' => array_unique( $errors ) ];
    }

    private function import_purchase_orders( array $data ) {
        $errors = [];
        $imported = 0;
        $skipped = 0;
        $review = $data;

        foreach ( $review as &$entry ) {
            if ( ! empty( $entry['ignore'] ) ) {
                $skipped++;
                continue;
            }
            $po = sanitize_text_field( $entry['number'] ?? '' );
            if ( $po === '' ) {
                $errors[] = 'PO-nummer kan ikke være tomt.';
                continue;
            }
            if ( $this->po_number_exists( $po ) ) {
                $errors[] = sprintf( 'PO-nummer %s finnes allerede.', $po );
                continue;
            }

            $items = [];
            $has_error = false;
            foreach ( $entry['items'] as $item ) {
                if ( ! empty( $item['ignore'] ) ) {
                    continue;
                }
                $sku = sanitize_text_field( $item['sku'] ?? '' );
                $name = sanitize_text_field( $item['name'] ?? '' );
                $qty = (int) ( $item['qty'] ?? 0 );
                if ( $sku === '' ) {
                    $errors[] = sprintf( 'SKU mangler for PO %s.', $po );
                    $has_error = true;
                    continue;
                }
                if ( $qty <= 0 ) {
                    $errors[] = sprintf( 'Ugyldig antall for SKU %s i PO %s.', $sku, $po );
                    $has_error = true;
                    continue;
                }
                $product_id = wc_get_product_id_by_sku( $sku );
                if ( ! $product_id ) {
                    $errors[] = sprintf( 'Fant ikke produkt for SKU %s i PO %s.', $sku, $po );
                    $has_error = true;
                    continue;
                }
                $items[] = [
                    'product_id' => $product_id,
                    'sku'        => $sku,
                    'name'       => $name,
                    'qty'        => $qty,
                ];
            }

            if ( $has_error || ! $items ) {
                $errors[] = sprintf( 'PO %s ble ikke importert.', $po );
                continue;
            }

            $post_id = wp_insert_post( [
                'post_type'   => 'lp_purchase_order',
                'post_title'  => 'PO ' . $po,
                'post_status' => 'publish',
            ] );
            if ( is_wp_error( $post_id ) ) {
                $errors[] = sprintf( 'Kunne ikke opprette PO %s.', $po );
                continue;
            }
            update_post_meta( $post_id, '_lp_po_number', $po );
            update_post_meta( $post_id, '_lp_po_items', $items );
            $imported++;
        }
        unset( $entry );

        return [
            'errors'   => array_unique( $errors ),
            'imported' => $imported,
            'skipped'  => $skipped,
            'review'   => $review,
        ];
    }

    private function normalize_po_submission( array $input ) {
        $output = [];
        foreach ( $input as $entry ) {
            $number = sanitize_text_field( $entry['number'] ?? '' );
            $items = [];
            foreach ( $entry['items'] ?? [] as $item ) {
                $items[] = [
                    'sku'    => sanitize_text_field( $item['sku'] ?? '' ),
                    'name'   => sanitize_text_field( $item['name'] ?? '' ),
                    'qty'    => (int) ( $item['qty'] ?? 0 ),
                    'ignore' => ! empty( $item['ignore'] ),
                ];
            }
            $output[] = [
                'number' => $number,
                'ignore' => ! empty( $entry['ignore'] ),
                'items'  => $items,
            ];
        }
        return $output;
    }

    private function fetch_existing_po_numbers( array $po_numbers ) {
        $existing = [];
        if ( ! $po_numbers ) {
            return $existing;
        }
        $posts = get_posts( [
            'post_type'      => 'lp_purchase_order',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'meta_query'     => [
                [
                    'key'     => '_lp_po_number',
                    'value'   => $po_numbers,
                    'compare' => 'IN',
                ],
            ],
            'fields' => 'ids',
        ] );
        foreach ( $posts as $post_id ) {
            $po = get_post_meta( $post_id, '_lp_po_number', true );
            if ( $po !== '' ) {
                $existing[ (string) $po ] = (int) $post_id;
            }
        }
        return $existing;
    }

    private function po_number_exists( $po_number ) {
        $po_number = sanitize_text_field( $po_number );
        if ( $po_number === '' ) {
            return false;
        }
        $query = new WP_Query( [
            'post_type'      => 'lp_purchase_order',
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'meta_query'     => [
                [
                    'key'     => '_lp_po_number',
                    'value'   => $po_number,
                    'compare' => '=',
                ],
            ],
            'fields' => 'ids',
        ] );
        return ! empty( $query->posts );
    }

    private function map_po_headers( array $header ) {
        $map = [];
        foreach ( $header as $index => $name ) {
            $key = strtolower( trim( (string) $name ) );
            if ( $key === 'po number' || $key === 'po' || $key === 'purchase order' || $key === 'purchase order number' ) {
                $map['po'] = $index;
            }
            if ( $key === 'sku' ) {
                $map['sku'] = $index;
            }
            if ( $key === 'product name' || $key === 'product' ) {
                $map['product_name'] = $index;
            }
            if ( $key === 'quantity' || $key === 'qty' || $key === 'antall' ) {
                $map['quantity'] = $index;
            }
        }
        return $map;
    }

    private function render_po_review_form( array $review ) {
        echo '<form method="post" style="margin-top:24px">';
        wp_nonce_field( 'lp_po_import', 'lp_po_nonce' );
        echo '<h2>Gjennomgå import</h2>';
        echo '<p>Røde rader mangler produkter. Rediger SKU eller ignorer linjen.</p>';
        foreach ( $review as $po_index => $entry ) {
            $po_number = $entry['number'];
            $collision = ! empty( $entry['collision'] );
            $ignore    = ! empty( $entry['ignore'] );
            echo '<div style="border:1px solid #ccd0d4; padding:12px; margin-bottom:16px; background:#fff">';
            echo '<h3 style="margin-top:0">PO ' . esc_html( $po_number ) . '</h3>';
            if ( $collision ) {
                echo '<p style="color:#b91c1c; font-weight:600">PO-nummer kolliderer med eksisterende PO. Rediger eller ignorer hele PO-en.</p>';
            }
            echo '<label>PO-nummer <input type="text" name="po[' . $po_index . '][number]" value="' . esc_attr( $po_number ) . '" style="width:220px"></label> ';
            echo '<label><input type="checkbox" name="po[' . $po_index . '][ignore]" value="1" ' . checked( $ignore, true, false ) . '> Ignorer PO</label>';
            echo '<table class="widefat striped" style="margin-top:12px"><thead><tr><th>SKU</th><th>Produktnavn</th><th>Antall</th><th>Handling</th></tr></thead><tbody>';
            foreach ( $entry['items'] as $item_index => $item ) {
                $missing = ! empty( $item['missing'] );
                $rowStyle = $missing ? ' style="background:#fee2e2"' : '';
                echo '<tr' . $rowStyle . '>';
                echo '<td><input type="text" name="po[' . $po_index . '][items][' . $item_index . '][sku]" value="' . esc_attr( $item['sku'] ) . '" style="width:140px"></td>';
                echo '<td><input type="text" name="po[' . $po_index . '][items][' . $item_index . '][name]" value="' . esc_attr( $item['name'] ) . '" style="width:240px"></td>';
                echo '<td><input type="number" name="po[' . $po_index . '][items][' . $item_index . '][qty]" value="' . esc_attr( $item['qty'] ) . '" min="0" style="width:90px"></td>';
                echo '<td><label><input type="checkbox" name="po[' . $po_index . '][items][' . $item_index . '][ignore]" value="1" ' . checked( ! empty( $item['ignore'] ), true, false ) . '> Ignorer linje</label></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
        }
        echo '<button type="submit" class="button button-primary" name="lp_po_import" value="1">Importer</button>';
        echo '</form>';
    }
}

}
