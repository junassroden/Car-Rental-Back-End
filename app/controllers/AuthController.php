<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AuthController extends ApiController
{
    public function login()
    {
        $input = $this->input();
        $identity = trim((string) ($input['email'] ?? $input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        if ($identity === '' || $password === '') {
            $this->response->send_json(['success' => false, 'message' => 'Email/username and password are required.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $user = $this->userModel->findByIdentity($identity);
        if (!$user || (int) $user['is_active'] !== 1 || !password_verify($password, $user['password'])) {
            $this->response->send_json(['success' => false, 'message' => 'Invalid login credentials.'], 401, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        try {
            $this->call->library('api');
            $tokens = $this->api->issue_tokens([
                'id' => (int) $user['id'],
                'role' => (string) $user['role'],
            ]);
        } catch (Throwable $exception) {
            error_log('JWT token issuance failed: ' . $exception->getMessage());
            $this->response->send_json(['success' => false, 'message' => 'Authentication service is not configured correctly.'], 503, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $this->response->send_json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => $this->publicUser($user),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in' => $tokens['expires_in'],
            'token_type' => $tokens['token_type'],
        ], 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function register()
    {
        $input = $this->input();
        $fullName = is_string($input['full_name'] ?? null) ? trim($input['full_name']) : '';
        $email = is_string($input['email'] ?? null) ? strtolower(trim($input['email'])) : '';
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';

        if ($fullName === '' || strlen($fullName) > 150) {
            $this->response->send_json(['success' => false, 'message' => 'Full name is required and must be no longer than 150 characters.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $this->response->send_json(['success' => false, 'message' => 'Enter a valid email address.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
            $this->response->send_json(['success' => false, 'message' => 'Password must be between 8 and 72 characters.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        if ($this->userModel->emailExists($email)) {
            $this->response->send_json(['success' => false, 'message' => 'An account with that email address already exists.'], 409, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        try {
            $this->userModel->createCustomer($fullName, $email, $password);
        } catch (Exception $exception) {
            if ($exception->getCode() === '23000') {
                $this->response->send_json(['success' => false, 'message' => 'An account could not be created because the email address or username is already in use.'], 409, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            error_log('Account registration failed: ' . $exception->getMessage());
            $this->response->send_json(['success' => false, 'message' => 'The account could not be created. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $this->response->send_json(['success' => true, 'message' => 'Account created successfully.'], 201, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function refresh()
    {
        $input = $this->input();
        $refreshToken = $input['refresh_token'] ?? '';
        if (!is_string($refreshToken) || trim($refreshToken) === '') {
            $this->response->send_json(['success' => false, 'message' => 'A refresh token is required.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        try {
            $this->call->library('api');
            $this->api->refresh_access_token(trim($refreshToken));
        } catch (Throwable $exception) {
            error_log('JWT refresh failed: ' . $exception->getMessage());
            $this->response->send_json(['success' => false, 'message' => 'The refresh token could not be processed.'], 401, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
    }

    public function logout()
    {
        $input = [];
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $input = $this->input();
        }
        $refreshToken = $input['refresh_token'] ?? '';
        if (is_string($refreshToken) && trim($refreshToken) !== '') {
            try {
                $this->call->library('api');
                $this->api->revoke_refresh_token(trim($refreshToken));
            } catch (Throwable $exception) {
                error_log('JWT logout revocation failed: ' . $exception->getMessage());
            }
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
        $this->response->send_json(['success' => true, 'message' => 'Logged out successfully.'], 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function me()
    {
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Authenticated user retrieved.', 'data' => $this->authenticatedUser()], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
