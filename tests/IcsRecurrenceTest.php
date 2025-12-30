<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class IcsRecurrenceTest extends TestCase
{
    private function readFixture(string $name): string
    {
        $path = __DIR__ . '/fixtures/' . $name;
        $this->assertFileExists($path, "Missing fixture: {$name}");
        $s = file_get_contents($path);
        $this->assertIsString($s);
        return $s;
    }

    private function parse(string $ics): array
    {
        $events = ics_parse_events($ics);
        $this->assertIsArray($events);
        $this->assertNotEmpty($events, "No events parsed from ICS");
        return $events;
    }

    private function instancesLocalStarts(array $rawEvent, string $fromYmd, string $toYmd, string $tz = 'America/Chicago'): array
    {
    	$from = new DateTimeImmutable($fromYmd . ' 00:00:00', new DateTimeZone($tz));
    	$to   = new DateTimeImmutable($toYmd . ' 23:59:59', new DateTimeZone($tz));
    	
    	// This matches your codebase: raw VEVENT -> instances within window
    	$instances = ics_to_instances_between($rawEvent, new DateTimeZone($tz), $from, $to, 500);
    	
    	$out = [];
    	foreach ($instances as $inst) {
    		$this->assertIsArray($inst);
    		$this->assertArrayHasKey('start_dt', $inst);
    		$this->assertInstanceOf(DateTimeInterface::class, $inst['start_dt']);
    		
    		$out[] = $inst['start_dt']->setTimezone(new DateTimeZone($tz))->format('Y-m-d H:i');
    	}
    	
    	sort($out);
    	return $out;
    }
    

    public function test_parse_extracts_rrule_and_exdate_list(): void
    {
        $ics = $this->readFixture('weekly_exdate.ics');
        $events = $this->parse($ics);

        $this->assertCount(1, $events);

        $e = $events[0];
        $this->assertArrayHasKey('rrule', $e);
        $this->assertNotEmpty($e['rrule'], 'RRULE should be present');

        $this->assertArrayHasKey('exdate', $e);
        $this->assertIsArray($e['exdate'], 'EXDATE should be parsed into an array');
        $this->assertCount(2, $e['exdate'], 'Expected 2 exdates in fixture');
    }

    public function test_expand_weekly_respects_exdate(): void
    {
        $ics = $this->readFixture('weekly_exdate.ics');
        $events = $this->parse($ics);
        $e = $events[0];

        $starts = $this->instancesLocalStarts($e, '2026-05-01', '2026-05-31');

        $this->assertSame(
            ['2026-05-01 19:00', '2026-05-08 19:00', '2026-05-22 19:00'],
            $starts
        );
    }

    public function test_parse_keeps_cancelled_override_event(): void
    {
        $ics = $this->readFixture('cancelled_override.ics');
        $events = ics_parse_events($ics);
        $this->assertIsArray($events);

        $this->assertGreaterThanOrEqual(2, count($events), 'Expected base + cancelled override events to be retained');
    }

    public function test_expand_daily_respects_cancelled_override_as_exdate(): void
    {
    	$ics = $this->readFixture('cancelled_override.ics');
    	$events = ics_parse_events($ics);
    	$events = $this->applyOverrides($events);
    	
    	// find the base (now has exdate applied)
    	$base = null;
    	foreach ($events as $ev) {
    		if (!empty($ev['rrule']) && empty($ev['recurrence_id'])) { $base = $ev; break; }
    	}
    	$this->assertIsArray($base);
    	
    	$starts = $this->instancesLocalStarts($base, '2026-05-10', '2026-05-14');
    	
    	$this->assertSame(
    			['2026-05-10 09:00', '2026-05-11 09:00', '2026-05-13 09:00', '2026-05-14 09:00'],
    			$starts
    			);
    	
    }

    public function test_expand_respects_moved_override(): void
    {
    	$ics = $this->readFixture('moved_override.ics');
    	$events = ics_parse_events($ics);
    	$events = $this->applyOverrides($events);
    	
    	$base = null;
    	$override = null;
    	
    	foreach ($events as $ev) {
    		if (!empty($ev['rrule']) && empty($ev['recurrence_id'])) $base = $ev;
    		if (!empty($ev['recurrence_id']) && empty($ev['rrule'])) $override = $ev;
    	}
    	
    	$this->assertIsArray($base);
    	$this->assertIsArray($override);
    	
    	$baseStarts = $this->instancesLocalStarts($base, '2026-05-06', '2026-05-31');
    	$this->assertSame(
    			['2026-05-06 18:00', '2026-05-20 18:00', '2026-05-27 18:00'],
    			$baseStarts
    			);
    	
    	$overrideStarts = $this->instancesLocalStarts($override, '2026-05-06', '2026-05-31');
    	$this->assertSame(['2026-05-14 20:00'], $overrideStarts);
    	
    }

    public function test_parse_maintains_timezone_metadata_if_present(): void
    {
        $ics = $this->readFixture('weekly_exdate.ics');
        $events = $this->parse($ics);
        $e = $events[0];

        $hasTz = false;
        if (!empty($e['tzid'])) $hasTz = true;
        if (!empty($e['dtstart_params']['TZID'])) $hasTz = true;
        if (!empty($e['dtstart_params']['tzid'])) $hasTz = true;

        $this->assertTrue($hasTz, 'Expected DTSTART TZID metadata to be preserved for consistent expansion');
    }

    private function applyOverrides(array $events): array
    {
    	// Group by UID
    	$byUid = [];
    	foreach ($events as $e) {
    		$uid = $e['uid'] ?? '';
    		if ($uid === '') continue;
    		$byUid[$uid][] = $e;
    	}
    	
    	$out = [];
    	foreach ($byUid as $uid => $group) {
    		$base = null;
    		$overrides = [];
    		
    		foreach ($group as $e) {
    			if (!empty($e['rrule']) && empty($e['recurrence_id'])) $base = $e;
    			if (!empty($e['recurrence_id'])) $overrides[] = $e;
    		}
    		
    		if (!$base) {
    			// No base series, just keep all
    			foreach ($group as $e) $out[] = $e;
    			continue;
    		}
    		
    		$base['exdate'] = $base['exdate'] ?? [];
    		$base['exdate_params'] = $base['exdate_params'] ?? [];
    		
    		foreach ($overrides as $ovr) {
    			$rid = $ovr['recurrence_id'] ?? null;
    			if ($rid) {
    				// exclude the original slot from the base series
    				$base['exdate'][] = $rid;
    				// if exdate params missing, borrow TZID from recurrence-id
    				if (empty($base['exdate_params']) && !empty($ovr['recurrence_id_params'])) {
    					$base['exdate_params'] = $ovr['recurrence_id_params'];
    				}
    			}
    			
    			// If it's CANCELLED, we don't emit the override at all.
    			$status = strtoupper($ovr['status'] ?? '');
    			if ($status === 'CANCELLED') continue;
    			
    			// If it's moved/modified, emit the override as a standalone event (no RRULE)
    			$ovr['rrule'] = null;
    			$out[] = $ovr;
    		}
    		
    		$out[] = $base;
    	}
    	
    	return $out;
    }
    
}
