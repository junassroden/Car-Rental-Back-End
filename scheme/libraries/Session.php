<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

class Session {

	/**
	 * Configuration array
	 *
	 * @var array
	 */
	private $config;

	/**
	 * IP match flag
	 *
	 * @var bool
	 */
	private $match_ip;

	/**
	 * Fingerprint match flag
	 *
	 * @var bool
	 */
	private $match_fingerprint;

	/**
	 * User data array
	 *
	 * @var array
	 */
	private $userdata;
	
	/**
	 * Number of allowed invalid attempts before locking
	 *
	 * @var integer
	 */
	private $max_invalid_attempts;
	
	/**
	 * Time window to track invalid attempts (in seconds)
	 *
	 * @var integer
	 */
	private $invalid_window;
	
	/**
	 * Lock duration after exceeding invalid attempts (in seconds)
	 *
	 * @var integer
	 */
	private $lock_duration_invalid;

	/**
	 * Maximum session creations allowed within a time window
	 *
	 * @var integer
	 */
	private $max_session_creations;
	
	/**
	 * Time window to track session creations (in seconds)
	 *
	 * @var integer
	 */
	private $creation_window;
	
	/**
	 * Lock duration after exceeding session creation limit (in seconds)
	 *
	 * @var integer
	 */
	private $lock_duration_creation;

	/**
	 * Path to the session security log file
	 *
	 * @var string
	 */
	private $security_file;


	/**
	 * Class constructor
	 *
	 * @throws RuntimeException
	 */
	public function __construct()
	{
		/**
		 * Load config
		 */
		$this->config = get_config();

		if ($this->config['sess_driver'] === 'database') {
			$handler = load_class('Database_session_handler', 'libraries/Session');
			session_set_save_handler($handler, true);
		}
		
		// Initialize from config
		$this->max_invalid_attempts   = $this->config['max_invalid_attempts']    ?? 5;
		$this->invalid_window         = $this->config['invalid_window']          ?? 600;
		$this->lock_duration_invalid  = $this->config['lock_duration_invalid']   ?? 900;
		$this->max_session_creations  = $this->config['max_session_creations']   ?? 10;
		$this->creation_window        = $this->config['creation_window']         ?? 60;
		$this->lock_duration_creation = $this->config['lock_duration_creation']  ?? 120;
		$this->security_file          = $this->config['security_file']           ?? (ROOT_DIR . 'runtime/session/session_security.json');

		// Setup session config values
		$this->match_ip          = $this->config['sess_match_ip'] ?? FALSE;
		$this->match_fingerprint = $this->config['sess_match_fingerprint'] ?? TRUE;

		// Setup cookie name
		if (!empty($this->config['cookie_prefix'])) {
			$this->config['cookie_name'] = $this->config['sess_cookie_name']
				? $this->config['cookie_prefix'] . $this->config['sess_cookie_name']
				: ini_get('session.name');
		} else {
			$this->config['cookie_name'] = $this->config['sess_cookie_name'] ?? ini_get('session.name');
		}

		ini_set('session.name', $this->config['cookie_name']);

		// Setup expiration times
		$this->config['sess_expiration'] = (int)($this->config['sess_expiration'] ?? ini_get('session.gc_maxlifetime'));
		ini_set('session.gc_maxlifetime', $this->config['sess_expiration']);

		$this->config['cookie_expiration'] = isset($this->config['cookie_expiration'])
			? (int)$this->config['cookie_expiration']
			: (($this->config['sess_expire_on_close'] ?? FALSE) ? 0 : (int)$this->config['sess_expiration']);

		session_set_cookie_params([
			'lifetime' => $this->config['cookie_expiration'],
			'path'     => $this->config['cookie_path'] ?? '/',
			'domain'   => $this->config['cookie_domain'] ?? '',
			'secure'   => $this->config['cookie_secure'] ?? FALSE,
			'httponly' => TRUE,
			'samesite' => $this->config['cookie_samesite'] ?? 'Lax'
		]);

		// Basic PHP session configuration
		ini_set('session.use_trans_sid', 0);
		ini_set('session.use_strict_mode', 1);
		ini_set('session.use_cookies', 1);
		ini_set('session.use_only_cookies', 1);
		ini_set('session.sid_length', $this->_get_sid_length());

		/**
		 * ----------------------------------------------------------------
		 * Initialize Session Security System
		 * ----------------------------------------------------------------
		 */
		$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		$fingerprint = $this->generate_fingerprint();

		$this->_security_init();
		$this->security_check_lock($ip, $fingerprint);

		/**
		 * ----------------------------------------------------------------
		 * Start session safely
		 * ----------------------------------------------------------------
		 */
		$existing_session = isset($_COOKIE[$this->config['cookie_name']]) && !empty($_COOKIE[$this->config['cookie_name']]);
		session_start();

		/**
		 * ----------------------------------------------------------------
		 * Track new session creations only
		 * ----------------------------------------------------------------
		 */
		if (!$existing_session && empty($_SESSION)) {
			$this->security_track_session_creation($ip, $fingerprint);
		}


		/**
		 * ----------------------------------------------------------------
		 * Validate Fingerprint & IP Binding
		 * ----------------------------------------------------------------
		 */
		if (empty($_SESSION['fingerprint'])) {
			$_SESSION['fingerprint'] = $fingerprint;
		} elseif ($this->match_fingerprint && $_SESSION['fingerprint'] !== $fingerprint) {
			$this->security_log_attempt($ip, $fingerprint, 'Fingerprint mismatch');
			session_destroy();
			throw new RuntimeException('Session terminated: Fingerprint mismatch detected.');
		}

		if (isset($_SESSION['ip_address']) && $this->match_ip && $_SESSION['ip_address'] !== $ip) {
			$this->security_log_attempt($ip, $fingerprint, 'IP mismatch');
			session_destroy();
			throw new RuntimeException('Session terminated: IP address mismatch detected.');
		}

		$_SESSION['ip_address'] = $ip;

		/**
		 * ----------------------------------------------------------------
		 * Session Regeneration Logic
		 * ----------------------------------------------------------------
		 */
		$regenerate_time = (int)($this->config['sess_time_to_update'] ?? 300);

		if (
			(empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
			strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest')
			&& $regenerate_time > 0
		) {
			if (!isset($_SESSION['last_session_regenerate'])) {
				$_SESSION['last_session_regenerate'] = time();
			} elseif ($_SESSION['last_session_regenerate'] < (time() - $regenerate_time)) {
				$this->sess_regenerate((bool)($this->config['sess_regenerate_destroy'] ?? FALSE));
			}
		}

		/**
		 * ----------------------------------------------------------------
		 * Refresh session cookie
		 * ----------------------------------------------------------------
		 */
		if (isset($_COOKIE[$this->config['cookie_name']]) &&
			$_COOKIE[$this->config['cookie_name']] === session_id()) {

			$expiration = empty($this->config['cookie_expiration'])
				? 0
				: time() + $this->config['cookie_expiration'];

			setcookie(
				$this->config['cookie_name'],
				session_id(),
				[
					'expires'  => $expiration,
					'path'     => $this->config['cookie_path'] ?? '/',
					'domain'   => $this->config['cookie_domain'] ?? '',
					'secure'   => $this->config['cookie_secure'] ?? FALSE,
					'httponly' => TRUE,
					'samesite' => $this->config['cookie_samesite'] ?? 'Lax'
				]
			);
		}

		/**
		 * Initialize Lava session variables
		 */
		$this->_lava_init_vars();
	}


	/**
	 * Validate session id (to mitigate session fixation attacks)
	 *
	 * @param string $id
	 * @return bool
	 */
	public function is_valid_sid($id)
	{
		// PHP default sid chars are alnum and usually length between 22 and 128 depending on config
		return is_string($id) && preg_match('/^[a-zA-Z0-9,-]{22,128}$/', $id);
	}

	/**
	 * Generates key as protection against Session Hijacking & Fixation.
	 * 
	 * @return string
	 */
	public function generate_fingerprint()
	{
		$key = array();
		foreach(array('ACCEPT_CHARSET', 'ACCEPT_ENCODING', 'ACCEPT_LANGUAGE', 'USER_AGENT') as $name) {
			$key[] = empty($_SERVER['HTTP_'. $name]) ? NULL : $_SERVER['HTTP_'. $name];
		}
		return md5(implode("\0", $key));
	}

	/**
	 * Internal method that initializes "flash" variables and
	 * populates $this->userdata with all session data except flash vars.
	 */
	protected function _lava_init_vars()
	{
		if (!empty($_SESSION['__lava_vars'])) {
			$current_time = time();
			foreach ($_SESSION['__lava_vars'] as $key => &$value) {
				if ($value === 'new') {
					$_SESSION['__lava_vars'][$key] = 'old';
				} elseif ($value === 'old' || (is_int($value) && $value < $current_time)) {
					unset($_SESSION[$key], $_SESSION['__lava_vars'][$key]);
				}
			}
			if (empty($_SESSION['__lava_vars'])) {
				unset($_SESSION['__lava_vars']);
			}
		}
		$this->userdata = $_SESSION;
	}

	/**
	 * Get the session ID length
	 *
	 * @return int
	 */
	public function _get_sid_length()
	{
		$bits_per_character = (int) ini_get('session.sid_bits_per_character');
		$sid_length = (int) ini_get('session.sid_length');
		$bits = $sid_length * $bits_per_character;
		if ($bits < 160) {
			$sid_length += (int) ceil((160 - $bits) / $bits_per_character);
		}
		return $sid_length;
	}

	/**
	 * Regenerate session id
	 *
	 * @param bool $destroy
	 */
	public function sess_regenerate($destroy = FALSE)
	{
		$_SESSION['last_session_regenerate'] = time();
		session_regenerate_id((bool)$destroy);
	}

	/**
	 * Mark a session variable as flashdata
	 *
	 * @param string|array $key
	 * @return bool
	 */
	public function mark_as_flash($key)
	{
		if (is_array($key)) {
			for ($i = 0, $c = count($key); $i < $c; $i++) {
				if (! isset($_SESSION[$key[$i]])) {
					return FALSE;
				}
			}
			$new = array_fill_keys($key, 'new');
			$_SESSION['__lava_vars'] = isset($_SESSION['__lava_vars'])
				? array_merge($_SESSION['__lava_vars'], $new)
				: $new;
			return TRUE;
		}
		if (! isset($_SESSION[$key])) {
			return FALSE;
		}
		$_SESSION['__lava_vars'][$key] = 'new';
		return TRUE;
	}

	/**
	 * Keeps existing flashdata available to next request.
	 *
	 * @param string|array $key
	 */
	public function keep_flashdata($key)
	{
		$this->mark_as_flash($key);
	}

	/**
	 * Use native session_id, do not invent our own here
	 */
	public function session_id()
	{
		return session_id();
	}
	
	/**
	 * Check if session key exists
	 *
	 * @param string $key
	 * @return boolean
	 */
	public function has_userdata($key = null)
	{
		if(! is_null($key)) {
			return isset($_SESSION[$key]);
		}
		return FALSE;
	}

	/** Set session data
	 *
	 * @param string|array $keys
	 * @param mixed $value
	 */
	public function set_userdata($keys, $value = NULL)
	{
		if(is_array($keys)) {
			foreach($keys as $key => $val) {
				$_SESSION[$key] = $val;
			}
		} else {
			$_SESSION[$keys] = $value;
		}
	}

	/**
	 * Unset session data
	 *
	 * @param string|array $keys
	 */
	public function unset_userdata($keys)
	{
		if(is_array($keys)) {
			foreach ($keys as $key) {
				if($this->has_userdata($key)) {
					unset($_SESSION[$key]);
				}
			}
		} else {
			if($this->has_userdata($keys)) {
				unset($_SESSION[$keys]);
			}
		}
	}

	/**
	 * Fetch all flashdata keys
	 *
	 * @return array
	 */
	public function get_flash_keys()
	{
		if (! isset($_SESSION['__lava_vars'])) {
			return array();
		}
		$keys = array();
		foreach (array_keys($_SESSION['__lava_vars']) as $key) {
			if (!is_int($_SESSION['__lava_vars'][$key])) $keys[] = $key;
		}
		return $keys;
	}

	/**
	 * Unmark flashdata so it won't be deleted on next request
	 *
	 * @param string|array $key
	 */
	public function unmark_flash($key)
	{
		if (empty($_SESSION['__lava_vars'])) {
			return;
		}
		is_array($key) OR $key = array($key);
		foreach ($key as $k) {
			if (isset($_SESSION['__lava_vars'][$k]) && ! is_int($_SESSION['__lava_vars'][$k])) {
				unset($_SESSION['__lava_vars'][$k]);
			}
		}
		if (empty($_SESSION['__lava_vars'])) {
			unset($_SESSION['__lava_vars']);
		}
	}

	/**
	 * Fetch session data
	 *
	 * @param string|null $key
	 * @return mixed
	 */
	public function userdata($key = NULL)
	{
		if(isset($key)) {
			return isset($_SESSION[$key]) ? $_SESSION[$key] : NULL;
		} elseif (empty($_SESSION)) {
			return array();
		}
		$userdata = array();
		$_exclude = array_merge(array('__lava_vars'), $this->get_flash_keys());
		foreach (array_keys($_SESSION) as $key) {
			if (! in_array($key, $_exclude, TRUE)) {
				$userdata[$key] = $_SESSION[$key];
			}
		}
		return $userdata;
	}

	/**
	 * Destroy the current session
	 */	
	public function sess_destroy()
	{
		$_SESSION = array();

		$params = session_get_cookie_params();
		setcookie(
			session_name(),
			'',
			(time() - 42000),
			$params['path'] ?? '/',
			$params['domain'] ?? '',
			$params['secure'] ?? false,
			$params['httponly'] ?? true
		);

		session_destroy();
	}

	/**
	 * Fetch flashdata
	 *
	 * @param string|null $key
	 * @return mixed
	 */
	public function flashdata($key = NULL)
	{
		if (isset($key)) {
			return (isset($_SESSION['__lava_vars'], $_SESSION['__lava_vars'][$key], $_SESSION[$key]) && ! is_int($_SESSION['__lava_vars'][$key]))
				? $_SESSION[$key]
				: NULL;
		}
		$flashdata = array();
		if (! empty($_SESSION['__lava_vars'])) {
			foreach ($_SESSION['__lava_vars'] as $key => &$value) {
				if (!is_int($value) && isset($_SESSION[$key])) $flashdata[$key] = $_SESSION[$key];
			}
		}
		return $flashdata;
	}

	/**
	 * Set flashdata
	 *
	 * @param string|array $data
	 * @param mixed $value
	 */
	public function set_flashdata($data, $value = NULL)
	{
		$this->set_userdata($data, $value);
		$this->mark_as_flash(is_array($data) ? array_keys($data) : $data);
	}

	/**
	 * Initialize security storage and ensure directory exists
	 *
	 * @return void
	 */
	private function _security_init()
	{
		$dir = dirname($this->security_file);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		if (!file_exists($this->security_file)) {
			file_put_contents($this->security_file, json_encode([], JSON_PRETTY_PRINT));
		}
	}

	/**
	 * Load the security log file
	 *
	 * @return array
	 */
	private function _security_load()
	{
		$json = @file_get_contents($this->security_file);
		return $json ? (json_decode($json, true) ?: [] ) : [];
	}

	/**
	 * Save updates to the security log file
	 *
	 * @param array $data
	 * @return void
	 */
	private function _security_save(array $data)
	{
		file_put_contents($this->security_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * Check if the current IP or fingerprint is locked
	 *
	 * @param string $ip
	 * @param string $fingerprint
	 * @return string|null  Returns an error message if locked, otherwise null
	 */
	public function security_check_lock($ip, $fingerprint)
	{
		$data = $this->_security_load();
		$key = $ip . '_' . $fingerprint;

		if (isset($data[$key]['locked_until']) && time() < $data[$key]['locked_until']) {
			$remaining = $data[$key]['locked_until'] - time();
			return "Session locked. Try again in {$remaining} seconds.";
		}

		foreach ($data as $k => $entry) {
			if (isset($entry['locked_until']) && time() > $entry['locked_until']) {
				unset($data[$k]['locked_until']);
				$data[$k]['attempts'] = 0;
			}
		}

		$this->_security_save($data);
	}

	/**
	 * Log invalid session attempt (e.g., fingerprint/IP mismatch)
	 *
	 * @param string $ip
	 * @param string $fingerprint
	 * @param string $reason
	 * @return void
	 */
	public function security_log_attempt($ip, $fingerprint, $reason = 'Unknown reason')
	{
		$data = $this->_security_load();
		$key = $ip . '_' . $fingerprint;
		$time = time();

		if (!isset($data[$key]) || !is_array($data[$key])) {
			$data[$key] = [
				'attempts' => 0,
				'timestamps' => [],
			];
		}

		$data[$key]['attempts'] = $data[$key]['attempts'] ?? 0;
		$data[$key]['timestamps'] = $data[$key]['timestamps'] ?? [];

		$data[$key]['attempts']++;
		$data[$key]['timestamps'][] = $time;
		$data[$key]['timestamps'] = array_filter(
			$data[$key]['timestamps'],
			fn($t) => $t > $time - $this->invalid_window
		);

		if ($data[$key]['attempts'] >= $this->max_invalid_attempts) {
			$data[$key]['locked_until'] = $time + $this->lock_duration_invalid;
			error_log("[Session Security] {$ip} locked due to excessive invalid attempts ({$reason})");
		}

		$this->_security_save($data);
	}

	/**
	 * Track session creation rate and apply lock if too frequent
	 *
	 * @param string $ip
	 * @param string $fingerprint
	 * @return string|null  Returns an error message if locked, otherwise null
	 */
	public function security_track_session_creation($ip, $fingerprint)
	{
		$data = $this->_security_load();
		$key = 'creation_' . $ip . '_' . $fingerprint;
		$time = time();

		if (!isset($data[$key])) {
			$data[$key] = ['creations' => []];
		}

		$data[$key]['creations'][] = $time;
		$data[$key]['creations'] = array_filter(
			$data[$key]['creations'],
			fn($t) => $t > $time - $this->creation_window
		);

		if (count($data[$key]['creations']) > $this->max_session_creations) {
			$data[$key]['locked_until'] = $time + $this->lock_duration_creation;
			$this->_security_save($data);
			error_log("[Session Security] IP {$ip} locked for session creation flood.");
			return 'Too many session creations. Please wait before trying again.';
		}

		$this->_security_save($data);
	}

	/**
	 * Reset failed login attempts and unlock session for a given IP/fingerprint
	 *
	 * @param string|null $ip
	 * @param string|null $fingerprint
	 * @return void
	 */
	public function reset_attempts($ip = null, $fingerprint = null)
	{
		$ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
		$fingerprint = $fingerprint ?? $this->generate_fingerprint();
		$key = $ip . '_' . $fingerprint;

		$data = $this->_security_load();

		$data[$key] = [
			'attempts' => 0,
			'timestamps' => [],
		];

		$this->_security_save($data);
	}

	public function check_lock_status($ip = null, $fingerprint = null)
	{
		$ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
		$fingerprint = $fingerprint ?? $this->generate_fingerprint();
		$key = $ip . '_' . $fingerprint;

		$data = $this->_security_load();

		$attempts = $data[$key]['attempts'] ?? 0;
		$locked_until = $data[$key]['locked_until'] ?? 0;
		$remaining = max($locked_until - time(), 0);

		if ($remaining === 0 && isset($data[$key]['locked_until'])) {
			unset($data[$key]['locked_until']);
			$this->_security_save($data);
		}

		return [
			'locked' => $remaining > 0,
			'attempts' => $attempts,
			'max' => $this->max_invalid_attempts,
			'remaining' => $remaining
		];
	}

	/**
	 * Manually unlock a locked user (admin override)
	 *
	 * @param string|null $ip
	 * @param string|null $fingerprint
	 * @return bool True if unlocked, false if no lock was found
	 */
	public function unlock_attempts($ip = null, $fingerprint = null)
	{
		$ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
		$fingerprint = $fingerprint ?? $this->generate_fingerprint();
		$key = $ip . '_' . $fingerprint;

		$data = $this->_security_load();

		if (isset($data[$key])) {
			// Remove lock if it exists
			if (isset($data[$key]['locked_until'])) {
				unset($data[$key]['locked_until']);
			}

			// Reset attempts
			$data[$key]['attempts'] = 0;
			$data[$key]['timestamps'] = [];

			$this->_security_save($data);
			return true;
		}

		return false;
	}

	/**
	 * Unlock all users and reset all failed login attempts
	 *
	 * @return int Number of records unlocked
	 */
	public function unlock_all_attempts()
	{
		$data = $this->_security_load();
		$count = 0;

		foreach ($data as $key => &$info) {
			if (!is_array($info)) {
				continue;
			}

			$wasLocked = isset($info['locked_until']);
			if ($wasLocked || ($info['attempts'] ?? 0) > 0) {
				// Remove lock + reset attempts
				unset($info['locked_until']);
				$info['attempts'] = 0;
				$info['timestamps'] = [];
				$count++;
			}
		}

		$this->_security_save($data);

		return $count;
	}

}

?>