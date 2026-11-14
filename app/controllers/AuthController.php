<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AuthController extends Controller
{
    // Failed password sign-ins allowed per account, and per IP address, before a lockout.
    const ACCOUNT_FAILURE_LIMIT = 5;
    const IP_FAILURE_LIMIT = 20;
    // A lockout starts at 15 minutes and doubles each time it recurs, up to one hour.
    const LOCKOUT_SECONDS = 900;
    const MAX_LOCKOUT_SECONDS = 3600;

    public function __construct()
    {
        parent::__construct();
        $this->security_headers->apply_api();
        $this->call->library('cache');
    }

    public function login()
    {
        $input    = $this->requestPayload();
        $identity = strtolower(trim((string) ($input['email'] ?? $input['username'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        if ($identity === '' || $password === '') {
            $this->response->send_json_error('Email/username and password are required.', 422);
            return;
        }

        // Throttled by account and by IP address. The account key comes from what was typed,
        // so it behaves the same whether or not that account exists.
        $accountKey = 'login_fail_acct_' . hash('sha256', $identity);
        $ipKey = 'login_fail_ip_' . hash('sha256', (string) $this->request->server('REMOTE_ADDR'));
        $wait = max($this->lockoutRemaining($accountKey), $this->lockoutRemaining($ipKey));
        if ($wait > 0) {
            $this->sendTooManyAttempts($wait);
            return;
        }

        $user = $this->UserModel->find_by_identity($identity);
        if (!$user || (int) $user['is_active'] !== 1 || !password_verify($password, $user['password'])) {
            $this->recordFailure($accountKey, self::ACCOUNT_FAILURE_LIMIT);
            $this->recordFailure($ipKey, self::IP_FAILURE_LIMIT);
            $this->response->send_json_error('Invalid login credentials.', 401);
            return;
        }
        $this->cache->delete($accountKey);

        // Only someone who knows the password learns that the address is unverified.
        if (!UserModel::email_verified($user)) {
            $this->response->send_json_error(
                'Verify your email address before signing in. Check your inbox for the verification link, or send a new one.',
                403,
                ['code' => 'email_not_verified']
            );
            return;
        }

        $this->sendTokens($user, 'Login successful.');
    }

    /**
     * Signs in with a Google ID token. An account already linked to this Google identity signs
     * in, and a new email creates a verified customer account. An email that already belongs to
     * an unlinked account is never linked automatically: the owner signs in with their password
     * (or resets it) and links Google from their profile (POST /api/account/google/link).
     */
    public function googleLogin()
    {
        $this->call->library('api');
        $this->api->rate_limit('google_auth_' . (string) $this->request->server('REMOTE_ADDR'), 30, 900);

        $claims = $this->googleClaims($this->requestPayload()['credential'] ?? '');
        if (!$claims) {
            return;
        }
        $email = strtolower(trim((string) $claims['email']));
        $googleSub = (string) $claims['sub'];

        try {
            $user = $this->UserModel->find_by_google_sub($googleSub);
            if (!$user) {
                if ($this->UserModel->find_by_email($email)) {
                    $this->response->send_json_error(
                        'An account with this email already exists. Sign in with your password, then link Google from your profile. If you do not know the password, use "Forgot password" to reset it.',
                        409,
                        ['code' => 'google_link_required']
                    );
                    return;
                }

                $name = trim((string) ($claims['name'] ?? ''));
                // Google confirmed the address. The stored password is random and unusable;
                // the owner can choose one later through the password reset flow.
                $id = $this->UserModel->create_customer($name !== '' ? $name : $email, $email, bin2hex(random_bytes(32)), true, false);
                if (!$id || !$this->UserModel->update((int) $id, ['google_sub' => $googleSub])) {
                    throw new RuntimeException('The Google account could not be created.');
                }
                $user = $this->UserModel->find((int) $id);
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
        // An address Google has verified is proven, even on an account that predates verification.
        if (!UserModel::email_verified($user) && strtolower((string) $user['email']) === $email) {
            $this->UserModel->mark_email_verified((int) $user['id']);
        }

        $this->sendTokens($user, 'Google sign-in successful.');
    }

    /** Links Google to the signed-in account. Only the authenticated owner can do this. */
    public function linkGoogle()
    {
        $user = $this->requireUser();
        $claims = $this->googleClaims($this->requestPayload()['credential'] ?? '');
        if (!$claims) {
            return;
        }
        $googleSub = (string) $claims['sub'];

        if (!empty($user['google_sub'])) {
            $this->response->send_json_error('A Google account is already linked. Unlink it first to link a different one.', 409);
            return;
        }
        $owner = $this->UserModel->find_by_google_sub($googleSub);
        if ($owner && (int) $owner['id'] !== (int) $user['id']) {
            $this->response->send_json_error('That Google account is already linked to another account.', 409);
            return;
        }
        if (!$this->UserModel->update((int) $user['id'], ['google_sub' => $googleSub])) {
            $this->response->send_json_error('Google could not be linked. Please try again.', 500);
            return;
        }
        $this->response->send_json_success(['google_linked' => true], 'Google account linked.');
    }

    /** Unlinks Google. Requires the account password, so the owner cannot lock themselves out. */
    public function unlinkGoogle()
    {
        $user = $this->requireUser();
        if (empty($user['google_sub'])) {
            $this->response->send_json_error('No Google account is linked.', 409);
            return;
        }
        if ((int) ($user['has_password'] ?? 1) !== 1) {
            $this->response->send_json_error('Set a password first (use "Forgot password"), so you can still sign in after unlinking Google.', 409);
            return;
        }
        $password = (string) ($this->requestPayload()['password'] ?? '');
        if ($password === '' || !password_verify($password, $user['password'])) {
            $this->response->send_json_error('Enter your current password to unlink Google.', 403);
            return;
        }
        if (!$this->UserModel->update((int) $user['id'], ['google_sub' => null])) {
            $this->response->send_json_error('Google could not be unlinked. Please try again.', 500);
            return;
        }
        $this->response->send_json_success(['google_linked' => false], 'Google account unlinked.');
    }

    /** Issues a token pair for the user and sends the login response. */
    private function sendTokens(array $user, $message)
    {
        try {
            $this->call->library('api');
            $tokens = $this->api->issue_tokens(['id' => (int) $user['id'], 'role' => (string) $user['role']]);
        } catch (Throwable $exception) {
            error_log('JWT token issuance failed: ' . $exception->getMessage());
            $this->response->send_json_error('Authentication service is not configured correctly.', 503);
            return;
        }

        $this->response->send_json([
            'success'       => true,
            'message'       => $message,
            'data'          => $this->userData($user),
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in'    => $tokens['expires_in'],
            'token_type'    => $tokens['token_type'],
        ]);
    }

    private function userData(array $user)
    {
        return [
            'id'        => (int) $user['id'],
            'username'  => $user['username'],
            'full_name' => $user['full_name'] ?? $user['username'],
            'email'     => $user['email'],
            'role'      => $user['role'],
        ];
    }

    /** Verified Google claims for a credential, or NULL after sending the error response. */
    private function googleClaims($credential)
    {
        if (!is_string($credential) || trim($credential) === '') {
            $this->response->send_json_error('A Google identity credential is required.', 422);
            return null;
        }
        if (trim((string) getenv('GOOGLE_CLIENT_ID')) === '' || !function_exists('curl_init')) {
            $this->response->send_json_error('Google sign-in is not configured on this server.', 503);
            return null;
        }
        $claims = $this->verifyGoogleCredential(trim($credential));
        if (!$claims) {
            $this->response->send_json_error('Google sign-in could not be verified.', 401);
            return null;
        }
        return $claims;
    }

    /** The verified claims of a Google ID token, or NULL. googleClaims() has already checked the configuration. */
    private function verifyGoogleCredential($credential)
    {
        $clientId = trim((string) getenv('GOOGLE_CLIENT_ID'));

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
        $this->call->library('api');
        $this->api->rate_limit('refresh_' . (string) $this->request->server('REMOTE_ADDR'), 120, 60);

        $input        = $this->requestPayload();
        $refreshToken = $input['refresh_token'] ?? '';
        if (!is_string($refreshToken) || trim($refreshToken) === '') {
            $this->response->send_json_error('A refresh token is required.', 422);
            return;
        }

        try {
            // Sends the new token pair itself.
            $this->api->refresh_access_token(trim($refreshToken));
        } catch (Throwable $exception) {
            error_log('JWT refresh failed: ' . $exception->getMessage());
            $this->response->send_json_error('The refresh token could not be processed.', 401);
        }
    }

    public function register()
    {
        $this->call->library('api');
        $this->api->rate_limit('register_' . (string) $this->request->server('REMOTE_ADDR'), 10, 3600);

        $this->validateInput($this->requestPayload(), [
            'full_name|Full name' => 'required|max_length[150]',
            'email|Email address' => 'required|valid_email|max_length[255]',
            'password|Password'   => 'required|min_length[8]|max_length[72]',
        ]);

        $email = strtolower(trim($this->request->post('email')));
        if ($this->UserModel->email_exists($email)) {
            $this->response->send_json_error('An account with that email address already exists.', 409);
            return;
        }

        try {
            $id = $this->UserModel->create_customer(trim($this->request->post('full_name')), $email, (string) $this->request->post('password'));
        } catch (Exception $exception) {
            error_log('Account registration failed: ' . $exception->getMessage());
            $this->response->send_json_error('The account could not be created. Please try again.', 500);
            return;
        }

        $this->sendVerificationEmail($this->UserModel->find((int) $id));
        $this->response->send_json_success(null, 'Account created. We sent a verification link to your email address. Verify it, then sign in.', 201);
    }

    /** Confirms an email address with the token from the verification link. */
    public function verifyEmail()
    {
        $this->call->library('api');
        $this->api->rate_limit('verify_email_' . (string) $this->request->server('REMOTE_ADDR'), 30, 900);

        $token = $this->requestPayload()['token'] ?? '';
        $this->call->model('AccountTokenModel');
        if (!$this->db->transaction()) {
            $this->response->send_json_error('The email address could not be verified. Please try again.', 500);
            return;
        }
        try {
            $userId = $this->AccountTokenModel->consume($token, 'verify_email');
            if ($userId === null) {
                $this->db->roll_back();
                $this->response->send_json_error('This verification link is invalid, expired, or already used. Request a new one from the sign-in page.', 410);
                return;
            }
            $this->UserModel->mark_email_verified($userId);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->roll_back();
            error_log('Email verification failed: ' . $exception->getMessage());
            $this->response->send_json_error('The email address could not be verified. Please try again.', 500);
            return;
        }
        $this->response->send_json_success(null, 'Your email address is verified. You can now sign in.');
    }

    /** Sends a new verification link. The response is the same whether or not the address exists. */
    public function resendVerification()
    {
        $this->call->library('api');
        $this->api->rate_limit('resend_verify_ip_' . (string) $this->request->server('REMOTE_ADDR'), 10, 3600);

        $email = strtolower(trim((string) ($this->requestPayload()['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->api->rate_limit('resend_verify_acct_' . hash('sha256', $email), 3, 900);
            $user = $this->UserModel->find_by_email($email);
            if ($user && (int) $user['is_active'] === 1 && !UserModel::email_verified($user)) {
                $this->sendVerificationEmail($user);
            }
        }
        $this->response->send_json_success(null, 'If that address belongs to an unverified account, a new verification link is on its way.');
    }

    /** Starts a password reset. The response is the same whether or not the address exists. */
    public function forgotPassword()
    {
        $this->call->library('api');
        $this->api->rate_limit('forgot_password_ip_' . (string) $this->request->server('REMOTE_ADDR'), 10, 3600);

        $email = strtolower(trim((string) ($this->requestPayload()['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->api->rate_limit('forgot_password_acct_' . hash('sha256', $email), 3, 900);
            $user = $this->UserModel->find_by_email($email);
            if ($user && (int) $user['is_active'] === 1) {
                $this->call->model('AccountTokenModel');
                $token = $this->AccountTokenModel->issue((int) $user['id'], 'password_reset');
                $this->sendAccountEmail(
                    $user,
                    'Reset your password',
                    "We received a request to reset the password for your account.\n\n"
                    . "Open this link within 1 hour to choose a new password:\n"
                    . $this->frontendUrl('/auth/reset-password?token=' . $token) . "\n\n"
                    . 'If you did not ask for this, ignore this email. Your password stays the same.',
                    'password reset'
                );
            }
        }
        $this->response->send_json_success(null, 'If that address belongs to an account, a password reset link is on its way.');
    }

    /**
     * Sets a new password with a reset token. Using the link proves the owner controls the
     * address, so it becomes verified, and every existing session is signed out.
     */
    public function resetPassword()
    {
        $this->call->library('api');
        $this->api->rate_limit('reset_password_' . (string) $this->request->server('REMOTE_ADDR'), 20, 900);

        $input = $this->requestPayload();
        $password = (string) ($input['password'] ?? '');
        if (strlen($password) < 8 || strlen($password) > 72) {
            $this->response->send_json_error('The password must be 8 to 72 characters long.', 422);
            return;
        }

        $this->call->model('AccountTokenModel');
        if (!$this->db->transaction()) {
            $this->response->send_json_error('The password could not be reset. Please try again.', 500);
            return;
        }
        try {
            $userId = $this->AccountTokenModel->consume($input['token'] ?? '', 'password_reset');
            if ($userId === null) {
                $this->db->roll_back();
                $this->response->send_json_error('This reset link is invalid, expired, or already used. Request a new one.', 410);
                return;
            }
            $this->UserModel->set_password($userId, $password);
            $this->UserModel->mark_email_verified($userId);
            $this->UserModel->revoke_sessions($userId);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->roll_back();
            error_log('Password reset failed: ' . $exception->getMessage());
            $this->response->send_json_error('The password could not be reset. Please try again.', 500);
            return;
        }
        $this->response->send_json_success(null, 'Your password has been changed. Sign in with the new password.');
    }

    public function logout()
    {
        $input        = $this->requestPayload();
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
        $user = $this->UserModel->find_active($this->authenticated_user_id());
        if (!$user) {
            $this->response->send_json_error('Authentication is required.', 401);
            return;
        }

        $this->response->send_json_success($this->userData($user) + [
            'google_linked' => !empty($user['google_sub']),
            'has_password'  => (int) ($user['has_password'] ?? 1) === 1,
        ], 'Authenticated user retrieved.');
    }

    /** Returns the signed-in active user, or sends a 401. */
    private function requireUser()
    {
        $user = $this->UserModel->find_active($this->authenticated_user_id());
        if (!$user) {
            $this->response->send_json_error('Authentication is required.', 401);
            exit;
        }
        return $user;
    }

    /** Seconds left on a lockout for this throttle key, or 0. */
    private function lockoutRemaining($key)
    {
        $state = $this->cache->get($key);
        return is_array($state) ? max(0, (int) ($state['locked_until'] ?? 0) - time()) : 0;
    }

    /**
     * Counts a failed sign-in. Reaching the limit starts a lockout that doubles each time it
     * recurs (15, 30, then 60 minutes). The record is forgotten a day after the last failure.
     */
    private function recordFailure($key, $limit)
    {
        $state = $this->cache->get($key);
        $state = is_array($state) ? $state : ['count' => 0, 'locks' => 0, 'locked_until' => 0];
        $state['count']++;
        if ($state['count'] >= $limit) {
            $state['locked_until'] = time() + min(self::MAX_LOCKOUT_SECONDS, self::LOCKOUT_SECONDS * (2 ** $state['locks']));
            $state['locks']++;
            $state['count'] = 0;
        }
        $this->cache->write($state, $key, 86400);
    }

    private function sendTooManyAttempts($seconds)
    {
        $minutes = max(1, (int) ceil($seconds / 60));
        $this->response->add_header('Retry-After', (string) $seconds);
        $this->response->send_json_error(
            "Too many sign-in attempts. Please wait {$minutes} minute(s) and try again, or reset your password.",
            429,
            ['code' => 'too_many_attempts', 'retry_after' => $seconds]
        );
    }

    private function sendVerificationEmail($user)
    {
        if (!$user) {
            return;
        }
        $this->call->model('AccountTokenModel');
        $token = $this->AccountTokenModel->issue((int) $user['id'], 'verify_email');
        $this->sendAccountEmail(
            $user,
            'Verify your email address',
            "Welcome! Confirm that this is your email address by opening this link within 24 hours:\n"
            . $this->frontendUrl('/auth/verify-email?token=' . $token) . "\n\n"
            . 'If you did not create an account, ignore this email.',
            'verification'
        );
    }

    /**
     * Sends an account email with LavaLust's Email library (PHP mail()). A failure is logged and
     * never blocks the request. In development only, the message (with its link) is written to
     * the PHP error log so the flow can be finished without a mail server; production never logs tokens.
     */
    private function sendAccountEmail(array $user, $subject, $body, $kind)
    {
        $from = trim((string) getenv('MAIL_FROM_ADDRESS'));
        $sent = false;
        try {
            if ($from === '') {
                throw new RuntimeException('MAIL_FROM_ADDRESS is not set.');
            }
            $this->call->library('email');
            $this->email->reset();
            $sent = $this->email
                ->sender($from, getenv('MAIL_FROM_NAME') ?: 'DriveRent')
                ->recipient($user['email'])
                ->subject($subject)
                ->email_content($body, 'plain')
                ->send();
        } catch (Throwable $exception) {
            error_log('Account ' . $kind . ' email could not be sent to user ' . (int) $user['id'] . ': ' . $exception->getMessage());
        }
        if (!$sent) {
            error_log('Account ' . $kind . ' email was not delivered to user ' . (int) $user['id'] . '.');
            if (config_item('environment') === 'development') {
                error_log('DEVELOPMENT ONLY, ' . $kind . ' email for user ' . (int) $user['id'] . ":\n" . $body);
            }
        }
    }

    private function frontendUrl($path)
    {
        return rtrim(getenv('FRONTEND_URL') ?: 'http://127.0.0.1:5173', '/') . $path;
    }

    /** The submitted form fields, or else the JSON body. Always an array. */
    private function requestPayload()
    {
        $form = $this->request->post();
        if ($form !== []) {
            return $form;
        }

        $json = $this->request->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Runs Form_validation on $input, or sends a 422. Form_validation reads $_POST, so the
     * values are placed there first, as strings, with '' for any field a rule names that
     * was not sent: its strlen() rejects NULL and its `required` rule treats the number 0 as empty.
     */
    private function validateInput(array $input, array $rules)
    {
        $fields = array_map(static function ($field) {
            return strtok($field, '|');
        }, array_keys($rules));

        $_POST = array_map(static function ($value) {
            return is_scalar($value) || $value === null ? (string) $value : $value;
        }, $input) + array_fill_keys($fields, '');

        $this->call->library('form_validation');
        if (!$this->form_validation->validate($rules)) {
            $errors = $this->form_validation->get_errors();
            $this->response->send_json_error($errors[0], 422, ['errors' => $errors]);
            exit;
        }
    }
}
