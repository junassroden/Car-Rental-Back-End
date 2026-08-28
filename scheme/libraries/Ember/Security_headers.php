<?php
class Security_headers
{
    public function __construct()
    {
        $this->set_csp();
    }

    private function set_csp()
    {
        $nonce = base64_encode(random_bytes(32));

        $_lava = lava_instance();
        $_lava->call->library('session');
        $_lava->session->set_userdata('csp_nonce', $nonce);

        if (!defined('CSP_NONCE')) {
            define('CSP_NONCE', $nonce);
        }

        // Split both scripts and styles into structural elements (-elem) and attribute handlers (-attr)
        $csp = "default-src 'self'; " .
               "script-src-elem 'self' 'nonce-{$nonce}'; " .
               "script-src-attr 'unsafe-inline'; " .
               "style-src-elem 'self' 'nonce-{$nonce}'; " .
               "style-src-attr 'unsafe-inline'; " .
               "img-src 'self' data:; " .
               "font-src 'self'; " .
               "object-src 'none'; " .
               "base-uri 'self'; " .
               "form-action 'self'; " .
               "frame-ancestors 'none'; " .
               "upgrade-insecure-requests;";

        header("Content-Security-Policy: " . $csp);
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: DENY");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
        header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
    }
}
