<?php

namespace Tests\Unit;

use App\Services\LeadmakerService;
use PHPUnit\Framework\TestCase;

class LeadmakerServiceTest extends TestCase
{
    public function test_maps_known_countries_to_iana_timezones(): void
    {
        $this->assertSame('America/New_York', LeadmakerService::timezoneForCountry('US'));
        $this->assertSame('Europe/London', LeadmakerService::timezoneForCountry('gb')); // case-insensitive
        $this->assertSame('Europe/Bucharest', LeadmakerService::timezoneForCountry('RO'));
        $this->assertSame('Asia/Tokyo', LeadmakerService::timezoneForCountry(' jp '));   // trims
    }

    public function test_falls_back_to_utc_for_unknown_or_empty(): void
    {
        $this->assertSame('UTC', LeadmakerService::timezoneForCountry(null));
        $this->assertSame('UTC', LeadmakerService::timezoneForCountry(''));
        $this->assertSame('UTC', LeadmakerService::timezoneForCountry('ZZ'));
    }

    public function test_extracts_id_and_token_from_varied_response_shapes(): void
    {
        $this->assertSame('abc', LeadmakerService::extractId(['id' => 'abc']));
        $this->assertSame('42', LeadmakerService::extractId(['id' => 42]));              // ints stringified
        $this->assertSame('nested', LeadmakerService::extractId(['data' => ['id' => 'nested']]));
        $this->assertNull(LeadmakerService::extractId(['nope' => 1]));

        $this->assertSame('tok', LeadmakerService::extractToken(['token' => 'tok']));
        $this->assertSame('tok2', LeadmakerService::extractToken(['campaign' => ['token' => 'tok2']]));
        $this->assertNull(LeadmakerService::extractToken([]));
    }
}
