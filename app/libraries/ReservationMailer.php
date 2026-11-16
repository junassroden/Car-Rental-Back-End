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
        $brevoApiKey = trim((string) getenv('BREVO_API_KEY'));
        $fromAddress = trim((string) getenv('MAIL_FROM_ADDRESS'));
        $fromName = trim((string) (getenv('MAIL_FROM_NAME') ?: 'DriveRent'));

        if ($fromAddress === '') {
            throw new RuntimeException('MAIL_FROM_ADDRESS must be configured.');
        }
        if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL) || !filter_var($reservation['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid sender and renter email address are required.');
        }

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

        if ($brevoApiKey !== '') {
            $this->send_with_brevo($brevoApiKey, $fromAddress, $fromName, $reservation, $htmlBody, $textBody, $attachmentName);
            return;
        }

        $host = trim((string) getenv('SMTP_HOST'));
        $username = trim((string) getenv('SMTP_USERNAME'));
        $password = (string) getenv('SMTP_PASSWORD');
        $encryption = strtolower(trim((string) (getenv('SMTP_ENCRYPTION') ?: 'tls')));
        if ($host === '' || $username === '' || $password === '') {
            throw new RuntimeException('Configure BREVO_API_KEY or SMTP_HOST, SMTP_USERNAME, and SMTP_PASSWORD.');
        }
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
        $mail->addAddress($reservation['email'], $reservation['full_name'] ?: $reservation['username']);
        $mail->isHTML(true);
        $mail->Subject = 'Your DriveRent rental agreement and pickup details';
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;
        $mail->addStringAttachment($reservation['contract_text'], $attachmentName, 'base64', 'text/plain; charset=UTF-8');
        $mail->send();
    }

    private function send_with_brevo($apiKey, $fromAddress, $fromName, array $reservation, $htmlBody, $textBody, $attachmentName)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required to send email through Brevo.');
        }

        $payload = [
            'sender' => ['name' => $fromName, 'email' => $fromAddress],
            'to' => [[
                'email' => $reservation['email'],
                'name' => $reservation['full_name'] ?: $reservation['username'],
            ]],
            'subject' => 'Your DriveRent rental agreement and pickup details',
            'htmlContent' => $htmlBody,
            'textContent' => $textBody,
            'attachment' => [[
                'name' => $attachmentName,
                'content' => base64_encode((string) $reservation['contract_text']),
            ]],
        ];
        $body = json_encode($payload);
        if ($body === false) {
            throw new RuntimeException('The agreement email could not be encoded for delivery.');
        }

        $curl = curl_init('https://api.brevo.com/v3/smtp/email');
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
            throw new RuntimeException('Brevo email request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            $result = json_decode($response, true);
            $message = is_array($result) && is_string($result['message'] ?? null)
                ? $result['message']
                : 'Brevo rejected the email request.';
            throw new RuntimeException($message);
        }
    }
}
