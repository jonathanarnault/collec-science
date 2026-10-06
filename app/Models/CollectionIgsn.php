<?php

namespace App\Models;

use Ppci\Libraries\PpciException;
use Ppci\Models\Gacltotp;
use Ppci\Models\PpciModel;

/**
 * Settings of the publication of the samples of a collection into the IGSN registry.
 * The API key is kept in its own table and never returned by the read functions,
 * so that it is never sent with the collection (collectionGet, forms...).
 * It is encrypted with the key pair of the instance (app.privateKey, app.pubKey), as the TOTP secrets:
 * if the key pair is replaced, the API keys must be entered again
 */
class CollectionIgsn extends PpciModel
{
    const DATASET_TYPE_ID = 6;

    function __construct()
    {
        $this->table = "collection_igsn";
        $this->useAutoIncrement = false;
        $this->fields = [
            "collection_id" => ["type" => 1, "key" => 1, "requis" => 1],
            "api_key" => ["requis" => 1],
            "dataset_template_id" => ["type" => 1, "requis" => 1],
        ];
        parent::__construct();
    }

    /**
     * Get the settings of a collection, without the API key
     *
     * @param integer $collection_id
     * @return array: empty if the publication is not configured
     */
    function getSettings(int $collection_id): array
    {
        $sql = "select collection_id, dataset_template_id
                from collection_igsn
                where collection_id = :collection_id:";
        return $this->readParam($sql, ["collection_id" => $collection_id]);
    }

    /**
     * Get the API key of a collection. Only used to call the registry
     *
     * @param integer $collection_id
     * @return string
     */
    function getApiKey(int $collection_id): string
    {
        $sql = "select api_key from collection_igsn where collection_id = :collection_id:";
        $row = $this->readParam($sql, ["collection_id" => $collection_id]);
        return empty($row["api_key"]) ? "" : $this->crypt()->decodeTotpKey($row["api_key"]);
    }

    /**
     * Write the settings of a collection
     *
     * @param integer $collection_id
     * @param string $apiKey: empty to keep the stored key
     * @param integer $dataset_template_id
     * @return void
     */
    function writeSettings(int $collection_id, string $apiKey, int $dataset_template_id): void
    {
        if (empty($apiKey)) {
            if (empty($this->getSettings($collection_id))) {
                throw new PpciException(_("La clé d'API IGSN doit être renseignée"));
            }
            $sql = "update collection_igsn set dataset_template_id = :dataset_template_id:
                    where collection_id = :collection_id:";
            $this->executeSQL($sql, ["collection_id" => $collection_id, "dataset_template_id" => $dataset_template_id], true);
        } else {
            $sql = "insert into collection_igsn (collection_id, api_key, dataset_template_id)
                    values (:collection_id:, :api_key:, :dataset_template_id:)
                    on conflict (collection_id) do update
                    set api_key = excluded.api_key, dataset_template_id = excluded.dataset_template_id";
            $this->executeSQL($sql, [
                "collection_id" => $collection_id,
                "api_key" => $this->crypt()->encodeTotpKey($apiKey),
                "dataset_template_id" => $dataset_template_id
            ], true);
        }
    }

    /**
     * Disable the publication of a collection
     *
     * @param integer $collection_id
     * @return void
     */
    function remove(int $collection_id): void
    {
        $this->supprimer($collection_id);
    }

    /**
     * Get the encryption tool of the instance
     *
     * @return Gacltotp
     */
    private function crypt(): Gacltotp
    {
        $app = config("App");
        return new Gacltotp($app->privateKey, $app->pubKey);
    }

    /**
     * Get the list of the dataset templates usable to generate the IGSN records
     *
     * @return array
     */
    function getTemplates(): array
    {
        $sql = "select dataset_template_id, dataset_template_name
                from dataset_template
                where dataset_type_id = :type:
                order by dataset_template_name";
        return $this->getListParam($sql, ["type" => self::DATASET_TYPE_ID]);
    }
}
