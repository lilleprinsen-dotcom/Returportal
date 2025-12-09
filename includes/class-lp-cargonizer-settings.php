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

 async function load(){
   box.innerHTML='Laster...';
   const html=await post('lp_cargo_fetch_agreements',{});
   box.innerHTML=html;

   // mark dirty on change
   box.addEventListener('change', function(){
     box.querySelectorAll('.lp-cargo-agree-status').forEach(function(s){
       s.textContent='Endringer ikke lagret';
     });
     dirty=true;
   });

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

   if(btn){ btn.addEventListener('click',function(ev){ev.preventDefault();load();}); }

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
  btn.addEventListener('click', function(ev){ ev.preventDefault(); load(); });
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', load);
  } else {
    load();
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
}

}
