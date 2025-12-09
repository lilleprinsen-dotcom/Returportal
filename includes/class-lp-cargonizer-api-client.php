<?php
/**
 * Cargonizer API client for LP Cargonizer Return Portal.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'LP_Cargonizer_API_Client' ) ) {

class LP_Cargonizer_API_Client {

    /** @var string */
    private $option_key_name;

    /** @var string */
    private $option_sender_name;

    /** @var string */
    private $endpoint_base;

    /** @var array */
    private $allowed_hosts;

    /** @var callable|null */
    private $logger;

    public function __construct( $option_key_name, $option_sender_name, $endpoint_base, $logger = null, ?array $allowed_hosts = null ) {
        $this->option_key_name   = $option_key_name;
        $this->option_sender_name = $option_sender_name;
        $this->endpoint_base     = rtrim( (string) $endpoint_base, '/' ) . '/';
        $this->logger            = is_callable( $logger ) ? $logger : null;
        $this->allowed_hosts     = $allowed_hosts ?: [ 'api.cargonizer.no', 'api.cargonizer.logistra.no', 'cargonizer.no', 'sandbox.cargonizer.no' ];
    }

    public function get_endpoint_base() {
        return $this->endpoint_base;
    }

    private function log( $message ) {
        if ( $this->logger ) {
            call_user_func( $this->logger, $message );
        }
    }

    public function require_api_credentials( $override_key = null, $override_sender = null ) {
        $key    = trim( (string) ( $override_key ?? ( defined( 'LP_CARGO_API_KEY' ) ? LP_CARGO_API_KEY : get_option( $this->option_key_name, '' ) ) ) );
        $sender = trim( (string) ( $override_sender ?? ( defined( 'LP_CARGO_SENDER_ID' ) ? LP_CARGO_SENDER_ID : get_option( $this->option_sender_name, '' ) ) ) );

        if ( $key === '' || $sender === '' ) {
            $msg_admin = __( 'Cargonizer API-nøkkel og Avsender-ID må fylles ut i innstillinger.', 'lp-cargo' );
            return current_user_can( 'manage_woocommerce' )
                ? new WP_Error( 'missing_credentials', $msg_admin )
                : new WP_Error( 'cargonizer_http', __( 'Klarte ikke å kontakte transportør. Prøv igjen senere.', 'lp-cargo' ) );
        }

        return [ 'key' => $key, 'sender' => $sender ];
    }

    public function api_headers( $accept = 'application/xml', $override_key = null, $override_sender = null ) {
        $key    = trim( (string) $override_key );
        $sender = trim( (string) $override_sender );
        $headers = [
            'X-Cargonizer-Key'    => $key,
            'X-Cargonizer-Sender' => $sender,
            'Accept'              => $accept,
            'User-Agent'          => 'LP-Cargonizer-Returns/' . LP_CARGO_VERSION . '; ' . home_url( '/' ),
        ];
        if ( $accept === 'application/xml' ) {
            $headers['Content-Type'] = 'application/xml; charset=utf-8';
        }
        return $headers;
    }

    public function http( $method, $path, $body = null, $query_args = [], $accept = 'application/xml' ) {
        $base = rtrim( $this->endpoint_base, '/' );
        $url  = ( preg_match( '#^https?://#i', $path ) ) ? $path : $base . '/' . ltrim( $path, '/' );
        if ( ! empty( $query_args ) ) {
            $qs  = http_build_query( $query_args, '', '&', PHP_QUERY_RFC3986 );
            $url .= ( strpos( $url, '?' ) === false ? '?' : '&' ) . $qs;
        }

        $host = parse_url( $url, PHP_URL_HOST );
        if ( $host && ! $this->is_allowed_host( $host ) ) {
            $msg = 'Blokkert utgående forespørsel til uautorisert vert: ' . $host;
            return current_user_can( 'manage_woocommerce' )
                ? new WP_Error( 'cargonizer_http_admin', $msg )
                : new WP_Error( 'cargonizer_http', 'Klarte ikke å kontakte transportør. Prøv igjen senere.' );
        }

        if ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL ) {
            $allowed      = defined( 'WP_ACCESSIBLE_HOSTS' ) ? WP_ACCESSIBLE_HOSTS : '';
            $allowed_list = array_filter( array_map( 'trim', explode( ',', strtolower( $allowed ) ) ) );
            if ( ! in_array( strtolower( $host ), $allowed_list, true ) ) {
                $msg = 'WP blokkerer utgående forespørsler. Legg api.cargonizer.no i WP_ACCESSIBLE_HOSTS i wp-config.php.';
                return current_user_can( 'manage_woocommerce' )
                    ? new WP_Error( 'cargonizer_http_admin', $msg )
                    : new WP_Error( 'cargonizer_http', 'Klarte ikke å kontakte transportør. Prøv igjen senere.' );
            }
        }

        $creds = $this->require_api_credentials();
        if ( is_wp_error( $creds ) ) {
            return $creds;
        }

        $args = [
            'method'      => $method,
            'headers'     => $this->api_headers( $accept, $creds['key'], $creds['sender'] ),
            'timeout'     => 45,
            'redirection' => 3,
            'body'        => ( $method === 'GET' ? null : $body ),
        ];

        $res = wp_remote_request( $url, $args );

        if ( is_wp_error( $res ) ) {
            $detail = $this->diagnose_http_error( 0, $res->get_error_message(), '' );
            $this->log( 'HTTP ERR: ' . $detail . ' → URL: ' . $url );
            return current_user_can( 'manage_woocommerce' )
                ? new WP_Error( 'cargonizer_http_admin', $detail )
                : new WP_Error( 'cargonizer_http', 'Klarte ikke å kontakte transportør. Prøv igjen senere.' );
        }

        $code = (int) wp_remote_retrieve_response_code( $res );
        $text = (string) wp_remote_retrieve_body( $res );

        if ( $code >= 200 && $code < 300 ) {
            return $text;
        }

        $detail = $this->diagnose_http_error( $code, '', $text );
        $this->log( 'HTTP ' . $code . ' – body: ' . substr( $text, 0, 600 ) . ' … URL: ' . $url );

        return current_user_can( 'manage_woocommerce' )
            ? new WP_Error( 'cargonizer_http_admin', $detail )
            : new WP_Error( 'cargonizer_http', 'Klarte ikke å kontakte transportør. Prøv igjen senere.' );
    }

    public function diagnose_http_error( $code, $wp_error_msg = '', $body = '' ) {
        if ( $code === 0 ) {
            $m = $wp_error_msg ?: 'Ukjent nettverksfeil';
            if ( stripos( $m, 'cURL error 60' ) !== false ) {
                return 'SSL/TLS-validering feilet på serveren (cURL #60). Oppdater CA/sertifikatkjede hos host.';
            }
            if ( stripos( $m, 'cURL error 28' ) !== false || stripos( $m, 'timed out' ) !== false ) {
                return 'Nettverkstimeout (cURL #28). Drift/Firewall eller nettverksstøy.';
            }
            if ( stripos( $m, 'cURL error 6' ) !== false || stripos( $m, 'Could not resolve host' ) !== false ) {
                return 'DNS-oppslag feilet (cURL #6). Sjekk serverens DNS.';
            }
            return 'Nettverksfeil: ' . $m;
        }

        $xml_msg = '';
        if ( ( $code === 400 || $code === 422 ) && $body ) {
            $old = libxml_use_internal_errors( true );
            $doc = @simplexml_load_string( $body );
            if ( $doc && isset( $doc->errors ) ) {
                $msgs = [];
                foreach ( $doc->errors->error as $e ) {
                    $msgs[] = trim( (string) $e );
                }
                if ( $msgs ) {
                    $xml_msg = ' Detaljer: ' . implode( ' | ', array_unique( $msgs ) );
                }
            }
            libxml_clear_errors();
            libxml_use_internal_errors( $old );
        }

        if ( $code === 401 ) {
            return 'Autentisering feilet (401). Sjekk API-nøkkel.';
        }
        if ( $code === 402 ) {
            return 'Autorisasjon feilet (402). Sannsynligvis mangler/feil Sender-ID.';
        }
        if ( $code === 403 ) {
            return 'Tilgang nektes (403). Sjekk lisens eller Sender-ID.';
        }
        if ( $code === 404 ) {
            return 'Endepunkt utilgjengelig (404).';
        }
        if ( $code === 422 ) {
            return 'Forespørselen ble avvist (422).' . $xml_msg;
        }
        if ( $code === 400 ) {
            return 'Valideringsfeil (400).' . $xml_msg;
        }
        if ( $code >= 500 ) {
            return 'Transportør svarte med feil (' . $code . ').';
        }
        return 'Ukjent HTTP-feil (' . $code . ').';
    }

    public function load_xml( $xml ) {
        $old = libxml_use_internal_errors( true );
        $doc = @simplexml_load_string( $xml );
        $errs = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors( $old );
        if ( ! $doc ) {
            $this->log( 'Cargonizer XML parse failed: ' . print_r( $errs, true ) );
            return new WP_Error( 'bad_xml', 'Kunne ikke tolke respons fra transportør.' );
        }
        return $doc;
    }

    public function is_allowed_host( $host ) {
        return $host && in_array( strtolower( $host ), array_map( 'strtolower', $this->allowed_hosts ), true );
    }

    public function looks_like_pdf( $bytes ) {
        return is_string( $bytes ) && substr( $bytes, 0, 4 ) === '%PDF';
    }
}

}
