<?php

use App\Libraries\IgsnApiException;
use App\Libraries\IgsnClient;
use App\Libraries\IgsnPublisher;
use App\Models\CollectionIgsn;
use App\Models\IdentifierType;
use App\Models\IgsnPublication;
use App\Models\ObjectIdentifier;
use App\Models\Sample;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Ppci\Libraries\MessagePpci;
use Ppci\Libraries\PpciException;

/**
 * Sending of the samples to the IGSN registry, and recording of the outcome
 *
 * @internal
 */
final class IgsnPublisherTest extends CIUnitTestCase
{
    /**
     * Identifiers of the samples of the batch, in the order of the items
     */
    private array $identifiers = ["S-1", "S-2"];
    private string $batchId = "0199b3f1-2a4c-7d10-8b6e-5f3a9c1d2e40";
    private string $token = "0199c000-0000-7000-8000-000000000000";
    private string $igsn = "01K6SZ4V3M8Q2R7T5W9X0Y1Z2A";
    /**
     * Publication of the sample 1, sent with the collection 7
     */
    private array $publication1 = ["uid" => 1, "collection_id" => 7, "batch_id" => "0199b3f1-2a4c-7d10-8b6e-5f3a9c1d2e40", "igsn_status" => "publishing"];
    private array $messages = [];
    private $client, $publication, $objectIdentifier, $sample, $identifierType;
    private IgsnPublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->messages = [];
        $message = $this->createMock(MessagePpci::class);
        $message->method("set")->willReturnCallback(function ($value) {
            $this->messages[] = $value;
        });
        Services::injectMock("MessagePpci", $message);
        $this->client = $this->createMock(IgsnClient::class);
        $this->publication = $this->createMock(IgsnPublication::class);
        $this->publication->method("getUUID")->willReturn($this->token);
        $this->objectIdentifier = $this->createMock(ObjectIdentifier::class);
        $this->sample = $this->createMock(Sample::class);
        $this->sample->method("verifyCollectionFromUid")->willReturn(true);
        $this->identifierType = $this->createMock(IdentifierType::class);
        $this->identifierType->method("getIdFromCodeOrCreate")->willReturn(12);
        $this->publisher = $this->publisherWithKey("the-key");
        $_SESSION["login"] = "jdoe";
    }

    protected function tearDown(): void
    {
        unset($_SESSION["login"]);
        parent::tearDown();
    }

    /**
     * Get a publisher whose collection API key is the given one, or raises the given exception
     *
     * @param string|\Throwable $key
     * @return IgsnPublisher
     */
    private function publisherWithKey($key): IgsnPublisher
    {
        $collectionIgsn = $this->createMock(CollectionIgsn::class);
        if ($key instanceof \Throwable) {
            $collectionIgsn->method("getApiKey")->willThrowException($key);
        } else {
            $collectionIgsn->method("getApiKey")->willReturn($key);
        }
        return new IgsnPublisher($this->client, $this->publication, $collectionIgsn, $this->objectIdentifier, $this->sample, $this->identifierType);
    }

    private function item(string $partnerId, string $status, ?string $igsn = null, ?string $error = null): array
    {
        return ["partnerId" => $partnerId, "id" => "0199b3f1-0000-7000-8000-00000000000" . $partnerId, "status" => $status, "igsn" => $igsn, "publishingError" => $error];
    }

    /**
     * Items ready to be sent, for the samples 1 (S-1) and 2 (S-2)
     *
     * @return array
     */
    private function items(): array
    {
        return [
            ["partnerId" => "1", "sample" => ["schemaVersion" => "0.11.0"], "identifier" => "S-1"],
            ["partnerId" => "2", "sample" => ["schemaVersion" => "0.11.0"], "identifier" => "S-2"],
        ];
    }

    public function testNamesTheSampleOfAnInvalidItem(): void
    {
        $e = new IgsnApiException(422, ["error" => "Invalid sample", "issues" => [
            ["path" => "items.1.sample.identification.titles", "code" => "too_small", "message" => "Required"],
        ]]);
        $messages = IgsnPublisher::describeError($e, $this->identifiers);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString("S-2", $messages[0]);
        $this->assertStringContainsString("identification.titles", $messages[0]);
        $this->assertStringContainsString("too_small", $messages[0]);
    }

    public function testExplainsARefusedWebhook(): void
    {
        $e = new IgsnApiException(422, ["error" => "Invalid sample", "issues" => [
            ["path" => "webhook.url", "code" => "invalid_format"],
        ]]);
        $messages = IgsnPublisher::describeError($e, $this->identifiers);
        $this->assertStringContainsString("webhook", $messages[0]);
        $this->assertStringNotContainsString("S-1", $messages[0]);
    }

    public function testReportsAnIssueWithoutPath(): void
    {
        $e = new IgsnApiException(422, ["error" => "Invalid sample", "issues" => [["code" => "sample_locked"]]]);
        $this->assertStringContainsString("sample_locked", IgsnPublisher::describeError($e, $this->identifiers)[0]);
    }

    public function testListsTheSuspectedDuplicates(): void
    {
        $e = new IgsnApiException(409, ["error" => "Suspected duplicate", "reason" => "duplicates", "items" => [[
            "index" => 0,
            "duplicates" => [["id" => "0199", "igsn" => $this->igsn, "name" => "Basalt A"]],
            "batchDuplicates" => [1],
        ]]]);
        $messages = implode("\n", IgsnPublisher::describeError($e, $this->identifiers));
        $this->assertStringContainsString("S-1", $messages);
        $this->assertStringContainsString($this->igsn, $messages);
        $this->assertStringContainsString("Basalt A", $messages);
        $this->assertStringContainsString("S-2", $messages);
    }

    public function testAsksToSendAgainAChangedSample(): void
    {
        $e = new IgsnApiException(409, ["error" => "Sample changed, retry"]);
        $this->assertCount(1, IgsnPublisher::describeError($e, $this->identifiers));
    }

    public function testDescribesTheOtherRefusals(): void
    {
        foreach ([403, 413, 429, 503, 500] as $status) {
            $messages = IgsnPublisher::describeError(new IgsnApiException($status), $this->identifiers);
            $this->assertCount(1, $messages, "status $status");
            $this->assertNotEmpty($messages[0]);
        }
    }

    public function testSendsOnlyTheClaimedSamples(): void
    {
        $this->publication->method("claim")->willReturn([1]);
        $this->client->expects($this->once())->method("submitBatch")
            ->with("the-key", [["partnerId" => "1", "sample" => ["schemaVersion" => "0.11.0"]]], false, null)
            ->willReturn(["id" => $this->batchId, "items" => [$this->item("1", "publishing")]]);
        $this->assertTrue($this->publisher->sendChunk(7, $this->items(), false));
        $this->assertStringContainsString("S-2", implode("\n", $this->messages));
    }

    public function testSendsNothingWhenNoSampleIsClaimed(): void
    {
        $this->publication->method("claim")->willReturn([]);
        $this->client->expects($this->never())->method("submitBatch");
        $this->assertTrue($this->publisher->sendChunk(7, $this->items(), false));
    }

    public function testRecordsTheAcceptedBatchWithItsClaim(): void
    {
        $this->publication->expects($this->once())->method("claim")->with(["1", "2"], 7, $this->token)->willReturn([1, 2]);
        $batch = ["id" => $this->batchId, "items" => [$this->item("1", "publishing"), $this->item("2", "published", $this->igsn)]];
        $this->client->method("submitBatch")->willReturn($batch);
        $this->publication->expects($this->once())->method("recordSent")->with(7, $batch, $this->token);
        $this->objectIdentifier->expects($this->once())->method("writeOrReplace")
            ->with(["uid" => 2, "identifier_type_id" => 12, "object_identifier_value" => $this->igsn]);
        $this->publication->expects($this->never())->method("release");
        $this->assertTrue($this->publisher->sendChunk(7, $this->items(), false));
    }

    public function testDoesNotRewriteTheIgsnOfAnUnchangedSample(): void
    {
        $this->publication->method("claim")->willReturn([1]);
        $items = [["partnerId" => "1", "sample" => ["identification" => ["sampleIdentifier" => $this->igsn]], "identifier" => "S-1"]];
        $this->client->method("submitBatch")->willReturn(["id" => $this->batchId, "items" => [$this->item("1", "published", $this->igsn)]]);
        $this->objectIdentifier->expects($this->never())->method("writeOrReplace");
        $this->assertTrue($this->publisher->sendChunk(7, $items, false));
    }

    public function testReleasesTheSamplesOfARefusedBatch(): void
    {
        $this->publication->method("claim")->willReturn([1, 2]);
        $this->client->method("submitBatch")->willThrowException(new IgsnApiException(422, ["error" => "Invalid sample", "issues" => [["path" => "items.0.sample.production", "code" => "too_small"]]]));
        $this->publication->expects($this->once())->method("release")->with($this->token);
        $this->assertTrue($this->publisher->sendChunk(7, $this->items(), false));
        $this->assertStringContainsString("S-1", implode("\n", $this->messages));
    }

    public function testStopsTheSendingOverTheRateLimit(): void
    {
        $this->publication->method("claim")->willReturn([1, 2]);
        $this->client->method("submitBatch")->willThrowException(new IgsnApiException(429, ["error" => "Too many requests"]));
        $this->publication->expects($this->once())->method("release");
        $this->assertFalse($this->publisher->sendChunk(7, $this->items(), false));
    }

    public function testStopsTheSendingWhenTheRegistryIsUnreachable(): void
    {
        $this->publication->method("claim")->willReturn([1, 2]);
        $this->client->method("submitBatch")->willThrowException(new \RuntimeException("Could not resolve host"));
        $this->publication->expects($this->once())->method("release");
        $this->assertFalse($this->publisher->sendChunk(7, $this->items(), false));
    }

    public function testGivesTheBatchIdWhenItsRecordingFails(): void
    {
        $this->publication->method("claim")->willReturn([1, 2]);
        $this->client->method("submitBatch")->willReturn(["id" => $this->batchId, "items" => [$this->item("1", "publishing"), $this->item("2", "publishing")]]);
        $this->publication->method("recordSent")->willThrowException(new \Exception("database error"));
        $this->publication->expects($this->never())->method("release");
        $this->assertTrue($this->publisher->sendChunk(7, $this->items(), false));
        $this->assertStringContainsString($this->batchId, implode("\n", $this->messages));
    }

    public function testReleasesTheSamplesWhenTheKeyCannotBeRead(): void
    {
        $publisher = $this->publisherWithKey(new PpciException("decryption failed"));
        $this->publication->method("claim")->willReturn([1, 2]);
        $this->publication->expects($this->once())->method("release");
        $this->client->expects($this->never())->method("submitBatch");
        $this->assertTrue($publisher->sendChunk(7, $this->items(), false));
    }

    public function testWritesTheIgsnOfANewlyPublishedSample(): void
    {
        $this->publication->method("recordOutcomes")->willReturn([1 => IgsnPublication::APPLIED]);
        $this->objectIdentifier->expects($this->once())->method("writeOrReplace")
            ->with(["uid" => 1, "identifier_type_id" => 12, "object_identifier_value" => $this->igsn]);
        $results = $this->publisher->recordOutcome(["id" => $this->batchId, "items" => [$this->item("1", "published", $this->igsn)]], "igsn-webhook");
        $this->assertSame([1 => IgsnPublication::APPLIED], $results);
    }

    public function testDoesNotRewriteAnIgnoredOutcome(): void
    {
        $this->publication->method("recordOutcomes")->willReturn([1 => IgsnPublication::IGNORED]);
        $this->objectIdentifier->expects($this->never())->method("writeOrReplace");
        $this->publisher->recordOutcome(["id" => $this->batchId, "items" => [$this->item("1", "published", $this->igsn)]], "igsn-webhook");
    }

    public function testRefreshRecordsTheOutcomeOfTheStoredBatch(): void
    {
        $this->publication->method("getFromUid")->willReturn($this->publication1);
        $items = [$this->item("1", "publish_failed", null, "DataCite refused"), $this->item("2", "published", $this->igsn)];
        $this->client->expects($this->once())->method("getBatch")->with("the-key", $this->batchId)
            ->willReturn(["id" => $this->batchId, "items" => $items]);
        $this->publication->expects($this->once())->method("recordOutcomes")->with($this->batchId, $items)
            ->willReturn([1 => IgsnPublication::APPLIED, 2 => IgsnPublication::IGNORED]);
        $this->publisher->refresh(1);
    }

    public function testRefreshUntracksABatchUnknownWithTheCurrentKey(): void
    {
        $this->publication->method("getFromUid")->willReturn($this->publication1);
        $this->client->method("getBatch")->willThrowException(new IgsnApiException(404, ["error" => "Not found"]));
        $this->publication->expects($this->once())->method("markUntracked")->with($this->batchId, $this->isType("string"));
        $this->publisher->refresh(1);
    }

    public function testRefreshUntracksABatchWhoseCollectionHasNoKeyAnymore(): void
    {
        $publisher = $this->publisherWithKey("");
        $this->publication->method("getFromUid")->willReturn($this->publication1);
        $this->client->expects($this->never())->method("getBatch");
        $this->publication->expects($this->once())->method("markUntracked")->with($this->batchId, $this->isType("string"));
        $publisher->refresh(1);
    }

    public function testRefreshDoesNotUntrackWhenTheKeyCannotBeRead(): void
    {
        $publisher = $this->publisherWithKey(new PpciException("decryption failed"));
        $this->publication->method("getFromUid")->willReturn($this->publication1);
        $this->publication->expects($this->never())->method("markUntracked");
        $this->client->expects($this->never())->method("getBatch");
        $publisher->refresh(1);
        $this->assertNotEmpty($this->messages);
    }

    public function testRefreshNeedsTheRightsOnTheSample(): void
    {
        $sample = $this->createMock(Sample::class);
        $sample->method("verifyCollectionFromUid")->willReturn(false);
        $publisher = new IgsnPublisher($this->client, $this->publication, $this->createMock(CollectionIgsn::class), $this->objectIdentifier, $sample, $this->identifierType);
        $this->publication->method("getFromUid")->willReturn($this->publication1);
        $this->client->expects($this->never())->method("getBatch");
        $publisher->refresh(1);
    }
}
