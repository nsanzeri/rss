<?php
// core/env.php
// Minimal .env loader + env() helper.
// Loads .env from project root by default.

function load_env(string $envPath): void {
	if (!is_readable($envPath)) return;
	
	$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if (!$lines) return;
	
	foreach ($lines as $line) {
		$line = trim($line);
		if ($line === '' || str_starts_with($line, '#')) continue;
		
		// allow: export KEY=VALUE
		if (str_starts_with($line, 'export ')) {
			$line = trim(substr($line, 7));
		}
		
		$pos = strpos($line, '=');
		if ($pos === false) continue;
		
		$key = trim(substr($line, 0, $pos));
		$val = trim(substr($line, $pos + 1));
		
		// Strip surrounding quotes
		if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
				(str_starts_with($val, "'") && str_ends_with($val, "'"))) {
					$val = substr($val, 1, -1);
				}
				
				// Don’t overwrite existing env values
				if (getenv($key) === false) {
					putenv("{$key}={$val}");
					$_ENV[$key] = $val;
				}
	}
}

function env(string $key, $default = null) {
	$val = getenv($key);
	if ($val === false) return $default;
	
	$lower = strtolower($val);
	if ($lower === 'true') return true;
	if ($lower === 'false') return false;
	if ($lower === 'null') return null;
	
	return $val;
}
