<?php

namespace App\Libraries;

use App\Models\CollectionIgsn;
use App\Models\DatasetColumn;
use App\Models\DatasetTemplate;
use App\Models\IdentifierType;
use App\Models\IgsnPublication;
use App\Models\ObjectIdentifier;
use App\Models\Sample;
use App\Models\Samplehisto;
use Ppci\Libraries\MessagePpci;

/**
 * Publication of the samples into the IGSN registry, and recording of its outcome
 * (answer of the batch, call of the webhook or manual refresh)
 */
class IgsnPublisher
{
    /**
     * Maximum number of items in a batch, set by the registry
     */
    const BATCH_SIZE = 500;
    /**
     * Login recorded in the history of the samples changed by a call of the registry
     */
    const WEBHOOK_LOGIN = "igsn-webhook";
    const IDENTIFIER_CODE = "IGSN";
    /**
     * @var MessagePpci
     */
    private $message;
    private IgsnClient $client;
    private IgsnPublication $publication;
    private CollectionIgsn $collectionIgsn;
    private ObjectIdentifier $objectIdentifier;
    private Sample $sample;
    private IdentifierType $identifierType;
    private int $identifierTypeId = 0;

    /**
     * The dependencies are created if not given (injected by the tests)
     *
     * @param IgsnClient|null $client
     * @param IgsnPublication|null $publication
     * @param CollectionIgsn|null $collectionIgsn
     * @param ObjectIdentifier|null $objectIdentifier
     * @param Sample|null $sample
     * @param IdentifierType|null $identifierType
     */
    function __construct(
        ?IgsnClient $client = null,
        ?IgsnPublication $publication = null,
        ?CollectionIgsn $collectionIgsn = null,
        ?ObjectIdentifier $objectIdentifier = null,
        ?Sample $sample = null,
        ?IdentifierType $identifierType = null
    ) {
        $this->message = service('MessagePpci');
        $this->client = $client ?? new IgsnClient;
        $this->publication = $publication ?? new IgsnPublication;
        $this->collectionIgsn = $collectionIgsn ?? new CollectionIgsn;
        $this->objectIdentifier = $objectIdentifier ?? new ObjectIdentifier;
        $this->sample = $sample ?? new Sample;
        $this->identifierType = $identifierType ?? new IdentifierType;
    }

    /**
     * Send samples to the registry, one batch per collection
     *
     * @param array $uids
     * @param boolean $confirmDuplicates: publish even if the registry suspects duplicates
     * @return void
     */
    function publish(array $uids, bool $confirmDuplicates = false): void
    {
        $uidList = $this->sample->generateArrayUidToString($uids);
        if (empty($uidList)) {
            $this->message->set(_("Pas d'échantillons sélectionnés"), true);
            return;
        }
        /**
         * The dates are read in the database format: the locale format (d/m/Y) is not readable by date_create
         */
        $this->sample->autoFormatDate = false;
        $rows = $this->sample->getListFromUids($uidList);
        $igsns = $this->publication->getIgsns(array_merge(array_column($rows, "uid"), array_filter(array_column($rows, "parent_uid"))));
        /**
         * Selection of the samples which can be sent, grouped by collection.
         * The samples already publishing are excluded when they are reserved, just before the sending
         */
        $settings = [];
        $rowsByCollection = [];
        foreach ($rows as $row) {
            $collectionId = $row["collection_id"];
            if (!$this->sample->verifyCollection($row)) {
                $this->message->set(sprintf(_("Échantillon %s : vous n'avez pas les droits sur sa collection"), $row["identifier"]), true);
                continue;
            }
            if (!array_key_exists($collectionId, $settings)) {
                $settings[$collectionId] = $this->collectionIgsn->getSettings($collectionId);
                if (empty($settings[$collectionId])) {
                    $this->message->set(sprintf(_("La publication dans IGSN n'est pas configurée pour la collection %s"), $row["collection_name"]), true);
                }
            }
            if (empty($settings[$collectionId])) {
                continue;
            }
            $row["igsn"] = $igsns[$row["uid"]] ?? null;
            $row["parent_igsn"] = empty($row["parent_uid"]) ? null : $igsns[$row["parent_uid"]] ?? null;
            if (!empty($row["parent_uid"]) && empty($row["parent_igsn"])) {
                $this->message->set(sprintf(_("Échantillon %1s : son parent %2s doit d'abord être publié dans IGSN"), $row["identifier"], $row["parent_identifier"]), true);
                continue;
            }
            /**
             * An IGSN entered by hand (another registry) must not be overwritten
             */
            if (empty($row["igsn"]) && preg_match('/(^|,)igsn:/i', $row["identifiers"] ?? "")) {
                $this->message->set(sprintf(_("Échantillon %s : il porte déjà un IGSN qui n'a pas été attribué par ce registre"), $row["identifier"]), true);
                continue;
            }
            $rowsByCollection[$collectionId][] = $row;
        }
        /**
         * Send of the batches
         */
        foreach ($rowsByCollection as $collectionId => $collectionRows) {
            $items = $this->generateItems($settings[$collectionId]["dataset_template_id"], $collectionRows);
            foreach (array_chunk($items, self::BATCH_SIZE) as $chunk) {
                if (!$this->sendChunk($collectionId, $chunk, $confirmDuplicates)) {
                    return;
                }
            }
        }
    }

    /**
     * Send one batch: reserve its samples, send them, then record the answer or cancel the reservation
     *
     * @param integer $collectionId
     * @param array $items: list of ["partnerId", "sample", "identifier"]
     * @param boolean $confirmDuplicates
     * @return boolean: false if the next batches must not be sent (limit of calls reached, registry unreachable)
     */
    function sendChunk(int $collectionId, array $items, bool $confirmDuplicates): bool
    {
        $token = $this->publication->getUUID();
        $claimed = array_flip($this->publication->claim(array_column($items, "partnerId"), $collectionId, $token));
        $sent = [];
        foreach ($items as $item) {
            if (isset($claimed[(int) $item["partnerId"]])) {
                $sent[] = $item;
            } else {
                $this->message->set(sprintf(_("Échantillon %s : une publication est déjà en cours, actualisez son statut"), $item["identifier"]), true);
            }
        }
        if (empty($sent)) {
            return true;
        }
        /**
         * The key is decrypted with the key pair of the instance, which may have been replaced since the key was entered
         */
        try {
            $apiKey = $this->collectionIgsn->getApiKey($collectionId);
        } catch (\Throwable $e) {
            $this->publication->release($token);
            $this->fail($e, _("La clé d'API IGSN de la collection ne peut pas être lue : saisissez-la de nouveau"));
            return true;
        }
        try {
            $batch = $this->client->submitBatch(
                $apiKey,
                array_map(fn($item) => ["partnerId" => $item["partnerId"], "sample" => $item["sample"]], $sent),
                $confirmDuplicates,
                $this->webhook($collectionId)
            );
        } catch (IgsnApiException $e) {
            $this->publication->release($token);
            $this->fail($e, ...self::describeError($e, array_column($sent, "identifier")));
            /**
             * Over the limit of calls, the next batches would be refused too
             */
            return $e->status != 429;
        } catch (\Throwable $e) {
            $this->publication->release($token);
            $this->fail($e, _("Le registre IGSN n'a pas pu être contacté, réessayez plus tard"));
            return false;
        }
        /**
         * The batch is accepted: its id must not be lost, even if its recording fails
         */
        try {
            $this->publication->recordSent($collectionId, $batch, $token);
        } catch (\Throwable $e) {
            $this->fail($e, sprintf(_("Le lot %s a été accepté par le registre IGSN mais n'a pas pu être enregistré : signalez-le à l'administrateur, ses échantillons ne pourront pas être renvoyés avant 15 minutes"), $batch["id"] ?? ""));
            return true;
        }
        $this->message->set(sprintf(_("%s échantillon(s) envoyé(s) au registre IGSN"), count($sent)));
        /**
         * A sample is published at once, without call of the webhook, when it is unchanged:
         * its identifier is only written if the IGSN differs from the one sent
         */
        $sentIgsns = [];
        foreach ($sent as $item) {
            $sentIgsns[(int) $item["partnerId"]] = $item["sample"]["identification"]["sampleIdentifier"] ?? null;
        }
        $published = [];
        foreach ($batch["items"] ?? [] as $item) {
            $uid = (int) $item["partnerId"];
            if ($item["status"] == IgsnPublication::PUBLISHED && !empty($item["igsn"]) && $item["igsn"] !== ($sentIgsns[$uid] ?? null)) {
                $published[$uid] = $item["igsn"];
            }
        }
        $this->writeIdentifiers($published, $_SESSION["login"] ?? "");
        return true;
    }

    /**
     * Read again the batch of a sample, to update its status and the status of the other samples of the batch
     *
     * @param integer $uid
     * @return void
     */
    function refresh(int $uid): void
    {
        $publication = $this->publication->getFromUid($uid);
        if (empty($publication["batch_id"])) {
            $this->message->set(_("Cet échantillon n'a pas été envoyé au registre IGSN"), true);
            return;
        }
        if (!$this->sample->verifyCollectionFromUid($uid)) {
            $this->message->set(_("Vous n'avez pas les droits sur la collection de l'échantillon"), true);
            return;
        }
        /**
         * The batch is read with the key of the collection which sent it, the sample may have changed of collection since
         */
        try {
            $apiKey = $this->collectionIgsn->getApiKey($publication["collection_id"]);
        } catch (\Throwable $e) {
            $this->fail($e, _("La clé d'API IGSN de la collection ne peut pas être lue : saisissez-la de nouveau"));
            return;
        }
        if (empty($apiKey)) {
            $this->untrack($publication["batch_id"], _("La collection qui a envoyé le lot n'a plus de clé d'API IGSN : le lot ne peut plus être suivi"));
            return;
        }
        try {
            $batch = $this->client->getBatch($apiKey, $publication["batch_id"]);
        } catch (IgsnApiException $e) {
            if ($e->status == 404) {
                $this->message->setSyslog($e->getMessage(), true);
                $this->untrack($publication["batch_id"], _("Le lot est inconnu du registre IGSN pour la clé d'API de la collection : le lot ne peut plus être suivi"));
            } else {
                $this->fail($e, ...self::describeError($e, []));
            }
            return;
        } catch (\Throwable $e) {
            $this->fail($e, _("Le registre IGSN n'a pas pu être contacté, réessayez plus tard"));
            return;
        }
        try {
            $this->recordOutcome($batch, $_SESSION["login"] ?? "");
            $this->message->set(_("Le statut de la publication IGSN a été mis à jour"));
        } catch (\Throwable $e) {
            $this->fail($e, _("Le statut de la publication IGSN n'a pas pu être enregistré"));
        }
    }

    /**
     * Record the outcome of the items of a batch (call of the webhook, refresh)
     *
     * @param array $batch: ["id" => batch id, "items" => [...]]
     * @param string $login: login recorded in the history of the samples
     * @return array: result of each item (IgsnPublication::APPLIED...), indexed by uid
     */
    function recordOutcome(array $batch, string $login): array
    {
        $results = $this->publication->recordOutcomes($batch["id"], $batch["items"] ?? []);
        $published = [];
        foreach ($batch["items"] ?? [] as $item) {
            $uid = (int) $item["partnerId"];
            if (($results[$uid] ?? null) == IgsnPublication::APPLIED && $item["status"] == IgsnPublication::PUBLISHED && !empty($item["igsn"])) {
                $published[$uid] = $item["igsn"];
            }
        }
        $this->writeIdentifiers($published, $login);
        return $results;
    }

    /**
     * Translate a refusal of the registry into messages for the user
     *
     * @param IgsnApiException $e
     * @param array $identifiers: identifiers of the samples, in the order of the items of the batch
     * @return array
     */
    static function describeError(IgsnApiException $e, array $identifiers): array
    {
        $body = $e->body;
        $name = fn($index) => $identifiers[$index] ?? "#" . $index;
        $messages = [];
        switch ($e->status) {
            case 422:
                foreach ($body["issues"] ?? [] as $issue) {
                    $path = $issue["path"] ?? "";
                    $detail = trim(($issue["code"] ?? "") . " " . ($issue["message"] ?? ""));
                    if (preg_match('/^items\.(\d+)(?:\.sample)?(?:\.(.*))?$/', $path, $matches)) {
                        $messages[] = sprintf(_("Échantillon %1s : le registre IGSN refuse le champ %2s (%3s)"), $name($matches[1]), $matches[2] ?? "", $detail);
                    } elseif (str_starts_with($path, "webhook")) {
                        $messages[] = sprintf(_("Le registre IGSN refuse l'adresse de retour (webhook) de l'application (%1s : %2s) : l'application doit être accessible publiquement en https, sinon videz app.IGSN_webhookSecret"), $path, $detail);
                    } else {
                        $messages[] = sprintf(_("Le registre IGSN refuse le lot : %s"), $detail);
                    }
                }
                if (empty($messages)) {
                    $messages[] = sprintf(_("Le registre IGSN refuse le lot : %s"), $body["error"] ?? "");
                }
                break;
            case 409:
                if (!empty($body["items"])) {
                    foreach ($body["items"] as $item) {
                        $similar = [];
                        foreach ($item["duplicates"] ?? [] as $duplicate) {
                            $similar[] = ($duplicate["igsn"] ?? _("en cours de publication")) . " (" . ($duplicate["name"] ?? "") . ")";
                        }
                        foreach ($item["batchDuplicates"] ?? [] as $index) {
                            $similar[] = $name($index);
                        }
                        $messages[] = sprintf(_("Échantillon %1s : doublon suspecté de %2s"), $name($item["index"]), implode(", ", $similar));
                    }
                    $messages[] = _("S'il s'agit bien d'échantillons différents, relancez la publication en cochant « publier même si des doublons sont suspectés »");
                } else {
                    $messages[] = _("Un échantillon a été modifié dans le registre IGSN pendant l'envoi : relancez la publication");
                }
                break;
            case 403:
                $messages[] = _("La clé d'API IGSN de la collection est refusée par le registre");
                break;
            case 413:
                $messages[] = _("Le lot est trop volumineux pour le registre IGSN (plus de 20 Mo)");
                break;
            case 429:
                $messages[] = _("Trop d'envois vers le registre IGSN : réessayez dans une minute pour les échantillons non envoyés");
                break;
            case 503:
                $messages[] = _("DataCite ne répond pas : rien n'a été enregistré par le registre IGSN, réessayez plus tard");
                break;
            default:
                $messages[] = sprintf(_("Le registre IGSN a répondu par une erreur (statut %1s) : %2s"), $e->status, $body["error"] ?? "");
        }
        return $messages;
    }

    /**
     * Generate the items of the batches, with the template of the collection.
     * Each sample is formatted alone: an error (mandatory column left empty, invalid value) only excludes its sample
     *
     * @param integer $datasetTemplateId
     * @param array $rows: samples, with their IGSN and the IGSN of their parent
     * @return array: list of ["partnerId", "sample", "identifier"]
     */
    private function generateItems(int $datasetTemplateId, array $rows): array
    {
        $datasetTemplate = new DatasetTemplate;
        $datasetColumn = new DatasetColumn;
        $columns = $datasetColumn->getListColumns($datasetTemplateId);
        $items = [];
        foreach ($rows as $row) {
            try {
                $items[] = [
                    "partnerId" => (string) $row["uid"],
                    "sample" => IgsnRecord::build($datasetTemplate->formatRow($row, $columns), $row["igsn"], $row["parent_igsn"]),
                    "identifier" => $row["identifier"],
                ];
            } catch (\Throwable $e) {
                $this->message->set(sprintf(_("Échantillon %1s non envoyé : %2s"), $row["identifier"], $e->getMessage()), true);
            }
        }
        return $items;
    }

    /**
     * Display the IGSN with the other identifiers of the samples.
     * A failure does not change the recorded status of the publication
     *
     * @param array $igsns: IGSN indexed by uid
     * @param string $login: login recorded in the history of the samples
     * @return void
     */
    private function writeIdentifiers(array $igsns, string $login): void
    {
        if (empty($igsns)) {
            return;
        }
        $history = new Samplehisto;
        $history->login = $login;
        $this->objectIdentifier->samplehisto = $history;
        foreach ($igsns as $uid => $igsn) {
            try {
                if ($this->identifierTypeId == 0) {
                    $this->identifierTypeId = $this->identifierType->getIdFromCodeOrCreate(self::IDENTIFIER_CODE);
                }
                $this->objectIdentifier->writeOrReplace([
                    "uid" => $uid,
                    "identifier_type_id" => $this->identifierTypeId,
                    "object_identifier_value" => $igsn
                ]);
            } catch (\Throwable $e) {
                $this->message->setSyslog(sprintf("IGSN %s not written as identifier of the sample %s: %s", $igsn, $uid, $e->getMessage()), true);
            }
        }
    }

    /**
     * Report a failure to the user, and its cause to the system log
     *
     * @param \Throwable $e
     * @param string ...$messages
     * @return void
     */
    private function fail(\Throwable $e, string ...$messages): void
    {
        $this->message->setSyslog($e->getMessage(), true);
        foreach ($messages as $message) {
            $this->message->set($message, true);
        }
    }

    /**
     * Stop following a batch which can no longer be read, so that its samples can be sent again
     *
     * @param string $batchId
     * @param string $reason
     * @return void
     */
    private function untrack(string $batchId, string $reason): void
    {
        $this->publication->markUntracked($batchId, $reason);
        $this->message->set(_("Le lot ne peut plus être lu dans le registre IGSN : l'échantillon peut être renvoyé"), true);
    }

    /**
     * Get the webhook sent with a batch of a collection.
     * The registry refuses a webhook which is not in https on a public host, and then the whole batch:
     * without secret or https address, no webhook is sent and the status is updated by a manual refresh
     *
     * @param integer $collectionId
     * @return array|null
     */
    private function webhook(int $collectionId): ?array
    {
        $url = site_url("igsnWebhook");
        if (empty(config("App")->IGSN_webhookSecret) || !str_starts_with($url, "https://")) {
            return null;
        }
        return ["url" => $url, "secret" => IgsnWebhook::secretFor($collectionId)];
    }
}
