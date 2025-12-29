<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__.'/error.log');
error_reporting(E_ALL);

header("Content-Type: application/json");

try {
	require_once "db.php";
	
	if (empty($_SESSION["user_id"])) {
		echo json_encode(["success" => false, "error" => "Not logged in"]);
		exit();
	}
	
	$calendarId = $_GET['id'] ?? null;
	$startDate  = $_GET['start'] ?? null;
	$endDate    = $_GET['end'] ?? null;
	
	if (!$calendarId || !$startDate || !$endDate) {
		echo json_encode(["success" => false, "error" => "Missing parameters"]);
		exit();
	}
	
	// ------------------------------------------------------------------
	// Fetch calendar URL
	// ------------------------------------------------------------------
	$pdo = conn();
	$stmt = $pdo->prepare("SELECT calendar_url FROM user_calendars WHERE id=? AND user_id=?");
	$stmt->execute([$calendarId, $_SESSION["user_id"]]);
	$cal = $stmt->fetch(PDO::FETCH_ASSOC);
	
	if (!$cal) {
		echo json_encode(["success" => false, "error" => "Calendar not found"]);
		exit();
	}
	
	$icalUrl = $cal['calendar_url'];
	
	// ------------------------------------------------------------------
	// Download ICS (using cURL for remote URLs)
	// ------------------------------------------------------------------
	$ch = curl_init($icalUrl);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 15);
	$rawICS = curl_exec($ch);
	if (curl_errno($ch)) {
		throw new Exception("Error fetching iCal: " . curl_error($ch));
	}
	curl_close($ch);
	
	if (!$rawICS) {
		echo json_encode(["success" => false, "error" => "Failed to fetch iCal data"]);
		exit();
	}
	
	// ------------------------------------------------------------------
	// Unfold folded lines (ICS spec)
	// ------------------------------------------------------------------
	function ics_unfold($raw) {
		$lines = preg_split("/\r\n|\n|\r/", $raw);
		$out = [];
		foreach ($lines as $line) {
			if (
					isset($out[count($out) - 1]) &&
					(strpos($line, " ") === 0 || strpos($line, "\t") === 0)
					) {
						// Continuation of previous line
						$out[count($out) - 1] .= ltrim($line);
					} else {
						$out[] = $line;
					}
		}
		return $out;
	}
	
	$lines = ics_unfold($rawICS);
	
	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------
	$tz = new DateTimeZone('America/Chicago');
	$rangeStart = new DateTime($startDate, $tz);
	$rangeEnd   = (new DateTime($endDate, $tz))->setTime(23, 59, 59);
	
	// Parse EXDATEs into an array of 'Y-m-d' strings
	function parse_exdates(array $event, DateTimeZone $tz): array {
		$dates = [];
		if (!isset($event['EXDATE'])) return $dates;
		
		$rawLines = is_array($event['EXDATE']) ? $event['EXDATE'] : [$event['EXDATE']];
		
		foreach ($rawLines as $raw) {
			// Raw may contain parameters + multiple comma-separated date-times
			// e.g. "TZID=America/Chicago:20260117T190000,20260214T190000"
			$parts = explode(',', $raw);
			foreach ($parts as $p) {
				$p = trim($p);
				if ($p === '') continue;
				
				// Strip any parameter prefix and colon (TZID=..., VALUE=DATE: etc)
				$clean = preg_replace('/^.*:/', '', $p);
				if ($clean === '') continue;
				
				try {
					$dt = new DateTime($clean, $tz);
				} catch (Exception $e) {
					continue;
				}
				
				$dates[$dt->format('Y-m-d')] = true;
			}
		}
		return $dates;
	}
	
	// Expand a recurring event into concrete occurrences in the given range
	function expand_rrule_event(array $event, DateTime $rangeStart, DateTime $rangeEnd, DateTimeZone $tz): array {
		$results = [];
		
		if (!isset($event['DTSTART']) || !isset($event['RRULE'])) {
			return $results;
		}
		
		// Base start/end
		$dtStartRaw = preg_replace('/^.*:/', '', $event['DTSTART']);
		$dtEndRaw   = isset($event['DTEND']) ? preg_replace('/^.*:/', '', $event['DTEND']) : $dtStartRaw;
		
		$baseStart = new DateTime($dtStartRaw, $tz);
		$baseEnd   = new DateTime($dtEndRaw, $tz);
		$duration  = $baseEnd->getTimestamp() - $baseStart->getTimestamp();
		
		// All-day if only YYYYMMDD
		$allDay = (bool)preg_match('/^\d{8}$/', $dtStartRaw);
		
		// Parse RRULE into array
		$rr = [];
		foreach (explode(';', $event['RRULE']) as $chunk) {
			if (strpos($chunk, '=') === false) continue;
			[$k, $v] = explode('=', $chunk, 2);
			$rr[strtoupper($k)] = strtoupper($v);
		}
		
		$freq     = $rr['FREQ']     ?? 'WEEKLY';
		$interval = isset($rr['INTERVAL']) ? max(1, (int)$rr['INTERVAL']) : 1;
		
		// UNTIL or infinite recurrence limited to search range
		if (!empty($rr['UNTIL'])) {
			$until = new DateTime($rr['UNTIL'], $tz);
		} else {
			$until = clone $rangeEnd;
		}
		
		// Exdates
		$exdates = parse_exdates($event, $tz);
		
		$summary     = $event['SUMMARY']     ?? 'No Summary';
		$location    = $event['LOCATION']    ?? 'No Location';
		$description = $event['DESCRIPTION'] ?? '';
		
		// Common function to push an occurrence
		$pushOccurrence = function(DateTime $startOcc) use (
				&$results, $duration, $allDay, $summary, $location, $description
				) {
					$occStart = clone $startOcc;
					$occEnd   = clone $startOcc;
					if ($allDay) {
						$occStart->setTime(0,0,0);
						$occEnd->setTime(23,59,59);
					} else {
						$occEnd->modify("+{$duration} seconds");
					}
					
					$results[] = [
							'start'       => $occStart->format(DateTime::ATOM),
							'end'         => $occEnd->format(DateTime::ATOM),
							'summary'     => $summary,
							'location'    => $location,
							'description' => $description
					];
		};
		
		// ---------- WEEKLY ----------
		if ($freq === 'WEEKLY') {
			// BYDAY list or fallback to baseStart weekday
			$bydays = [];
			if (!empty($rr['BYDAY'])) {
				$bydays = explode(',', $rr['BYDAY']); // e.g. SA,FR
			} else {
				$bydays[] = strtoupper(substr($baseStart->format('D'), 0, 2));
			}
			
			// WKST (week start) for week indexing
			$wkst = $rr['WKST'] ?? 'MO';
			$wkst = strtoupper($wkst);
			$dowMap = ['SU'=>0,'MO'=>1,'TU'=>2,'WE'=>3,'TH'=>4,'FR'=>5,'SA'=>6];
			$offsetDow = $dowMap[$wkst] ?? 1; // default Monday
			
			// Start scanning from max(rangeStart, baseStart)
			$scanStart = clone $rangeStart;
			if ($scanStart < $baseStart) {
				$scanStart = clone $baseStart;
			}
			
			for ($d = clone $scanStart; $d <= $until; $d->modify('+1 day')) {
				if ($d < $baseStart) continue;
				
				// In display range?
				if ($d > $rangeEnd) break;
				
				// Check BYDAY
				$dow2 = strtoupper(substr($d->format('D'), 0, 2));
				if (!in_array($dow2, $bydays, true)) continue;
				
				// Days difference from baseStart
				$daysDiff = (int) floor(($d->getTimestamp() - $baseStart->getTimestamp()) / 86400);
				if ($daysDiff < 0) continue;
				
				// Adjust week boundary using WKST
				$startDow  = (int)$baseStart->format('w'); // 0=Sun..6=Sat
				$shift     = ($startDow - $offsetDow + 7) % 7;
				$weekIndex = intdiv(max(0, $daysDiff - $shift), 7);
				
				if ($weekIndex % $interval !== 0) continue;
				
				// EXDATE filter
				if (isset($exdates[$d->format('Y-m-d')])) continue;
				
				$pushOccurrence($d);
			}
		}
		
		// ---------- MONTHLY ----------
		if ($freq === 'MONTHLY') {
			// BYMONTHDAY or fallback to baseStart's day-of-month
			$monthDays = [];
			if (!empty($rr['BYMONTHDAY'])) {
				foreach (explode(',', $rr['BYMONTHDAY']) as $md) {
					$md = (int)$md;
					if ($md >= 1 && $md <= 31) $monthDays[] = $md;
				}
			}
			if (!$monthDays) {
				$monthDays[] = (int)$baseStart->format('j');
			}
			
			// Start at the first day of the month of baseStart or rangeStart
			$current = (clone $baseStart)->modify('first day of this month');
			if ($current < $rangeStart) {
				$current = (clone $rangeStart)->modify('first day of this month');
			}
			
			while ($current <= $until && $current <= $rangeEnd) {
				// Month difference from baseStart
				$yearDiff  = (int)$current->format('Y') - (int)$baseStart->format('Y');
				$monthDiff = $yearDiff * 12 + ((int)$current->format('n') - (int)$baseStart->format('n'));
				
				if ($monthDiff >= 0 && $monthDiff % $interval === 0) {
					
					// BYSETPOS support (like "2nd Saturday" or "last Friday")
					if (!empty($rr['BYDAY']) && !empty($rr['BYSETPOS'])) {
						$posList  = array_map('intval', explode(',', $rr['BYSETPOS']));
						$weekdays = explode(',', $rr['BYDAY']);
						$monthDays = [];  // override numeric list
						
						foreach ($posList as $pos) {
							foreach ($weekdays as $wd) {
								$scan = clone $current;
								$scan->modify("first $wd of this month");
								$weekCount = 1;
								
								while ($scan->format('n') == $current->format('n')) {
									if ($pos == $weekCount) {
										$monthDays[] = (int)$scan->format('j');
									}
									$scan->modify("+1 week");
									$weekCount++;
								}
							}
						}
					}
					
					foreach ($monthDays as $md) {
						$occ = clone $current;
						// Safely set day (skip invalid dates like Feb 30)
						$occ->setDate(
								(int)$current->format('Y'),
								(int)$current->format('n'),
								min($md, (int)$current->format('t'))
								);
						
						if ($occ < $baseStart) continue;
						if ($occ < $rangeStart) continue;
						if ($occ > $until || $occ > $rangeEnd) continue;
						
						if (isset($exdates[$occ->format('Y-m-d')])) continue;
						
						$pushOccurrence($occ);
					}
				}
				
				// Next month
				$current->modify('first day of next month');
			}
		}
		
		return $results;
	}
	
	// ------------------------------------------------------------------
	// Main VEVENT parse: normal + recurring
	// ------------------------------------------------------------------
	$events = [];
	$event  = null;
	
	foreach ($lines as $line) {
		$trim = trim($line);
		
		if ($trim === 'BEGIN:VEVENT') {
			$event = [];
			continue;
		}
		
		if ($trim === 'END:VEVENT') {
			if ($event && isset($event['DTSTART'])) {
				// If RRULE present → expand into individual occurrences
				if (isset($event['RRULE'])) {
					$expanded = expand_rrule_event($event, $rangeStart, $rangeEnd, $tz);
					foreach ($expanded as $occ) {
						$events[] = $occ;
					}
				} else {
					// One-time event
					$dtStartRaw = preg_replace('/^.*:/', '', $event['DTSTART']);
					$dtEndRaw   = isset($event['DTEND']) ? preg_replace('/^.*:/', '', $event['DTEND']) : $dtStartRaw;
					
					$start = new DateTime($dtStartRaw, $tz);
					$end   = new DateTime($dtEndRaw, $tz);
					
					$allDay = (bool)preg_match('/^\d{8}$/', $dtStartRaw);
					if ($allDay) {
						$end->setTime(23,59,59);
					}
					
					if ($end >= $rangeStart && $start <= $rangeEnd) {
						$events[] = [
								'start'       => $start->format(DateTime::ATOM),
								'end'         => $end->format(DateTime::ATOM),
								'summary'     => $event['SUMMARY']     ?? 'No Summary',
								'location'    => $event['LOCATION']    ?? 'No Location',
								'description' => $event['DESCRIPTION'] ?? ''
						];
					}
				}
			}
			$event = null;
			continue;
		}
		
		// Inside VEVENT: parse key/value
		if ($event !== null && strpos($trim, ':') !== false) {
			[$key, $value] = explode(':', $trim, 2);
			$keyUpper = strtoupper(explode(';', $key)[0]);
			
			if ($keyUpper === 'EXDATE') {
				// Keep all EXDATE lines
				if (!isset($event['EXDATE'])) {
					$event['EXDATE'] = [];
				}
				$event['EXDATE'][] = $value;
			} else {
				$event[$keyUpper] = $value;
			}
		}
	}
	
	echo json_encode([
			"success" => true,
			"events"  => $events
	]);
	
} catch (Throwable $e) {
	echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
