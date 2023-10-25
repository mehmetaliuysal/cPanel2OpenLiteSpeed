<?php

class CloudflareAPI {
    private $apiKey;
    private $email;

    public function __construct($apiKey, $email) {
        $this->apiKey = $apiKey;
        $this->email = $email;
    }

    private function request($endpoint, $method = "GET", $data = []) {
        $url = "https://api.cloudflare.com/client/v4" . $endpoint;

        $headers = [
            'X-Auth-Email: ' . $this->email,
            'X-Auth-Key: ' . $this->apiKey,
            'Content-Type: application/json'
        ];


        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);

        curl_close($ch);

        return json_decode($result);
    }

    public function getZoneId($domain) {
        $response = $this->request("/zones?name=$domain");
        return $response->result[0]->id ?? null;
    }

    public function getDNSRecords($zoneId, $type = "A", $name = "") {
        $endpoint = "/zones/$zoneId/dns_records?type=$type";

        // Eğer name değeri belirtilmişse endpoint'e ekliyoruz.
        if ($name !== "") {
            $endpoint .= "&name=" . urlencode($name);
        }

        $response = $this->request($endpoint);
        return $response->result ?? [];
    }

    public function upsertDNSARecord($domain, $recordContent) {
        $zoneId = $this->getZoneId($domain);

        if (!$zoneId) {
            return [
                'status' => false,
                'message' => 'Zone ID not found.'
            ];
        }

        $dnsRecords = $this->getDNSRecords($zoneId, 'A');

        $data = [
            "type" => "A",
            "content" => $recordContent,
            "ttl" => 1,
            "proxied" => true
        ];

        $responses = [];

        // Eğer A kaydı bulunmuyorsa yeni bir kayıt oluştur
        if (empty($dnsRecords)) {
            $data["name"] = $domain;  // Eğer kayıt yoksa domain adını kullan
            $endpoint = "/zones/{$zoneId}/dns_records";
            $response = $this->request($endpoint, "POST", $data);

            if ($response && $response->success) {
                $responses[] = [
                    'status' => true,
                    'message' => "DNS record {$domain} successfully created."
                ];
            } else {
                $errorMessage = $response->errors[0]->message ?? 'Unknown error.';
                $responses[] = [
                    'status' => false,
                    'message' => $errorMessage
                ];
            }
        } else {
            // Mevcut A kayıtlarını döngüde işle
            foreach ($dnsRecords as $record) {
                $endpoint = "/zones/{$zoneId}/dns_records/{$record->id}";
                $data["name"] = $record->name;  // Mevcut kayıt adını kullan
                $response = $this->request($endpoint, "PUT", $data);

                if ($response && $response->success) {
                    $responses[] = [
                        'status' => true,
                        'message' => "DNS record {$record->name} successfully updated."
                    ];
                } else {
                    $errorMessage = $response->errors[0]->message ?? 'Unknown error.';
                    $responses[] = [
                        'status' => false,
                        'message' => $errorMessage
                    ];
                }
            }
        }

        return $responses;
    }




    /**
     * Set the SSL mode for a given domain.
     *
     * @param string $domain The domain name.
     * @param string $sslMode The desired SSL mode. Possible values: "off", "flexible", "full", "full_strict".
     *
     * @return array The result of the operation.
     */
    public function setSSLMode($domain, $sslMode) {
        $validModes = ['off', 'flexible', 'full', 'full_strict'];

        if (!in_array($sslMode, $validModes)) {
            return [
                'status' => false,
                'message' => 'Invalid SSL mode provided. Valid modes are: ' . implode(', ', $validModes)
            ];
        }

        $zoneId = $this->getZoneId($domain);
        if (!$zoneId) {
            return [
                'status' => false,
                'message' => 'Zone ID not found.'
            ];
        }

        $endpoint = "/zones/{$zoneId}/settings/ssl";
        $data = ["value" => $sslMode];
        $response = $this->request($endpoint, "PATCH", $data);

        if ($response && $response->success) {
            return [
                'status' => true,
                'message' => 'SSL mode successfully updated.'
            ];
        } else {
            $errorMessage = $response->errors[0]->message ?? 'Unknown error.';
            return [
                'status' => false,
                'message' => $errorMessage
            ];
        }
    }




    public function setApiKey($apiKey) {
        $this->apiKey = $apiKey;
    }

    public function setEmail($email) {
        $this->email = $email;
    }

    public function getApiKey() {
        return $this->apiKey;
    }

    public function getEmail() {
        return $this->email;
    }
}
