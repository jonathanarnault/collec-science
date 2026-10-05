<?php

namespace App\Libraries;

use App\Models\IgsnPublication;
use Ppci\Libraries\PpciException;

/**
 * Calls of the IGSN registry webhook, one for each sample whose publication succeeds or fails
 */
class IgsnWebhook
{
    /**
     * Maximum gap between the timestamp of a call and the clock, so that a captured call cannot be replayed later
     */
    const MAX_AGE = 300;

    /**
     * Get the secret sent with the batches of a collection.
     * It is derived from app.IGSN_webhookSecret instead of being stored, so it is the same for every batch of the collection
     *
     * @param integer $collectionId
     * @param string|null $instanceSecret: app.IGSN_webhookSecret by default
     * @return string
     */
    static function secretFor(int $collectionId, ?string $instanceSecret = null): string
    {
        return hash_hmac("sha256", "igsn-webhook:" . $collectionId, $instanceSecret ?? config("App")->IGSN_webhookSecret);
    }

    /**
     * Verify that a call comes from the registry:
     * X-Signature = "sha256=" + hex(HMAC_SHA256(secret, X-Webhook-Timestamp + "." + raw body))
     *
     * @param string $rawBody: body of the call, before any JSON parsing
     * @param string|null $timestamp: content of X-Webhook-Timestamp
     * @param string|null $signature: content of X-Signature
     * @param string $secret
     * @param integer|null $now: current time, for the tests
     * @return boolean
     */
    static function verify(string $rawBody, ?string $timestamp, ?string $signature, string $secret, ?int $now = null): bool
    {
        if (empty($timestamp) || empty($signature) || !ctype_digit($timestamp)) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $timestamp) > self::MAX_AGE) {
            return false;
        }
        $expected = "sha256=" . hash_hmac("sha256", $timestamp . "." . $rawBody, $secret);
        return hash_equals($expected, $signature);
    }

    private IgsnPublication $publication;
    private IgsnPublisher $publisher;

    /**
     * The dependencies are created if not given (injected by the tests)
     *
     * @param IgsnPublication|null $publication
     * @param IgsnPublisher|null $publisher
     */
    function __construct(?IgsnPublication $publication = null, ?IgsnPublisher $publisher = null)
    {
        $this->publication = $publication ?? new IgsnPublication;
        $this->publisher = $publisher ?? new IgsnPublisher(null, $this->publication);
    }

    /**
     * Process a call of the registry
     *
     * @param string $rawBody
     * @param string|null $timestamp: content of X-Webhook-Timestamp
     * @param string|null $signature: content of X-Signature
     * @return integer: HTTP status of the answer
     */
    function receive(string $rawBody, ?string $timestamp, ?string $signature): int
    {
        /**
         * Without instance secret, the secret of each collection could be computed by anyone
         */
        if (empty(config("App")->IGSN_webhookSecret)) {
            return 401;
        }
        try {
            $batch = self::parse($rawBody);
        } catch (PpciException) {
            return 400;
        }
        $uid = (int) $batch["items"][0]["partnerId"];
        $publication = $this->publication->getFromUid($uid);
        /**
         * The call is signed with the secret of the collection which sent the batch, recorded with the publication.
         * An unknown sample gets the same answer as a wrong signature, and nothing is recorded before the authentication:
         * the endpoint does not tell which samples exist
         */
        if (empty($publication["collection_id"]) || !self::verify($rawBody, $timestamp, $signature, self::secretFor($publication["collection_id"]))) {
            return 401;
        }
        $result = $this->publisher->recordOutcome($batch, IgsnPublisher::WEBHOOK_LOGIN)[$uid];
        service("Log")->setLog(IgsnPublisher::WEBHOOK_LOGIN, "igsnWebhook", $uid . " " . $batch["items"][0]["status"] . " " . $result);
        /**
         * The batch is not recorded yet (its sending is not finished): the registry calls again a minute later
         */
        return $result == IgsnPublication::PENDING ? 503 : 204;
    }

    /**
     * Read a call as a batch of one item, the form read by IgsnPublisher::recordOutcome()
     *
     * @param string $rawBody
     * @return array
     */
    static function parse(string $rawBody): array
    {
        $call = json_decode($rawBody, true);
        if (
            !is_array($call) || !isset($call["partnerId"]) || empty($call["status"])
            || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $call["batchId"] ?? "")
        ) {
            throw new PpciException(_("L'appel du registre IGSN n'est pas lisible"));
        }
        $batchId = $call["batchId"];
        unset($call["batchId"]);
        return ["id" => $batchId, "items" => [$call]];
    }
}
