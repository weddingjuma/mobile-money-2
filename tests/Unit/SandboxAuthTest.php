<?php

namespace DervisGroup\Pesa\Tests\Unit;


use DervisGroup\Pesa\Mpesa\Exceptions\MpesaException;
use DervisGroup\Pesa\Mpesa\Library\Authenticator;
use DervisGroup\Pesa\Tests\TestCase;

class SandboxAuthTest extends TestCase
{
    /** @test */
    public function it_throws_exception_for_invalid_credentials()
    {
        $this->expectException(MpesaException::class);
        mpesa_request('0799123456', 1, 'test', 'tests');
    }

    /** @test */
    public function it_gets_tokens()
    {
        /** @var Authenticator $authenticator */
        $authenticator = $this->app->make(Authenticator::class);
        $cred = $authenticator->authenticate();
        $this->assertNotEmpty($cred);
        $this->assertEquals(28, strlen($cred));
    }
}
