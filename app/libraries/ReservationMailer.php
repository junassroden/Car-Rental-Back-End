<?php

use PHPMailer\PHPMailer\PHPMailer;

class ReservationMailer
{
    public function send_confirmation_if_ready($reservation_id, $reservationModel)
    {
        $reservation = $reservationModel->find_with_details((int) $reservation_id);
        if (
            !$reservation
            || $reservation['status'] !== 'confirmed'
            || $reservation['payment_status'] !== 'paid'
        ) {
            return 'not_ready';
        }
        if (!empty($reservation['agreement_email_sent_at'])) {
            return 'already_sent';
        }

        try {
            $this->send_confirmation($reservation);
            if (!$reservationModel->mark_agreement_email_sent((int) $reservation['id'])) {
                throw new RuntimeException('The agreement email send could not be recorded.');
            }

            return 'sent';
        } catch (Throwable $exception) {
            error_log('Reservation agreement email failed for reservation #' . (int) $reservation['id'] . ': ' . $exception->getMessage());
            return 'failed';
        }
    }

    private function send_confirmation(array $reservation)
    {
        $name = htmlspecialchars(
            (string) ($reservation['full_name'] ?: $reservation['username']),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $vehicle = htmlspecialchars(trim($reservation['make'] . ' ' . $reservation['model']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $pickup = htmlspecialchars((new DateTimeImmutable($reservation['start_date']))->format('F j, Y'), ENT_QUOTES, 'UTF-8');
        $return = htmlspecialchars((new DateTimeImmutable($reservation['end_date']))->format('F j, Y'), ENT_QUOTES, 'UTF-8');
        $agreement = htmlspecialchars((string) $reservation['contract_text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $htmlBody = '<p>Hello ' . $name . ',</p>'
            . '<p>Your reservation for <strong>' . $vehicle . '</strong> is confirmed and paid.</p>'
            . '<p><strong>Pickup date:</strong> ' . $pickup . '<br><strong>Return date:</strong> ' . $return . '</p>'
            . '<p>Your accepted rental agreement is included below and attached as a text file.</p>'
            . '<hr><pre style="white-space:pre-wrap;font:14px/1.6 Arial,sans-serif">' . $agreement . '</pre>'
            . '<p>Please bring a valid driver license when you pick up the vehicle.</p>';
        $textBody = 'Hello ' . ($reservation['full_name'] ?: $reservation['username']) . ",\n\n"
            . 'Your reservation for ' . trim($reservation['make'] . ' ' . $reservation['model']) . " is confirmed and paid.\n"
            . 'Pickup date: ' . $pickup . "\nReturn date: " . $return . "\n\n"
            . "Accepted rental agreement:\n\n" . $reservation['contract_text'] . "\n\n"
            . 'Please bring a valid driver license when you pick up the vehicle.';
        $attachmentName = sprintf('rental-agreement-DR-%08d.txt', (int) $reservation['id']);

        $this->send_message(
            $reservation['email'],
            $reservation['full_name'] ?: $reservation['username'],
            'Your DriveRent rental agreement and pickup details',
            $htmlBody,
            $textBody,
            [[
                'name' => $attachmentName,
                'content' => (string) $reservation['contract_text'],
                'type' => 'text/plain; charset=UTF-8',
            ]]
        );
    }

    /** Sends through Brevo first and falls back to configured SMTP if Brevo fails. */
    public function send_message($toEmail, $toName, $subject, $htmlBody, $textBody, array $attachments = [])
    {
        $fromAddress = trim((string) getenv('MAIL_FROM_ADDRESS'));
        $fromName = trim((string) (getenv('MAIL_FROM_NAME') ?: 'DriveRent'));
        $toEmail = trim((string) $toEmail);

        if (
            !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)
            || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)
        ) {
            throw new RuntimeException('A valid sender and recipient email address are required.');
        }

        $failures = [];
        $brevoApiKey = trim((string) getenv('BREVO_API_KEY'));
        if ($brevoApiKey !== '') {
            try {
                $this->send_with_brevo(
                    $brevoApiKey,
                    $fromAddress,
                    $fromName,
                    $toEmail,
                    (string) $toName,
                    (string) $subject,
                    (string) $htmlBody,
                    (string) $textBody,
                    $attachments
                );
                return true;
            } catch (Throwable $exception) {
                $failures[] = 'Brevo: ' . $exception->getMessage();
                error_log('Brevo delivery failed; trying SMTP fallback: ' . $exception->getMessage());
            }
        }

        $host = trim((string) getenv('SMTP_HOST'));
        $username = trim((string) getenv('SMTP_USERNAME'));
        $password = (string) getenv('SMTP_PASSWORD');
        if ($host === '' || $username === '' || $password === '') {
            $failures[] = 'SMTP is not fully configured; set SMTP_HOST, SMTP_USERNAME, and SMTP_PASSWORD.';
        } else {
            try {
                $this->send_with_smtp(
                    $host,
                    $username,
                    $password,
                    $fromAddress,
                    $fromName,
                    $toEmail,
                    (string) $toName,
                    (string) $subject,
                    (string) $htmlBody,
                    (string) $textBody,
                    $attachments
                );
                return true;
            } catch (Throwable $exception) {
                $failures[] = 'SMTP: ' . $exception->getMessage();
            }
        }

        if ($brevoApiKey === '' && !$failures) {
            $failures[] = 'Configure BREVO_API_KEY or SMTP_HOST, SMTP_USERNAME, and SMTP_PASSWORD.';
        }

        throw new RuntimeException(implode(' | ', $failures));
    }

    private function send_with_brevo($apiKey, $fromAddress, $fromName, $toEmail, $toName, $subject, $htmlBody, $textBody, array $attachments)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for Brevo.');
        }

        $payload = [
            'sender' => ['name' => $fromName, 'email' => $fromAddress],
            'to' => [['email' => $toEmail, 'name' => $toName]],
            'subject' => $subject,
            'textContent' => $textBody,
        ];
        if ($htmlBody !== '') {
            $payload['htmlContent'] = $htmlBody;
        }
        if ($attachments) {
            $payload['attachment'] = array_map(static function ($attachment) {
                return [
                    'name' => (string) $attachment['name'],
                    'content' => base64_encode((string) $attachment['content']),
                ];
            }, $attachments);
        }

        $body = json_encode($payload);
        if ($body === false) {
            throw new RuntimeException('The email could not be encoded for delivery.');
        }

        $curl = curl_init('https://api.brevo.com/v3/smtp/email');
        if ($curl === false) {
            throw new RuntimeException('The Brevo request could not be initialized.');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'api-key: ' . $apiKey,
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS => $body,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException('Brevo request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            $result = json_decode($response, true);
            $message = is_array($result) && is_string($result['message'] ?? null)
                ? $result['message']
                : 'Brevo rejected the email request (HTTP ' . $status . ').';
            throw new RuntimeException($message);
        }
    }

    private function send_with_smtp($host, $username, $password, $fromAddress, $fromName, $toEmail, $toName, $subject, $htmlBody, $textBody, array $attachments)
    {
        $encryption = strtolower(trim((string) (getenv('SMTP_ENCRYPTION') ?: 'tls')));
        if (!in_array($encryption, ['tls', 'ssl'], true)) {
            throw new RuntimeException('SMTP_ENCRYPTION must be either tls or ssl.');
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int) (getenv('SMTP_PORT') ?: ($encryption === 'ssl' ? 465 : 587));
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Timeout = 15;
        $mail->setFrom($fromAddress, $fromName);
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;
        $mail->isHTML($htmlBody !== '');
        $mail->Body = $htmlBody !== '' ? $htmlBody : $textBody;
        $mail->AltBody = $textBody;
        foreach ($attachments as $attachment) {
            $mail->addStringAttachment(
                (string) $attachment['content'],
                (string) $attachment['name'],
                'base64',
                (string) ($attachment['type'] ?? 'application/octet-stream')
            );
        }
        $mail->send();
    }
}
