<?php
/**
 * Connexion OAuth2/OpenID Connect FFESSM.
 * Nécessite une application cliente enregistrée/validée par la FFESSM.
 * Les identifiants de licence ne sont jamais collectés par Intranap.
 */
class FfessmAuthController
{
    private function config($key, $default = '')
    {
        return trim((string)($_ENV[$key] ?? $default));
    }

    private function baseUrl()
    {
        return rtrim($this->config('FFESSM_IDENTITY_URL', 'https://identite.ffessm.fr'), '/');
    }

    private function redirectUri()
    {
        return $this->config('FFESSM_REDIRECT_URI');
    }

    private function requireConfig()
    {
        foreach (['FFESSM_CLIENT_ID', 'FFESSM_CLIENT_SECRET', 'FFESSM_REDIRECT_URI'] as $key) {
            if ($this->config($key) === '' || str_contains($this->config($key), 'A_CONFIGURER')) {
                http_response_code(503);
                exit('Connexion FFESSM non configurée : renseignez les paramètres OAuth fournis/validés par la FFESSM dans le fichier .env.');
            }
        }
    }

    public function login()
    {
        $this->requireConfig();
        $state = bin2hex(random_bytes(32));
        $_SESSION['ffessm_oauth_state'] = $state;
        $_SESSION['ffessm_oauth_started'] = time();

        // IdentityServer4 : endpoint d'autorisation standard. Le client_id et l'URI
        // doivent être enregistrés côté FFESSM ; les scopes doivent être autorisés.
        $params = [
            'client_id' => $this->config('FFESSM_CLIENT_ID'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => $this->config('FFESSM_SCOPES', 'openid profile api roles'),
            'state' => $state
        ];
        header('Location: ' . $this->baseUrl() . '/connect/authorize?' . http_build_query($params));
        exit;
    }

    public function callback()
    {
        $this->requireConfig();
        if (isset($_GET['error'])) {
            http_response_code(400);
            exit('Autorisation FFESSM refusée : ' . htmlspecialchars((string)$_GET['error'], ENT_QUOTES, 'UTF-8'));
        }

        $state = (string)($_GET['state'] ?? '');
        $expected = (string)($_SESSION['ffessm_oauth_state'] ?? '');
        $started = (int)($_SESSION['ffessm_oauth_started'] ?? 0);
        unset($_SESSION['ffessm_oauth_state'], $_SESSION['ffessm_oauth_started']);
        if ($state === '' || $expected === '' || !hash_equals($expected, $state) || $started < time() - 600) {
            http_response_code(400);
            exit('État OAuth invalide ou expiré. Relancez la connexion.');
        }

        $code = (string)($_GET['code'] ?? '');
        if ($code === '') {
            http_response_code(400);
            exit('Code d’autorisation absent dans le retour FFESSM.');
        }

        $post = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => $this->config('FFESSM_CLIENT_ID'),
            'client_secret' => $this->config('FFESSM_CLIENT_SECRET')
        ];
        $ch = curl_init($this->baseUrl() . '/connect/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($post),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded']
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            error_log('FFESSM OAuth token exchange transport error: ' . $curlError);
            http_response_code(502);
            exit('Impossible de joindre le service d’authentification FFESSM. Consultez les journaux serveur.');
        }
        $tokens = json_decode($response, true);
        if ($httpCode < 200 || $httpCode >= 300 || !is_array($tokens) || empty($tokens['access_token'])) {
            // Ne jamais écrire le corps complet : il peut contenir des données sensibles.
            error_log('FFESSM OAuth token exchange failed; HTTP ' . $httpCode . '; JSON error: ' . json_last_error_msg());
            http_response_code(502);
            exit('Échange du code OAuth refusé par FFESSM (HTTP ' . $httpCode . '). Vérifiez client_id, secret, URI de retour et scopes.');
        }

        session_regenerate_id(true);
        $_SESSION['ffessm_access_token'] = $tokens['access_token'];
        $_SESSION['ffessm_token_type'] = $tokens['token_type'] ?? 'Bearer';
        $_SESSION['ffessm_token_expires_at'] = time() + (int)($tokens['expires_in'] ?? 3600);
        if (!empty($tokens['refresh_token'])) {
            $_SESSION['ffessm_refresh_token'] = $tokens['refresh_token'];
        }

        header('Content-Type: text/plain; charset=utf-8');
        echo "Connexion FFESSM autorisée.\n\n";
        echo "Le jeton a été conservé en session PHP. Il ne prouve pas encore que l’API des performances est autorisée.\n";
        echo "L’endpoint de données et le scope exact doivent être confirmés par la FFESSM avant d’activer la synchronisation.\n";
        echo "Vous pouvez fermer cette page.";
        exit;
    }

    public function logout()
    {
        unset($_SESSION['ffessm_access_token'], $_SESSION['ffessm_token_type'], $_SESSION['ffessm_token_expires_at'], $_SESSION['ffessm_refresh_token']);
        header('Location: index.php');
        exit;
    }
}
