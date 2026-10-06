<?php

use App\Libraries\IgsnPublisher;
use App\Libraries\IgsnWebhook;
use App\Models\IgsnPublication;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Ppci\Libraries\PpciException;
use Ppci\Models\Log;

/**
 * Authentication and reading of the calls of the IGSN registry webhook
 *
 * @internal
 */
final class IgsnWebhookTest extends CIUnitTestCase
{
    private string $secret = "a-long-random-shared-secret";
    private string $body = '{"batchId":"0199b3f1-2a4c-7d10-8b6e-5f3a9c1d2e40","partnerId":"42","id":"0199b3f1-2a4d-7f22-9c01-6e4b8d2f3a51","status":"published","igsn":"01K6SZ4V3M8Q2R7T5W9X0Y1Z2A","publishingError":null}';
    private int $now = 1759670400;

    private function sign(string $timestamp, string $body, string $secret): string
    {
        return "sha256=" . hash_hmac("sha256", $timestamp . "." . $body, $secret);
    }

    /**
     * Verify the body signed at the given time with the given secret, as received at $this->now
     *
     * @param integer $time
     * @param string $secret
     * @param string|null $body: signed body, $this->body by default
     * @return boolean
     */
    private function verifySigned(int $time, string $secret, ?string $body = null): bool
    {
        $ts = (string) $time;
        return IgsnWebhook::verify($body ?? $this->body, $ts, $this->sign($ts, $this->body, $secret), $this->secret, $this->now);
    }

    public function testDerivesAStableSecretPerCollection(): void
    {
        $secret = IgsnWebhook::secretFor(3, "instance-secret");
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        $this->assertSame($secret, IgsnWebhook::secretFor(3, "instance-secret"));
        $this->assertNotSame($secret, IgsnWebhook::secretFor(4, "instance-secret"));
        $this->assertNotSame($secret, IgsnWebhook::secretFor(3, "another-instance-secret"));
    }

    public function testAcceptsAGenuineCall(): void
    {
        $this->assertTrue($this->verifySigned($this->now, $this->secret));
    }

    public function testRefusesATamperedBody(): void
    {
        $this->assertFalse($this->verifySigned($this->now, $this->secret, str_replace("published", "publish_failed", $this->body)));
    }

    public function testRefusesACallSignedWithAnotherSecret(): void
    {
        $this->assertFalse($this->verifySigned($this->now, "another-secret"));
    }

    public function testRefusesACallWithoutSignature(): void
    {
        $ts = (string) $this->now;
        $this->assertFalse(IgsnWebhook::verify($this->body, $ts, null, $this->secret, $this->now));
        $this->assertFalse(IgsnWebhook::verify($this->body, null, $this->sign($ts, $this->body, $this->secret), $this->secret, $this->now));
    }

    public function testRefusesASignatureWithoutItsPrefix(): void
    {
        $ts = (string) $this->now;
        $raw = hash_hmac("sha256", $ts . "." . $this->body, $this->secret);
        $this->assertFalse(IgsnWebhook::verify($this->body, $ts, $raw, $this->secret, $this->now));
    }

    public function testRefusesAStaleCall(): void
    {
        $this->assertFalse($this->verifySigned($this->now - 301, $this->secret));
        $this->assertFalse($this->verifySigned($this->now + 301, $this->secret));
    }

    public function testReadsTheCallAsABatchOfOneItem(): void
    {
        $this->assertSame([
            "id" => "0199b3f1-2a4c-7d10-8b6e-5f3a9c1d2e40",
            "items" => [[
                "partnerId" => "42",
                "id" => "0199b3f1-2a4d-7f22-9c01-6e4b8d2f3a51",
                "status" => "published",
                "igsn" => "01K6SZ4V3M8Q2R7T5W9X0Y1Z2A",
                "publishingError" => null,
            ]],
        ], IgsnWebhook::parse($this->body));
    }

    public function testRefusesAnUnreadableCall(): void
    {
        $this->expectException(PpciException::class);
        IgsnWebhook::parse("not json");
    }

    public function testRefusesACallWithoutBatchOrPartner(): void
    {
        $this->expectException(PpciException::class);
        IgsnWebhook::parse('{"status":"published"}');
    }

    /**
     * Prepare a webhook whose sample 42 was sent with the collection 3, signed with the secret of that collection
     *
     * @param array|null $row: publication of the sample 42
     * @return array: [webhook, publisher mock, log mock]
     */
    private function webhook(?array $row): array
    {
        config("App")->IGSN_webhookSecret = "instance-secret";
        $publication = $this->createMock(IgsnPublication::class);
        $publication->method("getFromUid")->willReturn($row ?? []);
        $publisher = $this->createMock(IgsnPublisher::class);
        $log = $this->createMock(Log::class);
        Services::injectMock("Log", $log);
        return [new IgsnWebhook($publication, $publisher), $publisher, $log];
    }

    private function signFor(int $collectionId): array
    {
        $ts = (string) time();
        return [$ts, $this->sign($ts, $this->body, IgsnWebhook::secretFor($collectionId, "instance-secret"))];
    }

    protected function tearDown(): void
    {
        config("App")->IGSN_webhookSecret = "";
        parent::tearDown();
    }

    public function testRecordsAGenuineCall(): void
    {
        [$webhook, $publisher, $log] = $this->webhook(["uid" => 42, "collection_id" => 3]);
        $publisher->expects($this->once())->method("recordOutcome")
            ->with(IgsnWebhook::parse($this->body), IgsnPublisher::WEBHOOK_LOGIN)
            ->willReturn([42 => IgsnPublication::APPLIED]);
        $log->expects($this->once())->method("setLog");
        [$ts, $signature] = $this->signFor(3);
        $this->assertSame(204, $webhook->receive($this->body, $ts, $signature));
    }

    public function testAsksToRetryACallForABatchNotYetRecorded(): void
    {
        [$webhook, $publisher] = $this->webhook(["uid" => 42, "collection_id" => 3]);
        $publisher->method("recordOutcome")->willReturn([42 => IgsnPublication::PENDING]);
        [$ts, $signature] = $this->signFor(3);
        $this->assertSame(503, $webhook->receive($this->body, $ts, $signature));
    }

    public function testChecksTheSignatureWithTheCollectionOfTheBatch(): void
    {
        [$webhook, $publisher, $log] = $this->webhook(["uid" => 42, "collection_id" => 3]);
        $publisher->expects($this->never())->method("recordOutcome");
        $log->expects($this->never())->method("setLog");
        [$ts, $signature] = $this->signFor(4);
        $this->assertSame(401, $webhook->receive($this->body, $ts, $signature));
    }

    public function testAnswersAnUnknownSampleAsABadSignature(): void
    {
        [$webhook, $publisher, $log] = $this->webhook(null);
        $publisher->expects($this->never())->method("recordOutcome");
        $log->expects($this->never())->method("setLog");
        [$ts, $signature] = $this->signFor(3);
        $this->assertSame(401, $webhook->receive($this->body, $ts, $signature));
    }

    public function testRefusesEveryCallWithoutInstanceSecret(): void
    {
        [$webhook, $publisher] = $this->webhook(["uid" => 42, "collection_id" => 3]);
        config("App")->IGSN_webhookSecret = "";
        $publisher->expects($this->never())->method("recordOutcome");
        $ts = (string) time();
        $this->assertSame(401, $webhook->receive($this->body, $ts, $this->sign($ts, $this->body, IgsnWebhook::secretFor(3, ""))));
    }

    public function testRefusesAnUnreadableBody(): void
    {
        [$webhook] = $this->webhook(["uid" => 42, "collection_id" => 3]);
        $this->assertSame(400, $webhook->receive("not json", (string) time(), "sha256=00"));
    }
}
