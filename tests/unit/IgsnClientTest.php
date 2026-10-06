<?php

use App\Libraries\IgsnApiException;
use App\Libraries\IgsnClient;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCURLRequest;
use Config\App;

/**
 * Calls to the batch API of the IGSN registry
 *
 * @internal
 */
final class IgsnClientTest extends CIUnitTestCase
{
    private MockCURLRequest $http;
    private IgsnClient $client;
    private array $batch = [
        "id" => "0199b3f1-2a4c-7d10-8b6e-5f3a9c1d2e40",
        "items" => [[
            "partnerId" => "42",
            "id" => "0199b3f1-2a4d-7f22-9c01-6e4b8d2f3a51",
            "status" => "publishing",
            "igsn" => null,
            "publishingError" => null,
        ]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new MockCURLRequest(
            new App(),
            new URI("https://igsn.test/api/service/"),
            new Response(new App()),
            ["http_errors" => false]
        );
        $this->client = new IgsnClient($this->http);
    }

    private function answer(int $status, string $body): void
    {
        $this->http->setOutput("HTTP/1.1 $status Status\r\nContent-Type: application/json\r\n\r\n" . $body);
    }

    public function testSubmitsABatchWithTheApiKey(): void
    {
        $this->answer(202, json_encode($this->batch));
        $items = [["partnerId" => "42", "sample" => ["schemaVersion" => "0.11.0"]]];
        $result = $this->client->submitBatch("the-key", $items);
        $this->assertSame($this->batch, $result);
        $this->assertSame("https://igsn.test/api/service/samples/batch", $this->http->curl_options[CURLOPT_URL]);
        $this->assertContains("Authorization: Bearer the-key", $this->http->curl_options[CURLOPT_HTTPHEADER]);
        $this->assertSame(["items" => $items], json_decode($this->http->curl_options[CURLOPT_POSTFIELDS], true));
    }

    public function testSendsTheWebhookWithTheBatch(): void
    {
        $this->answer(202, json_encode($this->batch));
        $webhook = ["url" => "https://collec.example.org/igsnWebhook", "secret" => str_repeat("s", 64)];
        $this->client->submitBatch("the-key", [], false, $webhook);
        $this->assertSame($webhook, json_decode($this->http->curl_options[CURLOPT_POSTFIELDS], true)["webhook"]);
    }

    public function testConfirmsTheDuplicates(): void
    {
        $this->answer(202, json_encode($this->batch));
        $this->client->submitBatch("the-key", [], true);
        $this->assertSame("https://igsn.test/api/service/samples/batch?confirmDuplicates=true", $this->http->curl_options[CURLOPT_URL]);
    }

    public function testReadsABatch(): void
    {
        $this->answer(200, json_encode($this->batch));
        $this->assertSame($this->batch, $this->client->getBatch("the-key", $this->batch["id"]));
        $this->assertSame("https://igsn.test/api/service/batches/" . $this->batch["id"], $this->http->curl_options[CURLOPT_URL]);
        $this->assertContains("Authorization: Bearer the-key", $this->http->curl_options[CURLOPT_HTTPHEADER]);
    }

    public function testRaisesTheStatusAndBodyOfARefusal(): void
    {
        $body = ["error" => "Invalid sample", "issues" => [["path" => "items.0.sample.production", "code" => "too_small"]]];
        $this->answer(422, json_encode($body));
        try {
            $this->client->submitBatch("the-key", []);
            $this->fail("No exception raised");
        } catch (IgsnApiException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame($body, $e->body);
        }
    }

    public function testRaisesARefusalWithoutJsonBody(): void
    {
        $this->http->setOutput("HTTP/1.1 503 Service Unavailable\r\nContent-Type: text/html\r\n\r\n<html>down</html>");
        try {
            $this->client->getBatch("the-key", $this->batch["id"]);
            $this->fail("No exception raised");
        } catch (IgsnApiException $e) {
            $this->assertSame(503, $e->status);
            $this->assertSame([], $e->body);
        }
    }
}
