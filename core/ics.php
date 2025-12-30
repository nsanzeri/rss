<?php
error_log("RUNNING: " . __FILE__ . " mtime=" . @filemtime(__FILE__));
// Lightweight ICS parser for v1.
// Supports:
// - DTSTART/DTEND (date-time or all-day VALUE=DATE)
// - UID, SUMMARY
// - RRULE with FREQ=DAILY|WEEKLY and UNTIL or COUNT (basic expansion)
// Notes: This is intentionally conservative. It does not implement full iCal spec.

function ics_fetch(string $url): array {
	$ch = curl_init($url);
	curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 20,
			CURLOPT_USERAGENT => "ReadySetShowsOps/1.0",
	]);
	$body = curl_exec($ch);
	$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$err  = curl_error($ch);
	curl_close($ch);
	
	if ($body === false || $http >= 400) {
		return [false, null, $http ?: null, $err ?: ("HTTP " . $http)];
	}
	return [true, $body, $http, null];
}

function ics_unfold_lines(string $ics): array {
	$ics = str_replace(["\r\n", "\r"], "\n", $ics);
	$lines = explode("\n", $ics);
	$out = [];
	foreach ($lines as $line) {
		if ($line === '') continue;
		if (!empty($out) && (isset($line[0]) && ($line[0] === ' ' || $line[0] === "\t"))) {
			$out[count($out)-1] .= substr($line, 1);
		} else {
			$out[] = $line;
		}
	}
	return $out;
}

function ics_parse_datetime(string $value, ?string $tzid): DateTimeImmutable {
	// value can be:
	// - 20251226T180000Z
	// - 20251226T180000
	// - 20251226 (all-day handled elsewhere)
	$isZ = str_ends_with($value, "Z");
	$tz = $isZ ? new DateTimeZone("UTC") : new DateTimeZone($tzid ?: "UTC");
	
	if ($isZ) {
		$value = substr($value, 0, -1);
	}
	// Accept both basic and extended forms; we expect basic.
	$dt = DateTimeImmutable::createFromFormat("Ymd\THis", $value, $tz);
	if (!$dt) {
		// Try date-only fallback
		$dt = DateTimeImmutable::createFromFormat("Ymd", $value, $tz);
		if (!$dt) {
			throw new RuntimeException("Invalid DTSTART/DTEND: " . $value);
		}
	}
	return $dt;
}

function ics_rrule_parse(string $rrule): array {
	$parts = [];
	foreach (explode(";", $rrule) as $kv) {
		$kvp = explode("=", $kv, 2);
		if (count($kvp) === 2) {
			$parts[strtoupper($kvp[0])] = strtoupper($kvp[1]);
		}
	}
	return $parts;
}

function ics_expand(array $event, int $maxInstances = 500): array {
	// Returns list of expanded instances (including the original).
	if (empty($event['rrule'])) return [$event];
	
	$r = ics_rrule_parse($event['rrule']);
	$freq = $r['FREQ'] ?? null;
	if (!$freq || !in_array($freq, ['DAILY','WEEKLY'], true)) {
		// unsupported recurrence
		return [$event];
	}
	
	$count = isset($r['COUNT']) ? max(1, (int)$r['COUNT']) : null;
	$until = isset($r['UNTIL']) ? $r['UNTIL'] : null;
	$byday = isset($r['BYDAY']) ? explode(",", $r['BYDAY']) : null;
	
	$start = $event['start_dt'];
	$end   = $event['end_dt'];
	$durSeconds = $end->getTimestamp() - $start->getTimestamp();
	
	$instances = [];
	$instances[] = $event;
	
	$i = 1;
	$cursor = $start;
	
	$untilDt = null;
	if ($until) {
		// UNTIL may be Z-terminated. Parse in UTC if so.
		$tzid = $event['tzid'] ?: 'UTC';
		$untilDt = ics_parse_datetime($until, $tzid);
	}
	
	// Helper for weekly BYDAY
	$map = ['MO'=>1,'TU'=>2,'WE'=>3,'TH'=>4,'FR'=>5,'SA'=>6,'SU'=>7];
	
	while (true) {
		if ($count !== null && $i >= $count) break;
		if (count($instances) >= $maxInstances) break;
		
		if ($freq === 'DAILY') {
			$cursor = $cursor->modify("+1 day");
			$instStart = $cursor;
			$instEnd = $instStart->modify("+" . $durSeconds . " seconds");
			if ($untilDt && $instStart > $untilDt) break;
			
			$copy = $event;
			$copy['start_dt'] = $instStart;
			$copy['end_dt'] = $instEnd;
			$instances[] = $copy;
			$i++;
			continue;
		}
		
		if ($freq === 'WEEKLY') {
			// If BYDAY specified, generate occurrences within each week.
			// We'll advance week-by-week from the original start week.
			$weekStart = $start->modify("+" . $i . " week")->setTime((int)$start->format('H'), (int)$start->format('i'), (int)$start->format('s'));
			
			$days = $byday ?: [strtoupper(substr($start->format('D'),0,2))]; // rough fallback
			// Better fallback: map start weekday to MO..SU
			$startDow = (int)$start->format('N');
			$startKey = array_search($startDow, $map, true);
			if (!$byday && $startKey) $days = [$startKey];
			
			foreach ($days as $d) {
				$d = strtoupper(trim($d));
				if (!isset($map[$d])) continue;
				$targetDow = $map[$d];
				$delta = $targetDow - (int)$weekStart->format('N');
				$instStart = $weekStart->modify(($delta >= 0 ? "+" : "") . $delta . " day");
				// Keep the original time-of-day
				$instStart = $instStart->setTime((int)$start->format('H'), (int)$start->format('i'), (int)$start->format('s'));
				$instEnd = $instStart->modify("+" . $durSeconds . " seconds");
				
				if ($untilDt && $instStart > $untilDt) {
					continue 2;
				}
				
				$copy = $event;
				$copy['start_dt'] = $instStart;
				$copy['end_dt'] = $instEnd;
				$instances[] = $copy;
				if ($count !== null && count($instances) >= $count) break 2;
				if (count($instances) >= $maxInstances) break 2;
			}
			
			$i++;
			continue;
		}
	}
	
	return $instances;
}

/**
 * Expand (including RRULE) but ONLY within the given window.
 * This prevents huge recurrence expansions that can time out previews/imports.
 *
 * Window semantics:
 * - Returns instances whose time range overlaps [from..to] (inclusive).
 * - Recurrences are generated only for start times that could overlap the window.
 */
function ics_expand_between(array $event, DateTimeImmutable $from, DateTimeImmutable $to, int $maxInstances = 500): array {
	if ($to < $from) return [];
	
	$start = $event['start_dt'];
	$end   = $event['end_dt'];
	$durSeconds = max(0, $end->getTimestamp() - $start->getTimestamp());
	
	$overlaps = function(DateTimeImmutable $s, DateTimeImmutable $e) use ($from, $to): bool {
		if ($e < $from) return false;
		if ($s > $to) return false;
		return true;
	};
	
	// Non-recurring: keep only if it overlaps
	if (empty($event['rrule'])) {
		return $overlaps($start, $end) ? [$event] : [];
	}
	
	$r = ics_rrule_parse($event['rrule']);
	$freq = $r['FREQ'] ?? null;
	if (!$freq || !in_array($freq, ['DAILY','WEEKLY'], true)) {
		// Unsupported recurrence; treat as single instance
		return $overlaps($start, $end) ? [$event] : [];
	}
	
	$count = isset($r['COUNT']) ? max(1, (int)$r['COUNT']) : null;
	$until = $r['UNTIL'] ?? null;
	$byday = isset($r['BYDAY']) ? array_filter(array_map('trim', explode(',', $r['BYDAY']))) : null;
	
	$tzid = $event['tzid'] ?: 'UTC';
	$untilDt = null;
	if ($until) {
		try {
			$untilDt = ics_parse_datetime($until, $tzid);
		} catch (Throwable $e) {
			$untilDt = null;
		}
	}
	
	// Effective cap on generation window
	$windowEnd = $to;
	if ($untilDt && $untilDt < $windowEnd) $windowEnd = $untilDt;
	
	// Earliest possible overlapping start is (from - duration)
	$minStart = $from->modify('-' . $durSeconds . ' seconds');
	
	// EXDATE support: skip specific recurrence instances (day-level), normalized to the request timezone
	$seriesTz = $start->getTimezone();
	
	$exTs = [];
	if (!empty($event['exdate']) && is_array($event['exdate'])) {
		$exTzid = $event['exdate_params']['TZID'] ?? ($event['tzid'] ?: null);
		$exTz = new DateTimeZone($exTzid ?: $seriesTz->getName());
		
		foreach ($event['exdate'] as $raw) {
			$raw = trim((string)$raw);
			if ($raw === '') continue;
			
			try {
				if (strlen($raw) === 8) {
					// DATE-only EXDATE: assume same local time as DTSTART
					$dt = DateTimeImmutable::createFromFormat('Ymd', $raw, $exTz)->setTime(
							(int)$start->format('H'),
							(int)$start->format('i'),
							(int)$start->format('s')
							);
				} else {
					$dt = ics_parse_datetime($raw, $exTz->getName());
				}
				
				// Normalize to the same timezone as DTSTART (optional, but consistent)
				$dt = $dt->setTimezone($seriesTz);
				
				// Key by absolute instant
				$exTs[$dt->getTimestamp()] = true;
				
			} catch (Throwable $e) {
				continue;
			}
		}
	}
	
	
	
	$instances = [];
	
	// Weekday map
	$map = ['MO'=>1,'TU'=>2,'WE'=>3,'TH'=>4,'FR'=>5,'SA'=>6,'SU'=>7];
	
	$add = function(DateTimeImmutable $s) use (&$instances, $event, $durSeconds, $overlaps, $exTs): void {
		$e = $durSeconds ? $s->modify('+' . $durSeconds . ' seconds') : $s;
		
		if (isset($exTs[$s->getTimestamp()])) return;
		if (!$overlaps($s, $e)) return;
		
		$copy = $event;
		$copy['start_dt'] = $s;
		$copy['end_dt']   = $e;
		$instances[] = $copy;
	};
	
	
	if ($freq === 'DAILY') {
		$cursor = $start;
		
		// Fast-forward cursor near the window
		if ($cursor < $minStart) {
			$deltaSec = $minStart->getTimestamp() - $cursor->getTimestamp();
			$days = (int)floor($deltaSec / 86400);
			if ($days > 0) $cursor = $cursor->modify('+' . $days . ' day');
			while ($cursor < $minStart) $cursor = $cursor->modify('+1 day');
		}
		
		// Approximate series index for COUNT
		$seriesIndex = 0;
		if ($count !== null) {
			$deltaSec = $cursor->getTimestamp() - $start->getTimestamp();
			$seriesIndex = max(0, (int)floor($deltaSec / 86400));
			if ($seriesIndex >= $count) return [];
		}
		
		$guard = 0;
		while (true) {
			if ($cursor > $windowEnd) break;
			if ($count !== null && $seriesIndex >= $count) break;
			if (count($instances) >= $maxInstances) break;
			
			$add($cursor);
			$cursor = $cursor->modify('+1 day');
			$seriesIndex++;
			$guard++;
			if ($guard > ($maxInstances * 3)) break;
		}
		
		return $instances;
	}
	
	if ($freq === 'WEEKLY') {
		if (!$byday || count($byday) === 0) {
			$startDow = (int)$start->format('N');
			$startKey = array_search($startDow, $map, true);
			$byday = $startKey ? [$startKey] : ['MO'];
		}
		
		$weekCursor = $start;
		
		// Fast-forward weeks near the window
		if ($weekCursor < $minStart) {
			$deltaSec = $minStart->getTimestamp() - $weekCursor->getTimestamp();
			$weeks = (int)floor($deltaSec / (86400*7));
			if ($weeks > 0) $weekCursor = $weekCursor->modify('+' . $weeks . ' week');
			while ($weekCursor < $minStart) $weekCursor = $weekCursor->modify('+1 week');
		}
		
		// Align week cursor to original time-of-day
		$weekCursor = $weekCursor->setTime((int)$start->format('H'), (int)$start->format('i'), (int)$start->format('s'));
		
		$guard = 0;
		while (true) {
			if ($weekCursor > $windowEnd) break;
			if (count($instances) >= $maxInstances) break;
			
			foreach ($byday as $d) {
				$d = strtoupper(trim($d));
				if (!isset($map[$d])) continue;
				$targetDow = $map[$d];
				$delta = $targetDow - (int)$weekCursor->format('N');
				$instStart = $weekCursor->modify(($delta >= 0 ? '+' : '') . $delta . ' day');
				$instStart = $instStart->setTime((int)$start->format('H'), (int)$start->format('i'), (int)$start->format('s'));
				
				if ($instStart < $start) continue; // don't emit before series start
				if ($instStart > $windowEnd) continue;
				if ($untilDt && $instStart > $untilDt) continue;
				
				$add($instStart);
				if ($count !== null && count($instances) >= $count) break 2;
				if (count($instances) >= $maxInstances) break 2;
			}
			
			$weekCursor = $weekCursor->modify('+1 week');
			$guard++;
			if ($guard > ($maxInstances * 3)) break;
		}
		
		return $instances;
	}
	
	return $instances;
}

/**
 * Like ics_to_instances(), but bounds RRULE expansion to [from..to].
 */
function ics_to_instances_between(array $rawEvent, DateTimeZone $fallbackTz, DateTimeImmutable $from, DateTimeImmutable $to, int $maxInstances = 500): array {
	$tzid = $rawEvent['dtstart_params']['TZID'] ?? null;
	$isAllDay = (strtoupper($rawEvent['dtstart_params']['VALUE'] ?? '') === 'DATE') || (strlen($rawEvent['dtstart'] ?? '') === 8);
	
	if ($isAllDay) {
		$start = DateTimeImmutable::createFromFormat('Ymd', $rawEvent['dtstart'], $fallbackTz)->setTime(0,0,0);
		$endVal = $rawEvent['dtend'] ?? null;
		if ($endVal && strlen($endVal) === 8) {
			$end = DateTimeImmutable::createFromFormat('Ymd', $endVal, $fallbackTz)->setTime(0,0,0);
		} else {
			$end = $start->modify('+1 day');
		}
		$event = [
				'uid' => $rawEvent['uid'] ?? null,
				'summary' => $rawEvent['summary'] ?? '',
				'description' => $rawEvent['description'] ?? null,
				'is_all_day' => 1,
				'tzid' => $tzid,
				'start_dt' => $start,
				'end_dt' => $end,
				'rrule' => $rawEvent['rrule'] ?? null,
				'exdate' => $rawEvent['exdate'] ?? [],
				'exdate_params' => $rawEvent['exdate_params'] ?? [],
		];
		return ics_expand_between($event, $from, $to, $maxInstances);
	}
	
	$tz = $tzid ? new DateTimeZone($tzid) : $fallbackTz;
	
	$start = ics_parse_datetime($rawEvent['dtstart'], $tz->getName());
	$endVal = $rawEvent['dtend'] ?? null;
	if ($endVal) {
		$end = ics_parse_datetime($endVal, $tz->getName());
	} else {
		$end = $start->modify('+1 hour');
	}
	
	$event = [
			'uid' => $rawEvent['uid'] ?? null,
			'summary' => $rawEvent['summary'] ?? '',
			'description' => $rawEvent['description'] ?? null,
			'is_all_day' => 0,
			'tzid' => $tzid,
			'start_dt' => $start,
			'end_dt' => $end,
			'rrule' => $rawEvent['rrule'] ?? null,
			'exdate' => $rawEvent['exdate'] ?? [],
			'exdate_params' => $rawEvent['exdate_params'] ?? [],
	];
	
	return ics_expand_between($event, $from, $to, $maxInstances);
}


function ics_parse_events(string $ics): array {
	$lines = ics_unfold_lines($ics);
	$events = [];
	$in = false;
	$cur = [];
	
	foreach ($lines as $line) {
		$t = trim($line);
		
		if ($t === "BEGIN:VEVENT") {
			$in = true;
			$cur = [];
			continue;
		}
		if ($t === "END:VEVENT") {
			// Keep any VEVENT that has enough information to matter (main event or exceptions)
			if (!empty($cur['dtstart']) || !empty($cur['rrule']) || !empty($cur['exdate'])) {
				$events[] = $cur;
			}
			$in = false;
			$cur = [];
			continue;
		}
		if (!$in) continue;
		
		[$left, $value] = array_pad(explode(":", $line, 2), 2, "");
		$value = trim($value);
		
		// parse params
		$parts = explode(";", $left);
		$name = strtoupper(array_shift($parts));
		$params = [];
		foreach ($parts as $p) {
			[$k, $v] = array_pad(explode("=", $p, 2), 2, "");
			if ($k) $params[strtoupper($k)] = $v;
		}
		
		if ($name === "UID") $cur['uid'] = $value;
		if ($name === "SUMMARY") $cur['summary'] = $value;
		if ($name === "DESCRIPTION") $cur['description'] = $value;
		
		if ($name === "DTSTART") {
			$cur['dtstart'] = $value;
			$cur['dtstart_params'] = $params;
		}
		if ($name === "DTEND") {
			$cur['dtend'] = $value;
			$cur['dtend_params'] = $params;
		}
		
		if ($name === "RRULE") $cur['rrule'] = $value;
		if ($name === "STATUS") $cur['status'] = strtoupper($value);
		
		if ($name === "EXDATE") {
			// EXDATE can contain multiple comma-separated values
			$cur['exdate'] = $cur['exdate'] ?? [];
			foreach (explode(",", $value) as $ex) {
				$ex = trim($ex);
				if ($ex !== "") $cur['exdate'][] = $ex;
			}
			$cur['exdate_params'] = $params;
		}
		
		if ($name === "RECURRENCE-ID") {
			$cur['recurrence_id'] = $value;
			$cur['recurrence_id_params'] = $params;
		}
	}
	
	return $events;
}

function ics_to_instances(array $rawEvent, DateTimeZone $fallbackTz): array {
	$tzid = $rawEvent['dtstart_params']['TZID'] ?? null;
	$isAllDay = (strtoupper($rawEvent['dtstart_params']['VALUE'] ?? '') === 'DATE') || (strlen($rawEvent['dtstart'] ?? '') === 8);
	
	if ($isAllDay) {
		// DTSTART:YYYYMMDD  DTEND:YYYYMMDD (end is exclusive)
		$start = DateTimeImmutable::createFromFormat("Ymd", $rawEvent['dtstart'], $fallbackTz)->setTime(0,0,0);
		$endVal = $rawEvent['dtend'] ?? null;
		if ($endVal && strlen($endVal) === 8) {
			$end = DateTimeImmutable::createFromFormat("Ymd", $endVal, $fallbackTz)->setTime(0,0,0);
		} else {
			$end = $start->modify("+1 day");
		}
		$event = [
				'uid' => $rawEvent['uid'] ?? null,
				'summary' => $rawEvent['summary'] ?? '',
				'description' => $rawEvent['description'] ?? null,
				'is_all_day' => 1,
				'tzid' => $tzid,
				'start_dt' => $start,
				'end_dt' => $end,
				'rrule' => $rawEvent['rrule'] ?? null,
				'exdate' => $rawEvent['exdate'] ?? [],
				'exdate_params' => $rawEvent['exdate_params'] ?? [],
		];
		return ics_expand($event);
	}
	
	$tz = $tzid ? new DateTimeZone($tzid) : $fallbackTz;
	
	$start = ics_parse_datetime($rawEvent['dtstart'], $tz->getName());
	$endVal = $rawEvent['dtend'] ?? null;
	if ($endVal) {
		$end = ics_parse_datetime($endVal, $tz->getName());
	} else {
		// default duration 1 hour
		$end = $start->modify("+1 hour");
	}
	
	$event = [
			'uid' => $rawEvent['uid'] ?? null,
			'summary' => $rawEvent['summary'] ?? '',
			'description' => $rawEvent['description'] ?? null,
			'is_all_day' => 0,
			'tzid' => $tzid,
			'start_dt' => $start,
			'end_dt' => $end,
			'rrule' => $rawEvent['rrule'] ?? null,
			'exdate' => $rawEvent['exdate'] ?? [],
			'exdate_params' => $rawEvent['exdate_params'] ?? [],
	];
	
	return ics_expand($event);
}
