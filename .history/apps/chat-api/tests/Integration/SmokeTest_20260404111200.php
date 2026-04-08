<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;

final class SmokeTest extends TestCase
{
    public function test_database_connection(): void
    {
        $user = $this->createUser(['display_name' => 'Test User']);
        $this->assertGreaterThan(0, $user['id']);
        $this->assertSame('Test User', $user['display_name']);
    }
}
