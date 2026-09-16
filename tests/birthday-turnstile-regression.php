<?php
// Regression specification for ASCLA 1.9.48.
// Run inside the project's disposable WordPress/PHPUnit environment.
use ASCLA\Core\Services\Birthdays;

final class BirthdayTurnstileRegressionTest extends WP_UnitTestCase
{
    public function test_birthday_date_validation_and_matching(): void
    {
        $this->assertSame('1990-09-16', Birthdays::normalize('1990-09-16'));
        $this->assertTrue(Birthdays::isToday('1990-09-16', new DateTimeImmutable('2026-09-16')));
        $this->assertFalse(Birthdays::isToday('1990-09-15', new DateTimeImmutable('2026-09-16')));
    }
}
