<?php

declare(strict_types=1);

namespace App\Support;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/**
 * Thin wrapper over PHPMailer. Uses SMTP when SMTP_HOST is configured,
 * otherwise PHP's mail() — which works on typical shared hosting for domains
 * hosted on the same account.
 */
final class Mailer
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param list<string> $to
     */
    public function send(array $to, string $subject, string $html): void
    {
        if ($to === []) {
            throw new RuntimeException('No recipients given.');
        }

        $mail = new PHPMailer(true);

        try {
            if ($this->config->smtpHost !== '') {
                $mail->isSMTP();
                $mail->Host = $this->config->smtpHost;
                $mail->Port = $this->config->smtpPort;
                $mail->SMTPAuth = $this->config->smtpUser !== '';
                $mail->Username = $this->config->smtpUser;
                $mail->Password = $this->config->smtpPassword;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $from = $this->config->mailFrom !== '' ? $this->config->mailFrom : 'rolo@example.com';
            $mail->setFrom($from, 'Rolo CRM');

            foreach ($to as $address) {
                $mail->addAddress($address);
            }

            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html);

            $mail->send();
        } catch (MailException $e) {
            throw new RuntimeException('Mail send failed: ' . $e->getMessage(), previous: $e);
        }
    }
}
