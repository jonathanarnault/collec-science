<?php

namespace App\Libraries;

use CodeIgniter\HTTP\CURLRequest;

/**
 * Client of the batch API of the IGSN registry
 */
class IgsnClient
{
    const API_PATH = "/api/service/";
    const TIMEOUT = 30;
    /**
     * @var CURLRequest
     */
    private CURLRequest $http;

    /**
     * @param CURLRequest|null $http: HTTP client, injected by the tests
     */
    function __construct(?CURLRequest $http = null)
    {
        /**
         * Not shared, so that the base URI and the options of another service are never mixed with ours
         */
        $this->http = $http ?? \Config\Services::curlrequest([
            "baseURI" => rtrim(config("App")->IGSN_url, "/") . self::API_PATH,
            "timeout" => self::TIMEOUT,
            "http_errors" => false,
        ], null, null, false);
    }

    /**
     * Send samples to create or update, in one batch
     *
     * @param string $apiKey: key of the service account of the collection
     * @param array $items: list of ["partnerId" => ..., "sample" => IGSN Core record]
     * @param boolean $confirmDuplicates: publish even if the registry suspects duplicates
     * @param array|null $webhook: ["url" => ..., "secret" => ...] called for each published sample
     * @return array: the batch, with the status of each item
     */
    function submitBatch(string $apiKey, array $items, bool $confirmDuplicates = false, ?array $webhook = null): array
    {
        $body = ["items" => $items];
        if (!empty($webhook)) {
            $body["webhook"] = $webhook;
        }
        $options = ["json" => $body];
        if ($confirmDuplicates) {
            $options["query"] = ["confirmDuplicates" => "true"];
        }
        return $this->send("POST", "samples/batch", $apiKey, $options);
    }

    /**
     * Read the current status of each item of a batch
     *
     * @param string $apiKey: key of the service account which sent the batch
     * @param string $batchId
     * @return array
     */
    function getBatch(string $apiKey, string $batchId): array
    {
        return $this->send("GET", "batches/" . rawurlencode($batchId), $apiKey);
    }

    /**
     * Send a request to the registry.
     * The path is relative, without leading slash, so that it is appended to /api/service/
     *
     * @param string $method
     * @param string $path
     * @param string $apiKey
     * @param array $options
     * @return array: decoded body of the answer
     */
    private function send(string $method, string $path, string $apiKey, array $options = []): array
    {
        $options["headers"] = [
            "Authorization" => "Bearer " . $apiKey,
            "Accept" => "application/json",
        ];
        $response = $this->http->request($method, $path, $options);
        $body = json_decode($response->getBody() ?? "", true);
        $body = is_array($body) ? $body : [];
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new IgsnApiException($status, $body);
        }
        return $body;
    }
}
