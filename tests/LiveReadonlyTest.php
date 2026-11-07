<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require dirname(__DIR__) . '/tools/live_readonly_check.php';

final class LiveReadonlyTest extends TestCase
{
    public function testWriteCommandsAreRefusedBeforeAnyCall(): void
    {
        $spy = new class {
            public int $calls = 0;

            public function testApi(): array
            {
                $this->calls++;

                return ['ok' => true];
            }
        };

        foreach (['Purchase', 'Extend', 'TP_CreateOrder', 'ModifyNS', 'GetAuthCode'] as $command) {
            try {
                enom_live_dispatch($command, $spy, null);
                $this->fail($command . ' must be refused');
            } catch (EnomLiveRefused) {
            }
        }
        $this->assertSame(0, $spy->calls);
        $this->assertTrue(enom_live_command_allowed('GetBalance'));
        $this->assertTrue(enom_live_command_allowed('Check'));
        $this->assertTrue(enom_live_command_allowed('GetDomainInfo'));
    }

    public function testOutputRedactsTheTokenAndUid(): void
    {
        $token = 'live-token-value';
        $uid = 'live-uid-value';
        $row = [
            'balance_ok' => false,
            'check' => 'error',
            'expiry' => '',
            'ns_count' => 0,
            'ns_hosts' => '',
            'registered_at_kept' => false,
            'error' => enom_live_redact('Err1 saw ' . $token . ' pw=' . $token . ' uid=' . $uid, $uid, $token),
        ];
        $text = enom_live_format($row);

        $this->assertStringNotContainsString($token, $text);
        $this->assertStringNotContainsString($uid, $text);
        $this->assertStringContainsString('balance_ok=false', $text);
        $this->assertStringContainsString('check=error', $text);
        $this->assertStringContainsString('saw [token]', $text);
        $this->assertStringContainsString('pw=[redacted]', $text);
        $this->assertStringContainsString('uid=[redacted]', $text);
    }
}
