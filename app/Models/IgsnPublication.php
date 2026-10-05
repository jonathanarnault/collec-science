<?php

namespace App\Models;

use Ppci\Models\PpciModel;

/**
 * Publication of the samples into the IGSN registry, one row per sample.
 * Each change of status is a single conditional statement: the answer of a batch, the calls of the webhook
 * and the refreshes may be processed at the same time
 */
class IgsnPublication extends PpciModel
{
    const SUBMITTING = "submitting";
    const PUBLISHING = "publishing";
    const PUBLISHED = "published";
    const UNTRACKED = "untracked";
    /**
     * Statuses which end a publication
     */
    const FINAL_STATUSES = [self::PUBLISHED, "publish_failed", "withdrawn", "tombstone"];
    /**
     * Results of the recording of an outcome
     */
    const APPLIED = "applied";
    const PENDING = "pending";
    const IGNORED = "ignored";
    /**
     * Delay after which the claim of a request which never ended is abandoned
     */
    const CLAIM_TIMEOUT = "15 minutes";

    function __construct()
    {
        $this->table = "igsn_publication";
        $this->useAutoIncrement = false;
        $this->fields = [
            "uid" => ["type" => 1, "key" => 1, "requis" => 1],
            "collection_id" => ["type" => 1, "requis" => 1],
            "batch_id" => ["type" => 0],
            "igsn" => ["type" => 0],
            "igsn_status" => ["type" => 0, "requis" => 1],
            "igsn_error" => ["type" => 0],
            "claim_token" => ["type" => 0],
            "claimed_at" => ["type" => 3],
            "updated_at" => ["type" => 3],
        ];
        parent::__construct();
    }

    /**
     * Get the IGSN minted for a list of samples
     *
     * @param array $uids
     * @return array: IGSN indexed by uid, for the samples which have one
     */
    function getIgsns(array $uids): array
    {
        if (empty($uids)) {
            return [];
        }
        $rows = $this->getListParam(
            "select uid, igsn from igsn_publication where uid in :uids: and igsn is not null",
            ["uids" => array_map("intval", $uids)]
        );
        return array_column($rows, "igsn", "uid");
    }

    /**
     * Get the publication of a sample
     *
     * @param integer $uid
     * @return array
     */
    function getFromUid(int $uid): array
    {
        $sql = "select uid, collection_id, batch_id, igsn, igsn_status, igsn_error, updated_at
                from igsn_publication
                where uid = :uid:";
        return $this->readParam($sql, ["uid" => $uid]);
    }

    /**
     * Reserve samples before sending them, so that two requests never send the same sample.
     * A sample is not reserved if it is publishing, or reserved by another request less than 15 minutes ago
     *
     * @param array $uids
     * @param integer $collectionId: collection whose API key is used
     * @param string $token: token of the request
     * @return array: uids reserved by the request
     */
    function claim(array $uids, int $collectionId, string $token): array
    {
        $uids = array_filter(array_map("intval", $uids));
        if (empty($uids)) {
            return [];
        }
        /**
         * The condition of the update is evaluated against the last committed row,
         * so only one of two concurrent requests obtains the sample. The rows returned are the reserved ones
         */
        $sql = "insert into igsn_publication (uid, collection_id, igsn_status, claim_token, claimed_at)
                select u, :collection_id:, :submitting:, cast(:token: as uuid), now()
                from unnest(array[" . implode(",", $uids) . "]) as u
                on conflict (uid) do update
                set claim_token = excluded.claim_token, claimed_at = excluded.claimed_at
                where igsn_publication.igsn_status <> :publishing:
                and (igsn_publication.claim_token is null
                or igsn_publication.claimed_at < now() - interval '" . self::CLAIM_TIMEOUT . "')
                returning uid";
        $rows = $this->executeSQL($sql, [
            "collection_id" => $collectionId,
            "submitting" => self::SUBMITTING,
            "token" => $token,
            "publishing" => self::PUBLISHING
        ]);
        return array_map("intval", array_column($rows, "uid"));
    }

    /**
     * Cancel the reservation of a request whose batch was not accepted
     *
     * @param string $token
     * @return void
     */
    function release(string $token): void
    {
        $this->executeSQL(
            "delete from igsn_publication where claim_token = :token: and igsn_status = :submitting:",
            ["token" => $token, "submitting" => self::SUBMITTING],
            true
        );
        $this->executeSQL(
            "update igsn_publication set claim_token = null, claimed_at = null where claim_token = :token:",
            ["token" => $token],
            true
        );
    }

    /**
     * Record the answer of an accepted batch, for the samples reserved by the request
     *
     * @param integer $collectionId: collection whose API key and secret were used
     * @param array $batch: answer of the registry
     * @param string $token: token of the request
     * @return void
     */
    function recordSent(int $collectionId, array $batch, string $token): void
    {
        $sql = "update igsn_publication p
                set collection_id = :collection_id:, batch_id = cast(:batch_id: as uuid), igsn_status = x.status,
                igsn = coalesce(x.igsn, p.igsn), igsn_error = x.error,
                claim_token = null, claimed_at = null, updated_at = now()
                from json_to_recordset(cast(:items: as json)) as x(uid integer, status varchar, igsn varchar, error varchar)
                where p.uid = x.uid and p.claim_token = cast(:token: as uuid)";
        $this->executeSQL($sql, [
            "collection_id" => $collectionId,
            "batch_id" => $batch["id"],
            "items" => self::itemsToJson($batch["items"] ?? []),
            "token" => $token
        ], true);
    }

    /**
     * Record the outcome of the publication of samples of a batch (calls of the webhook, refresh).
     * Only the samples whose current batch is this one are updated:
     * the outcome of an older batch never replaces the status of a newer one
     *
     * @param string $batchId
     * @param array $items: items of the batch, as given by the registry
     * @return array: result indexed by uid: APPLIED (status changed), PENDING (the batch is not recorded yet,
     *                while the sample is being sent) or IGNORED (unchanged, or outcome of an older batch)
     */
    function recordOutcomes(string $batchId, array $items): array
    {
        if (empty($items)) {
            return [];
        }
        $sql = "update igsn_publication p
                set igsn_status = x.status, igsn = coalesce(x.igsn, p.igsn), igsn_error = x.error, updated_at = now()
                from json_to_recordset(cast(:items: as json)) as x(uid integer, status varchar, igsn varchar, error varchar)
                where p.uid = x.uid and p.batch_id = cast(:batch_id: as uuid)
                and not (p.igsn_status in :finals: and x.status = :publishing:)
                and (p.igsn_status, coalesce(p.igsn, ''), coalesce(p.igsn_error, ''))
                is distinct from (x.status, coalesce(x.igsn, p.igsn, ''), coalesce(x.error, ''))
                returning p.uid";
        $applied = array_column($this->executeSQL($sql, [
            "items" => self::itemsToJson($items),
            "batch_id" => $batchId,
            "finals" => self::FINAL_STATUSES,
            "publishing" => self::PUBLISHING
        ]), "uid");
        $uids = array_map(fn($item) => (int) $item["partnerId"], $items);
        $results = array_fill_keys($uids, self::IGNORED);
        foreach ($applied as $uid) {
            $results[(int) $uid] = self::APPLIED;
        }
        /**
         * A sample still reserved without this batch: the answer of the batch is not recorded yet
         */
        $pending = $this->getListParam(
            "select uid from igsn_publication
            where uid in :uids: and claim_token is not null
            and batch_id is distinct from cast(:batch_id: as uuid)",
            ["uids" => $uids, "batch_id" => $batchId]
        );
        foreach (array_column($pending, "uid") as $uid) {
            $results[(int) $uid] = self::PENDING;
        }
        return $results;
    }

    /**
     * Stop following a batch which can no longer be read (key of the collection changed or removed),
     * so that its samples can be sent again
     *
     * @param string $batchId
     * @param string $reason
     * @return void
     */
    function markUntracked(string $batchId, string $reason): void
    {
        $this->executeSQL(
            "update igsn_publication set igsn_status = :untracked:, igsn_error = :reason:, updated_at = now()
            where batch_id = :batch_id: and igsn_status = :publishing:",
            ["untracked" => self::UNTRACKED, "reason" => $reason, "batch_id" => $batchId, "publishing" => self::PUBLISHING],
            true
        );
    }

    /**
     * Encode the items of a batch for json_to_recordset
     *
     * @param array $items
     * @return string
     */
    private static function itemsToJson(array $items): string
    {
        return json_encode(array_map(fn($item) => [
            "uid" => (int) $item["partnerId"],
            "status" => $item["status"],
            "igsn" => $item["igsn"] ?? null,
            "error" => $item["publishingError"] ?? null,
        ], $items));
    }
}
