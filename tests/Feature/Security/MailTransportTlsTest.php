<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\TestCase;

/**
 * RT-MAIL-TLS-2026-10: the SMTP mailer must verify the server certificate and host name.
 *
 * `config/mail.php` used to carry `stream.ssl` with `verify_peer=false`, `verify_peer_name=false` and
 * `allow_self_signed=true`. Laravel 12 does not pass `stream` to the transport, so that block was misleading rather than
 * effective; the setting that does reach Symfony is the top-level `verify_peer` option. Both are checked here, plus the
 * stream options of the built transport. The transport is built but never connected, so no mail is sent.
 */
class MailTransportTlsTest extends TestCase
{
    #[Test]
    public function smtp_config_does_not_weaken_tls_verification(): void
    {
        $smtp = config('mail.mailers.smtp');
        $ssl = $smtp['stream']['ssl'] ?? [];

        $this->assertArrayNotHasKey('verify_peer', $smtp);
        $this->assertArrayNotHasKey('peer_fingerprint', $smtp);
        $this->assertNotSame(false, $ssl['verify_peer'] ?? true);
        $this->assertNotSame(false, $ssl['verify_peer_name'] ?? true);
        $this->assertNotSame(true, $ssl['allow_self_signed'] ?? false);
    }

    #[Test]
    public function built_smtp_transport_keeps_verification_defaults(): void
    {
        $ssl = $this->builtTransportSslOptions();

        $this->assertNotSame(false, $ssl['verify_peer'] ?? true);
        $this->assertNotSame(false, $ssl['verify_peer_name'] ?? true);
        $this->assertNotSame(true, $ssl['allow_self_signed'] ?? false);
    }

    #[Test]
    public function transport_check_detects_disabled_verification(): void
    {
        // Control: the option that does disable verification is visible on the built transport.
        $ssl = $this->builtTransportSslOptions(['mail.mailers.smtp.verify_peer' => false]);

        $this->assertFalse($ssl['verify_peer']);
        $this->assertFalse($ssl['verify_peer_name']);
    }

    private function builtTransportSslOptions(array $overrides = []): array
    {
        config(array_merge([
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.encryption' => 'tls',
        ], $overrides));

        $transport = Mail::mailer('smtp')->getSymfonyTransport();
        $this->assertInstanceOf(EsmtpTransport::class, $transport);

        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);

        return $stream->getStreamOptions()['ssl'] ?? [];
    }
}
