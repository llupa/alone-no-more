<?php

declare(strict_types=1);

namespace App\World\Soap;

use App\World\Exception\WorldGatewayException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Runs GM console commands on AzerothCore over its SOAP interface (worldserver.conf: SOAP.Enabled).
 *
 * Commands are written without the leading dot that the in-game chat form uses:
 * "server info", not ".server info". The account needs a GM level high enough for the
 * command, and SOAP binds to 127.0.0.1 by default.
 *
 * We build the envelope by hand over HttpClient rather than using ext-soap: AzerothCore
 * publishes no WSDL, the payload is one string in and one string out, and HttpClient gives
 * us MockHttpClient for tests.
 */
final readonly class SoapCommandExecutor
{
    private const ENVELOPE = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="urn:AC">
          <SOAP-ENV:Body><ns1:executeCommand><command>%s</command></ns1:executeCommand></SOAP-ENV:Body>
        </SOAP-ENV:Envelope>
        XML;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'ANM_SOAP_ENDPOINT')]
        private string $endpoint,
        #[Autowire(env: 'ANM_SOAP_USERNAME')]
        private string $username,
        #[Autowire(env: 'ANM_SOAP_PASSWORD')]
        private string $password,
    ) {
    }

    /**
     * @throws WorldGatewayException when the server is unreachable, rejects the credentials,
     *                               or answers with a SOAP fault (which is how it reports an
     *                               unknown command or a bad argument)
     */
    public function execute(string $command): string
    {
        $command = trim($command);

        if ('' === $command) {
            throw new WorldGatewayException('Refusing to send an empty command.');
        }

        // Nothing configured is a different problem from the wrong password, and deserves a
        // different answer: a 401 sends someone hunting a gmlevel they never set in the first
        // place. This is the first wall a fresh clone hits.
        if ('' === trim($this->username)) {
            throw new WorldGatewayException('No GM account configured. Create one on your server ("account create <name> <password>", then "account set gmlevel <name> 3 -1") and put ANM_SOAP_USERNAME and ANM_SOAP_PASSWORD in .env.local. See the README.');
        }

        // The console takes commands without the chat-form leading dot.
        $command = ltrim($command, '.');

        try {
            $response = $this->httpClient->request('POST', $this->endpoint, [
                'auth_basic' => [$this->username, $this->password],
                'headers' => ['Content-Type' => 'application/xml'],
                'body' => \sprintf(self::ENVELOPE, htmlspecialchars($command, \ENT_XML1)),
            ]);

            $body = $response->getContent(false);
            $status = $response->getStatusCode();
        } catch (HttpExceptionInterface $e) {
            throw new WorldGatewayException(\sprintf('SOAP request failed for command "%s": %s', $command, $e->getMessage()), previous: $e);
        }

        if (401 === $status) {
            throw new WorldGatewayException('SOAP rejected the credentials (check the GM account and its gmlevel).');
        }

        return self::parse($body, $command, $status);
    }

    private static function parse(string $body, string $command, int $status): string
    {
        if (preg_match('#<faultstring>(.*?)</faultstring>#s', $body, $m)) {
            throw new WorldGatewayException(\sprintf('Server rejected command "%s": %s', $command, self::clean($m[1])));
        }

        if (preg_match('#<result[^>]*>(.*?)</result>#s', $body, $m)) {
            return self::clean($m[1]);
        }

        if ($status >= 400) {
            throw new WorldGatewayException(\sprintf('SOAP returned HTTP %d for command "%s".', $status, $command));
        }

        throw new WorldGatewayException(\sprintf('Could not find a result in the SOAP response to "%s".', $command));
    }

    /**
     * The server pads multi-line output with literal CR entities and wraps lines; callers
     * want the text, not the transport's punctuation.
     */
    private static function clean(string $raw): string
    {
        $text = html_entity_decode($raw, \ENT_QUOTES | \ENT_XML1, 'UTF-8');

        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }
}
