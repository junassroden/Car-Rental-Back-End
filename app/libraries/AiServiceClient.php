<?php

/**
 * JSON requests from the Back-End to the Django AI service, plus the mapping
 * from a cars row to the maintenance model's inputs. Config comes from
 * app/config/api.php (ai_service_capabilities_url, ai_service_api_key).
 */
class AiServiceClient
{
    /** Endpoint URL next to the configured capabilities URL, e.g. endpoint('fleet/analyze'). */
    public static function endpoint($path)
    {
        return preg_replace('#/capabilities/?$#', '/' . trim($path, '/') . '/', (string) config_item('ai_service_capabilities_url'));
    }

    /** Decoded JSON body, or NULL on transport failure. */
    public static function request($method, $url, $body = null, $timeout = 8)
    {
        if (!function_exists('curl_init') || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $headers = ['Accept: application/json'];
        $token   = trim((string) config_item('ai_service_api_key'));
        if ($token !== '') {
            $headers[] = 'X-AI-Service-Key: ' . $token;
        }

        $handle  = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        if ($method === 'POST') {
            $headers[]                   = 'Content-Type: application/json';
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($handle, $options);

        $response = curl_exec($handle);
        if ($response === false) {
            error_log('AI service request failed: ' . curl_error($handle));
            return null;
        }
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Maintenance-model inputs for one cars row (body type from its category). */
    public static function model_vehicle(array $vehicle, $body_type)
    {
        return [
            'id'           => (int) $vehicle['id'],
            'brand'        => $vehicle['make'] ?? null,
            'model'        => $vehicle['model'] ?? null,
            'vehicle_type' => $body_type,
            'fuel_type'    => $vehicle['fuel_type'] ?? null,
            'make_year'    => isset($vehicle['year']) ? (int) $vehicle['year'] : null,
            'mileage'      => isset($vehicle['current_mileage']) && $vehicle['current_mileage'] !== null ? (int) $vehicle['current_mileage'] : null,
        ];
    }
}
