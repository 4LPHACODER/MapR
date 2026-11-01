<?php

namespace Tests\Unit;

use App\Support\SourceName;
use PHPUnit\Framework\TestCase;

class SourceNameTest extends TestCase
{
    public function test_name_error_token_is_not_displayed(): void
    {
        $part = SourceName::part('#NAME?');

        $this->assertNull($part['value']);
        $this->assertTrue($part['needs_review']);
        $this->assertSame('Ana (source value requires review)', SourceName::display('Ana', null, '#NAME?'));
    }

    public function test_second_member_indexes_are_independent_of_household_size(): void
    {
        $this->assertSame('Maria Santos', SourceName::display('Maria', null, 'Santos'));
    }
}
