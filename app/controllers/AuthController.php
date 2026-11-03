<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AuthController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->security_headers->apply_api();
    }

    public function login()
    {
        $input    = $this->request->is_json() ? $this->request->json() : $this->request->post();
        $identity = trim((string) ($input['email'] ?? $input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($identity === '' || $password === '') {
            $this->response->send_json_error('Email/username and password are required.', 422);
            return;
        }

        $user = $this->UserModel->find_by_identity($identity);
        if (!$user || (int) $user['is_active'] !== 1 || !password_verify($password, $user['password'])) {
            $this->response->send_json_error('Invalid login credentials.', 401);
            return;
        }

        try {
            $this->call->library('api');
            $tokens = $this->api->issue_tokens(['id' => (int) $user['id'], 'role' => (string) $user['role']]);
        } catch (Throwable $exception) {
            error_log('JWT token issuance failed: ' . $exception->getMessage());
            $this->response->send_json_error('Authentication service is not configured correctly.', 503);
            return;
        }

        $this->session->regenerate_on_login();
        $this->session->set_userdata('user_id', (int) $user['id']);

        // The session cookie authenticates the web app; the tokens serve JWT clients.
        $this->response->send_json([
            'success'       => true,
            'message'       => 'Login successful.',
            'data'          => [
                'id'        => (int) $user['id'],
                'username'  => $user['username'],
                'full_name' => $user['full_name'] ?? $user['username'],
                'email'     => $user['email'],
                'role'      => $user['role'],
            ],
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in'    => $tokens['expires_in'],
            'token_type'    => $tokens['token_type'],
        ]);
    }

    public function googleLogin()
    {
        $input = $this->request->is_json() ? $this->request->json() : $this->request->post();
        $credential = $input['credential'] ?? '';
        if (!is_string($credential) || trim($credential) === '') {
            $this->response->send_json_error('A Google identity credential is required.', 422);
            return;
        }

        if (trim((string) getenv('GOOGLE_CLIENT_ID')) === '' || !function_exists('curl_init')) {
            $this->response->send_json_error('Google sign-in is not configured on this server.', 503);
            return;
        }

        $claims = $this->verifyGoogleCredential(trim($credential));
        if (!$claims) {
            $this->response->send_json_error('Google sign-in could not be verified.', 401);
            return;
        }

        $email = strtolower(trim((string) $claims['email']));
        $googleSub = (string) $claims['sub'];
        $user = $this->UserModel->find_by_google_sub($googleSub);

        try {
            if (!$user) {
                $user = $this->UserModel->find_by_identity($email);
                if ($user && !empty($user['google_sub']) && !hash_equals((string) $user['google_sub'], $googleSub)) {
                    $this->response->send_json_error('That email is already linked to a different Google account.', 409);
                    return;
                }
                if ($user && $user['role'] === 'admin' && empty($user['google_sub'])) {
                    $this->response->send_json_error('Administrator accounts must be linked to Google by an administrator first.', 403);
                    return;
                }

                if (!$user) {
                    $name = trim((string) ($claims['name'] ?? ''));
                    if ($name === '') {
                        $name = $email;
                    }
                    $id = $this->UserModel->create_customer($name, $email, bin2hex(random_bytes(32)));
                    $user = $this->UserModel->find((int) $id);
                }

                if ($user && empty($user['google_sub'])) {
                    if (!$this->UserModel->update((int) $user['id'], ['google_sub' => $googleSub])) {
                        throw new RuntimeException('Google identity could not be linked to the account.');
                    }
                    $user = $this->UserModel->find((int) $user['id']);
                }
            }
        } catch (Throwable $exception) {
            error_log('Google account sign-in failed: ' . $exception->getMessage());
            $this->response->send_json_error('The Google account could not be signed in. Please try again.', 500);
            return;
        }

        if (!$user || (int) $user['is_active'] !== 1) {
            $this->response->send_json_error('This account is inactive or could not be found.', 403);
            return;
        }

        try {
            $this->call->library('api');
            $tokens = $this->api->issue_tokens(['id' => (int) $user['id'], 'role' => (string) $user['role']]);
        } catch (Throwable $exception) {
            error_log('Google JWT token issuance failed: ' . $exception->getMessage());
            $this->response->send_json_error('Authentication service is not configured correctly.', 503);
            return;
        }

        $this->session->regenerate_on_login();
        $this->session->set_userdata('user_id', (int) $user['id']);
        $this->response->send_json([
            'success'       => true,
            'message'       => 'Google sign-in successful.',
            'data'          => [
                'id'        => (int) $user['id'],
                'username'  => $user['username'],
                'full_name' => $user['full_name'] ?? $user['username'],
                'email'     => $user['email'],
                'role'      => $user['role'],
            ],
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in'    => $tokens['expires_in'],
            'token_type'    => $tokens['token_type'],
        ]);
    }

    private function verifyGoogleCredential($credential)
    {
        $clientId = trim((string) getenv('GOOGLE_CLIENT_ID'));
        if ($clientId === '' || !function_exists('curl_init')) {
            error_log('Google sign-in is unavailable: GOOGLE_CLIENT_ID or the PHP cURL extension is missing.');
            return null;
        }

        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($credential);
        $curl = curl_init($url);
        if ($curl === false) {
            error_log('Google credential verification request could not be initialized.');
            return null;
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false || $status !== 200) {
            if ($error !== '') {
                error_log('Google credential verification request failed: ' . $error);
            }
            return null;
        }

        $claims = json_decode($response, true);
        $issuerValid = is_array($claims)
            && in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true);
        $emailVerified = is_array($claims)
            && in_array($claims['email_verified'] ?? false, [true, 'true'], true);

        if (
            !$issuerValid
            || !$emailVerified
            || !isset($claims['aud'], $claims['sub'], $claims['email'], $claims['exp'])
            || !hash_equals($clientId, (string) $claims['aud'])
            || (int) $claims['exp'] <= time()
            || trim((string) $claims['sub']) === ''
            || !filter_var($claims['email'], FILTER_VALIDATE_EMAIL)
        ) {
            return null;
        }

        return $claims;
    }

    public function refresh()
    {
        $input        = $this->request->is_json() ? $this->request->json() : $this->request->post();
        $refreshToken = $input['refresh_token'] ?? '';
        if (!is_string($refreshToken) || trim($refreshToken) === '') {
            $this->response->send_json_error('A refresh token is required.', 422);
            return;
        }

        try {
            // Sends the new token pair itself.
            $this->call->library('api');
            $this->api->refresh_access_token(trim($refreshToken));
        } catch (Throwable $exception) {
            error_log('JWT refresh failed: ' . $exception->getMessage());
            $this->response->send_json_error('The refresh token could not be processed.', 401);
        }
    }

    public function register()
    {
        $input = $this->request->is_json() ? $this->request->json() : $this->request->post();

        // Form_validation reads $_POST, so a JSON body is placed there first.
        $_POST = is_array($input) ? $input : [];
        $this->call->library('form_validation');
        if (!$this->form_validation->validate([
            'full_name|Full name' => 'required|max_length[150]',
            'email|Email address' => 'required|valid_email|max_length[255]',
            'password|Password'   => 'required|min_length[8]|max_length[72]',
        ])) {
            $errors = $this->form_validation->get_errors();
            $this->response->send_json_error($errors[0], 422, ['errors' => $errors]);
            return;
        }

        $email = strtolower(trim($this->request->post('email')));
        if ($this->UserModel->email_exists($email)) {
            $this->response->send_json_error('An account with that email address already exists.', 409);
            return;
        }

        try {
            $this->UserModel->create_customer(trim($this->request->post('full_name')), $email, (string) $this->request->post('password'));
        } catch (Exception $exception) {
            error_log('Account registration failed: ' . $exception->getMessage());
            $this->response->send_json_error('The account could not be created. Please try again.', 500);
            return;
        }

        $this->response->send_json_success(null, 'Account created successfully.', 201);
    }

    public function logout()
    {
        $input        = $this->request->is_json() ? $this->request->json() : $this->request->post();
        $refreshToken = $input['refresh_token'] ?? '';

        if (is_string($refreshToken) && trim($refreshToken) !== '') {
            try {
                $this->call->library('api');
                $this->api->revoke_refresh_token(trim($refreshToken));
            } catch (Throwable $exception) {
                error_log('JWT logout revocation failed: ' . $exception->getMessage());
            }
        }

        $this->session->sess_destroy();
        $this->response->send_json_success(null, 'Logged out successfully.');
    }

    public function me()
    {
        $user = $this->UserModel->find((int) $this->session->userdata('user_id'));
        if (!$user || (int) $user['is_active'] !== 1) {
            $this->response->send_json_error('Authentication is required.', 401);
            return;
        }

        $this->response->send_json_success([
            'id'        => (int) $user['id'],
            'username'  => $user['username'],
            'full_name' => $user['full_name'] ?? $user['username'],
            'email'     => $user['email'],
            'role'      => $user['role'],
        ], 'Authenticated user retrieved.');
    }
}
