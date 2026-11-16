<?php

class ImageKitStorage
{
    private $privateKey;

    public function __construct()
    {
        $this->privateKey = trim((string) getenv('IMAGEKIT_PRIVATE_KEY'));
    }

    public function is_configured()
    {
        return $this->privateKey !== '';
    }

    public function upload_file($filePath, $fileName)
    {
        if (!$this->is_configured()) {
            throw new RuntimeException('IMAGEKIT_PRIVATE_KEY is not configured.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for ImageKit uploads.');
        }
        if (!is_file($filePath)) {
            throw new RuntimeException('The staged vehicle image could not be found.');
        }

        $folder = trim((string) (getenv('IMAGEKIT_UPLOAD_FOLDER') ?: '/caren/cars'));
        if ($folder !== '' && $folder[0] !== '/') {
            $folder = '/' . $folder;
        }

        $mimeType = function_exists('mime_content_type')
            ? mime_content_type($filePath)
            : 'application/octet-stream';
        $response = $this->request(
            'https://upload.imagekit.io/api/v1/files/upload',
            [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => [
                    'file' => new CURLFile($filePath, $mimeType ?: 'application/octet-stream', $fileName),
                    'fileName' => $fileName,
                    'folder' => $folder,
                    'useUniqueFileName' => 'true',
                ],
            ],
            'upload'
        );

        if (!is_string($response['url'] ?? null) || !is_string($response['fileId'] ?? null)) {
            throw new RuntimeException('ImageKit returned an incomplete upload response.');
        }

        return [
            'url' => $response['url'],
            'file_id' => $response['fileId'],
        ];
    }

    public function delete_file($fileId)
    {
        if (!$this->is_configured()) {
            throw new RuntimeException('IMAGEKIT_PRIVATE_KEY is not configured.');
        }

        $this->request(
            'https://api.imagekit.io/v1/files/' . rawurlencode((string) $fileId),
            [CURLOPT_CUSTOMREQUEST => 'DELETE'],
            'delete'
        );
    }

    private function request($url, array $options, $operation)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for ImageKit requests.');
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, array_replace([
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->privateKey . ':',
        ], $options));

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('ImageKit ' . $operation . ' request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            $response = json_decode($body, true);
            $message = is_array($response) && is_string($response['message'] ?? null)
                ? $response['message']
                : 'HTTP ' . $status;
            throw new RuntimeException('ImageKit ' . $operation . ' request failed: ' . $message);
        }
        if ($body === '') {
            return [];
        }

        $response = json_decode($body, true);
        if (!is_array($response)) {
            throw new RuntimeException('ImageKit returned an invalid response.');
        }

        return $response;
    }
}
