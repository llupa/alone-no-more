<?php

declare(strict_types=1);

namespace App\Tests\World\Soap;

use App\World\Exception\WorldGatewayException;
use App\World\Soap\SoapCommandExecutor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SoapCommandExecutorTest extends TestCase
{
    /**
     * Verbatim response from the running server for `playerbots inspect Azalerina position`.
     */
    private const REAL_RESULT = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="urn:AC">
        <SOAP-ENV:Body><ns1:executeCommandResponse><result>Azalerina: 1676.71 1678.31 121.67 0 2.70526 |Tirisfal Glades|&#xD;
        </result></ns1:executeCommandResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>
        XML;

    private const REAL_FAULT = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">
        <SOAP-ENV:Body><SOAP-ENV:Fault><faultcode>SOAP-ENV:Client</faultcode>
        <faultstring>There is no such command</faultstring></SOAP-ENV:Fault></SOAP-ENV:Body></SOAP-ENV:Envelope>
        XML;

    /**
     * Obviously-fake credentials, on purpose.
     *
     * The first version of this used the real GM account and password from the development
     * server, which is a working console login for anyone who sets the project up by following
     * its own runbook — and it would have gone into a public repository as test data, where
     * nobody looks for secrets.
     */
    private function executor(MockHttpClient $client): SoapCommandExecutor
    {
        return new SoapCommandExecutor($client, 'http://127.0.0.1:7878/', 'test-gm', 'not-a-real-password');
    }

    public function testItReturnsTheCommandResult(): void
    {
        $result = $this->executor(new MockHttpClient(new MockResponse(self::REAL_RESULT)))
            ->execute('playerbots inspect Azalerina position');

        self::assertSame('Azalerina: 1676.71 1678.31 121.67 0 2.70526 |Tirisfal Glades|', $result);
    }

    public function testItStripsTheChatFormLeadingDot(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertStringContainsString('<command>server info</command>', $options['body']);

            return new MockResponse(self::REAL_RESULT);
        });

        $this->executor($client)->execute('.server info');
    }

    public function testItSendsBasicAuth(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertContains('Authorization: Basic '.base64_encode('test-gm:not-a-real-password'), $options['headers']);

            return new MockResponse(self::REAL_RESULT);
        });

        $this->executor($client)->execute('server info');
    }

    public function testItEscapesCommandsSoChatTextCannotBreakTheEnvelope(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertStringNotContainsString('<evil>', $options['body']);
            self::assertStringContainsString('&lt;evil&gt;', $options['body']);

            return new MockResponse(self::REAL_RESULT);
        });

        $this->executor($client)->execute('say <evil>&</evil>');
    }

    public function testItTranslatesAFaultIntoAnException(): void
    {
        $this->expectException(WorldGatewayException::class);
        $this->expectExceptionMessage('There is no such command');

        $this->executor(new MockHttpClient(new MockResponse(self::REAL_FAULT)))->execute('bogus');
    }

    public function testItReportsRejectedCredentials(): void
    {
        $this->expectException(WorldGatewayException::class);
        $this->expectExceptionMessage('credentials');

        $this->executor(new MockHttpClient(new MockResponse('', ['http_code' => 401])))
            ->execute('server info');
    }

    /**
     * The first wall a fresh clone hits. Reporting it as rejected credentials would send
     * someone hunting a gmlevel on an account they never created.
     */
    public function testItSaysWhatToDoWhenNoGmAccountIsConfigured(): void
    {
        $client = new MockHttpClient();

        $this->expectException(WorldGatewayException::class);
        $this->expectExceptionMessageMatches('/No GM account configured.+\.env\.local/s');

        (new SoapCommandExecutor($client, 'http://127.0.0.1:7878/', '', ''))->execute('server info');
    }

    public function testItRejectsAnEmptyCommand(): void
    {
        $this->expectException(WorldGatewayException::class);

        $this->executor(new MockHttpClient())->execute('   ');
    }

    public function testItFailsLoudlyWhenTheServerIsDown(): void
    {
        $this->expectException(WorldGatewayException::class);

        $client = new MockHttpClient(static function (): MockResponse {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('Connection refused');
        });

        $this->executor($client)->execute('server info');
    }
}
