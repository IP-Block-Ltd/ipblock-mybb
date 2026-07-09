<?php
/**
 * IP Block Protection - a MyBB plugin.
 *
 * Screens every front-end request against the ip-block.com IP-screening
 * service before the page is rendered.
 *
 * @package   ipblock
 * @copyright 2026 ip-block.com
 * @license   GNU General Public License v2.0 only
 * @link      https://www.ip-block.com
 */

// Disallow direct access to this file for security reasons.
if (!defined('IN_MYBB')) {
	die('Direct initialization of this file is not allowed.');
}

/*
 * Hook registration.
 *
 * `global_start` is triggered very early in global.php, which is included by
 * every front-end script. The Admin CP (admin/) does NOT include global.php,
 * so hooking here automatically leaves the control panel untouched - the
 * operator can never be locked out.
 */
$plugins->add_hook('global_start', 'ipblock_run');

/**
 * Plugin metadata.
 *
 * @return array
 */
function ipblock_info()
{
	return array(
		'name'          => 'IP Block Protection',
		'description'   => 'Screens front-end visitors against the ip-block.com IP-screening service.',
		'website'       => 'https://www.ip-block.com',
		'author'        => 'ip-block.com',
		'authorsite'    => 'https://www.ip-block.com',
		'version'       => '1.0.0',
		'guid'          => '',
		'codename'      => 'ipblock',
		'compatibility' => '18*',
	);
}

/**
 * Whether the plugin is installed (its settinggroup exists).
 *
 * @return bool
 */
function ipblock_is_installed()
{
	global $db;

	$query = $db->simple_select('settinggroups', 'gid', "name='ipblock'");

	return (bool) $db->num_rows($query);
}

/**
 * Install: create the settings group and all settings.
 *
 * @return void
 */
function ipblock_install()
{
	global $db;

	// Settings group.
	$gid = $db->insert_query('settinggroups', array(
		'name'        => 'ipblock',
		'title'       => $db->escape_string('IP Block'),
		'description' => $db->escape_string('Settings for the ip-block.com IP-screening service.'),
		'disporder'   => 100,
		'isdefault'   => 0,
	));

	$settings = array(
		'ipblock_enabled' => array(
			'title'       => 'Enable IP screening',
			'description' => 'Screen every front-end request against ip-block.com. The Admin CP is never screened.',
			'optionscode' => 'yesno',
			'value'       => '0',
		),
		'ipblock_site_id' => array(
			'title'       => 'Site ID',
			'description' => 'Your ip-block.com site identifier.',
			'optionscode' => 'text',
			'value'       => '',
		),
		'ipblock_api_key' => array(
			'title'       => 'API key',
			'description' => 'Your ip-block.com API key. Sent in the request body.',
			'optionscode' => 'text',
			'value'       => '',
		),
		'ipblock_api_url' => array(
			'title'       => 'API URL',
			'description' => 'The screening endpoint. Leave as default unless instructed otherwise.',
			'optionscode' => 'text',
			'value'       => 'https://api.ip-block.com/v1/check',
		),
		'ipblock_fail_open' => array(
			'title'       => 'Fail open',
			'description' => 'If the API times out or errors, allow the visitor (recommended). Set to No to block on failure.',
			'optionscode' => 'yesno',
			'value'       => '1',
		),
		'ipblock_cache_ttl' => array(
			'title'       => 'Cache lifetime (seconds)',
			'description' => 'How long a decision is cached, keyed by IP + user agent + referrer. 0 = check every request.',
			'optionscode' => 'numeric',
			'value'       => '300',
		),
		'ipblock_behind_proxy' => array(
			'title'       => 'Behind a proxy / CDN',
			'description' => 'Enable when behind Cloudflare or another reverse proxy so the real IP is read from CF-Connecting-IP / X-Forwarded-For.',
			'optionscode' => 'yesno',
			'value'       => '0',
		),
		'ipblock_block_action' => array(
			'title'       => 'Block action',
			'description' => 'What to do with a blocked visitor.',
			'optionscode' => "select\nredirect=Redirect to ip-block.com\nmessage=Show an HTTP 403 message",
			'value'       => 'redirect',
		),
		'ipblock_block_message' => array(
			'title'       => 'Block message',
			'description' => 'Message returned with the HTTP 403 response when the block action is "message".',
			'optionscode' => 'textarea',
			'value'       => 'Access to this board has been denied.',
		),
		'ipblock_whitelist' => array(
			'title'       => 'IP whitelist',
			'description' => 'One IP address per line. Whitelisted IPs are always allowed and never sent to the API.',
			'optionscode' => 'textarea',
			'value'       => '',
		),
	);

	$disporder = 1;
	foreach ($settings as $name => $setting) {
		$db->insert_query('settings', array(
			'name'        => $db->escape_string($name),
			'title'       => $db->escape_string($setting['title']),
			'description' => $db->escape_string($setting['description']),
			'optionscode' => $db->escape_string($setting['optionscode']),
			'value'       => $db->escape_string($setting['value']),
			'disporder'   => $disporder++,
			'gid'         => (int) $gid,
		));
	}

	// Regenerate inc/settings.php so the new settings are usable.
	rebuild_settings();
}

/**
 * Activate the plugin. Settings are created at install time; nothing further
 * is required here, but the hook is provided per MyBB convention.
 *
 * @return void
 */
function ipblock_activate()
{
	// Intentionally empty - configuration lives in the settings created on install.
}

/**
 * Deactivate the plugin. No template/theme changes to revert.
 *
 * @return void
 */
function ipblock_deactivate()
{
	// Intentionally empty.
}

/**
 * Uninstall: remove all settings, the settings group and the decision cache.
 *
 * @return void
 */
function ipblock_uninstall()
{
	global $db, $cache;

	$db->delete_query('settings', "name LIKE 'ipblock_%'");
	$db->delete_query('settinggroups', "name='ipblock'");

	// Drop our decision cache.
	$cache->delete('ipblock');

	rebuild_settings();
}

/**
 * Front-end guard (global_start hook).
 *
 * @return void
 */
function ipblock_run()
{
	global $mybb;

	// Disabled or not configured yet.
	if (empty($mybb->settings['ipblock_enabled'])
		|| empty($mybb->settings['ipblock_site_id'])
		|| empty($mybb->settings['ipblock_api_key'])) {
		return;
	}

	$ip = ipblock_client_ip();
	if ($ip === '') {
		return;
	}

	// The whitelist is always honoured.
	if (ipblock_is_whitelisted($ip)) {
		return;
	}

	$user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
	$referrer   = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';

	if (ipblock_decide($ip, $user_agent, $referrer) === 'block') {
		ipblock_block();
	}
}

/**
 * Resolve a decision, using MyBB's data cache when enabled.
 *
 * @param string $ip
 * @param string $user_agent
 * @param string $referrer
 * @return string 'allow' | 'block'
 */
function ipblock_decide($ip, $user_agent, $referrer)
{
	global $mybb, $cache;

	$ttl = (int) $mybb->settings['ipblock_cache_ttl'];
	$key = md5($ip . '|' . $user_agent . '|' . $referrer);
	$now = time();

	if ($ttl > 0) {
		$store = $cache->read('ipblock');
		if (!is_array($store)) {
			$store = array();
		}

		// Prune expired entries and honour a live cache hit.
		$changed = false;
		foreach ($store as $k => $entry) {
			if (!isset($entry['expires']) || $entry['expires'] < $now) {
				unset($store[$k]);
				$changed = true;
			}
		}

		if (isset($store[$key]) && $store[$key]['expires'] >= $now) {
			if ($changed) {
				$cache->update('ipblock', $store);
			}
			return $store[$key]['action'];
		}
	}

	$result = ipblock_api_check($ip, $user_agent, $referrer);

	if ($result === null) {
		// Any error/timeout => apply fail mode (default FAIL OPEN = allow).
		$action = !empty($mybb->settings['ipblock_fail_open']) ? 'allow' : 'block';
	} else {
		$action = $result;
	}

	if ($ttl > 0) {
		if (!isset($store) || !is_array($store)) {
			$store = array();
		}
		$store[$key] = array('action' => $action, 'expires' => $now + $ttl);
		$cache->update('ipblock', $store);
	}

	return $action;
}

/**
 * Call the ip-block.com API (1 second timeout).
 *
 * @param string $ip
 * @param string $user_agent
 * @param string $referrer
 * @return string|null 'allow', 'block', or null on any error/timeout.
 */
function ipblock_api_check($ip, $user_agent, $referrer)
{
	global $mybb;

	$api_url = (string) $mybb->settings['ipblock_api_url'];
	if ($api_url === '') {
		$api_url = 'https://api.ip-block.com/v1/check';
	}

	$payload = json_encode(array(
		'api_key'    => (string) $mybb->settings['ipblock_api_key'],
		'site_id'    => (string) $mybb->settings['ipblock_site_id'],
		'ip'         => $ip,
		'user_agent' => $user_agent,
		'referrer'   => $referrer,
	));

	$body = ipblock_http_post($api_url, $payload);
	if ($body === null) {
		return null;
	}

	$data = json_decode($body, true);
	if (!is_array($data) || !isset($data['action'])) {
		return null;
	}

	// Blocked ONLY on an explicit "block" action.
	return ($data['action'] === 'block') ? 'block' : 'allow';
}

/**
 * POST helper with a hard 1 second timeout (cURL, with a stream fallback).
 *
 * @param string $url
 * @param string $payload
 * @return string|null Response body, or null on any transport/HTTP error.
 */
function ipblock_http_post($url, $payload)
{
	if (function_exists('curl_init')) {
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $payload,
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'Content-Length: ' . strlen($payload),
			),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 1,
			CURLOPT_CONNECTTIMEOUT => 1,
			CURLOPT_FOLLOWLOCATION => false,
		));

		$body   = curl_exec($ch);
		$errno  = curl_errno($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($errno !== 0 || $body === false || $status < 200 || $status >= 300) {
			return null;
		}

		return $body;
	}

	// Fallback: PHP streams with the same 1 second timeout.
	$context = stream_context_create(array(
		'http' => array(
			'method'        => 'POST',
			'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
			'content'       => $payload,
			'timeout'       => 1,
			'ignore_errors' => true,
		),
	));

	$body = @file_get_contents($url, false, $context);
	if ($body === false) {
		return null;
	}

	$status = 0;
	if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
		$status = (int) $m[1];
	}

	if ($status < 200 || $status >= 300) {
		return null;
	}

	return $body;
}

/**
 * Determine the real client IP address (respecting the behind_proxy setting).
 *
 * @return string A valid IP, or '' when none could be determined.
 */
function ipblock_client_ip()
{
	global $mybb;

	$candidates = array();

	if (!empty($mybb->settings['ipblock_behind_proxy'])) {
		if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
			$candidates[] = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
		}
		if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
			$candidates[] = trim($parts[0]);
		}
	}

	if (!empty($_SERVER['REMOTE_ADDR'])) {
		$candidates[] = (string) $_SERVER['REMOTE_ADDR'];
	}

	foreach ($candidates as $candidate) {
		if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
			return $candidate;
		}
	}

	return '';
}

/**
 * Is the IP explicitly whitelisted? (one IP per line)
 *
 * @param string $ip
 * @return bool
 */
function ipblock_is_whitelisted($ip)
{
	global $mybb;

	$whitelist = (string) $mybb->settings['ipblock_whitelist'];
	if ($whitelist === '') {
		return false;
	}

	foreach (preg_split('/\r\n|\r|\n/', $whitelist) as $line) {
		if (trim($line) === $ip) {
			return true;
		}
	}

	return false;
}

/**
 * Halt the request for a blocked visitor.
 *
 * @return void
 */
function ipblock_block()
{
	global $mybb, $lang;

	if ($mybb->settings['ipblock_block_action'] === 'message') {
		$message = trim((string) $mybb->settings['ipblock_block_message']);
		if ($message === '') {
			$lang->load('ipblock');
			$message = isset($lang->ipblock_default_message) ? $lang->ipblock_default_message : 'Access denied.';
		}

		@header('HTTP/1.1 403 Forbidden', true, 403);
		@header('Content-Type: text/plain; charset=UTF-8');
		echo $message;
	} else {
		@header('Location: https://www.ip-block.com/blocked.php', true, 302);
	}

	exit;
}
