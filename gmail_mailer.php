<?php
/**
 * Gmail API mailer (OAuth2 refresh-token flow) — condiviso da ykan/mcp.php e
 * 3d/faro/lib/mailer.php. Invia tramite l'account ai.portale3d@gmail.com invece
 * di mail() di PHP, che sull'IP condiviso di OVH viene spesso segnalato come spam.
 *
 * Vive nella root dell'hosting (un livello sopra ogni cartella progetto) così
 * che ciascun progetto possa fare require_once con un path relativo breve.
 *
 * Setup del refresh token: ykan/gmail_auth.php (one-time, poi cancellato).
 * Chiavi lette dal chiamante (di solito dal .env di root):
 *   GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GMAIL_REFRESH_TOKEN, GMAIL_FROM
 */

declare(strict_types=1);

if (!function_exists('gmail_access_token')) {
    /** Scambia il refresh_token per un access_token di breve durata. Lancia RuntimeException su errore. */
    function gmail_access_token(string $clientId, string $clientSecret, string $refreshToken): string
    {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type'    => 'refresh_token',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new RuntimeException("Gmail token refresh failed (curl): $err");
        $data = json_decode($resp, true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new RuntimeException('Gmail token refresh failed: ' . ($data['error_description'] ?? $resp));
        }
        return (string) $data['access_token'];
    }
}

if (!function_exists('gmail_api')) {
    /** Chiamata generica all'API Gmail (JSON in/out) con Bearer auth. Lancia RuntimeException su errore. */
    function gmail_api(string $accessToken, string $method, string $url, ?array $body = null): array
    {
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) throw new RuntimeException("Gmail API call failed (curl): $err");
        $data = json_decode($resp, true);
        if ($code >= 400) throw new RuntimeException("Gmail API error ($code): " . ($data['error']['message'] ?? $resp));
        return is_array($data) ? $data : [];
    }
}

if (!function_exists('gmail_send_mail')) {
    /**
     * Invia una mail HTML via Gmail API. Ritorna [bool ok, string info] (info =
     * message id se ok, messaggio d'errore altrimenti) — non lancia mai
     * eccezioni, per essere un drop-in di faro_send_mail()/mcp_send_mail():
     *   [$ok, $info] = gmail_send_mail(...);
     */
    function gmail_send_mail(
        string $to,
        string $subject,
        string $html,
        string $fromHeader,
        string $clientId,
        string $clientSecret,
        string $refreshToken
    ): array {
        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            return [false, 'Gmail non configurato (client id/secret/refresh token mancanti).'];
        }
        try {
            $token = gmail_access_token($clientId, $clientSecret, $refreshToken);
            $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $raw  = 'From: ' . $fromHeader . "\r\n";
            $raw .= "To: $to\r\n";
            $raw .= "Subject: $subjectEnc\r\n";
            $raw .= "MIME-Version: 1.0\r\n";
            $raw .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
            $raw .= $html;
            $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
            $res = gmail_api($token, 'POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send', ['raw' => $encoded]);
            return [true, (string) ($res['id'] ?? '')];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }
}
