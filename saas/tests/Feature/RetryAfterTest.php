<?php

namespace Tests\Feature;

use App\Services\Ai\RetryAfter;
use PHPUnit\Framework\TestCase;

class RetryAfterTest extends TestCase
{
    public function test_reads_the_header_message_and_reset_hints_in_order(): void
    {
        $this->assertSame(12, RetryAfter::seconds('12', '1m', 'try again in 3s'));
        $this->assertSame(7, RetryAfter::seconds(null, '1m', 'Please try again in 6.7s.'));
        $this->assertSame(63, RetryAfter::seconds('', '1m2.5s'));
        $this->assertSame(1, RetryAfter::seconds(null, null, 'Please try again in 450ms'));
        $this->assertNull(RetryAfter::seconds(null, null, 'Rate limit reached.'));
    }
}
