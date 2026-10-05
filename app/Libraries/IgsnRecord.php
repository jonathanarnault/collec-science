<?php

namespace App\Libraries;

use Ppci\Libraries\PpciException;

/**
 * Build an IGSN Core record from the values extracted by a dataset template of type igsn.
 * The template columns carry, as export name, one of the keys of IgsnRecord::KEYS
 */
class IgsnRecord
{
    const SCHEMA_VERSION = "0.11.0";
    const CRS = "http://www.opengis.net/def/crs/OGC/1.3/CRS84";
    /**
     * Keys usable as export names in the dataset template, with true if required
     *
     * @var array
     */
    const KEYS = [
        "title" => true,
        "localName" => false,
        "alternateTitle" => false,
        "natureOfSample" => true,
        "sampleObjectType" => true,
        "material" => true,
        "provenanceStatus" => true,
        "collectionDateStart" => true,
        "collectionDateEnd" => false,
        "longitude" => false,
        "latitude" => false,
        "placeName" => false,
        "countryCode" => false,
        "collectionMethod" => false,
        "projectName" => false,
        "campaign" => false,
        "description" => false,
        "existenceStatus" => true,
        "availabilityStatus" => true,
        "collectionName" => false,
        "collectorFirstname" => false,
        "collectorLastname" => false,
    ];

    /**
     * Generate the IGSN Core record of a sample
     *
     * @param array $values: values of the sample, indexed by the keys of IgsnRecord::KEYS
     * @param string|null $igsn: IGSN of the sample, if already published (update)
     * @param string|null $parentIgsn: IGSN of the parent, for a subsample
     * @return array
     */
    static function build(array $values, ?string $igsn = null, ?string $parentIgsn = null): array
    {
        foreach (array_keys($values) as $key) {
            if (!array_key_exists($key, self::KEYS)) {
                throw new PpciException(sprintf(_("La colonne %s du modèle IGSN ne correspond à aucune clé IGSN connue"), $key));
            }
        }
        $missing = self::missing($values);
        if (!empty($missing)) {
            throw new PpciException(sprintf(_("champs IGSN obligatoires non renseignés : %s"), implode(", ", $missing)));
        }
        $v = fn(string $key) => self::isEmpty($values[$key] ?? null) ? null : $values[$key];
        /**
         * Identification
         */
        $identification = ["titles" => [["value" => $v("title"), "titleType" => "Main"]]];
        if ($v("alternateTitle") !== null) {
            $identification["titles"][] = ["value" => $v("alternateTitle"), "titleType" => "Other"];
        }
        if ($v("localName") !== null) {
            $identification["localName"] = $v("localName");
        }
        if (!empty($igsn)) {
            $identification["sampleIdentifier"] = $igsn;
        }
        /**
         * Classification: the head material is the first two levels of the material path
         */
        $material = $v("material") ?? "";
        $classification = [
            "natureOfSample" => self::concept($v("natureOfSample"), "nature-of-sample"),
            "sampleObjectTypes" => [self::concept($v("sampleObjectType"), "sample-type")],
            "materialCategories" => [self::concept(implode(".", array_slice(explode(".", $material), 0, 2)), "material")],
            "contextCategories" => [
                self::concept($material, "material"),
                self::concept($v("provenanceStatus"), "scientificContext") + ["notation" => "provenance-status"],
            ],
        ];
        /**
         * Responsibility is required by the registry, even empty
         */
        $responsibility = [];
        $collector = array_filter(["firstname" => $v("collectorFirstname"), "lastname" => $v("collectorLastname")], fn($x) => $x !== null);
        if (!empty($collector)) {
            $responsibility[] = ["agent" => ["agentType" => "Person"] + $collector, "roles" => ["Collector"]];
        }
        /**
         * Production
         */
        $start = self::formatDate($v("collectionDateStart"));
        $production = [
            "collection_date_start" => $start,
            "collection_date_end" => $v("collectionDateEnd") === null ? $start : self::formatDate($v("collectionDateEnd")),
            "collectionDatePrecision" => "day",
        ];
        if ($v("collectionMethod") !== null) {
            $production["collectionMethod"] = self::concept($v("collectionMethod"), "sample_description");
        }
        $project = array_filter(["name" => $v("projectName"), "campaign" => $v("campaign")], fn($x) => $x !== null);
        if (!empty($project)) {
            $production["projects"] = [$project];
        }
        /**
         * A subsample inherits the location of its parent, and the registry refuses its own
         */
        if (empty($parentIgsn)) {
            $location = [];
            if ($v("longitude") !== null && $v("latitude") !== null) {
                $location["geometry"] = ["type" => "Point", "coordinates" => [(float) $v("longitude"), (float) $v("latitude")]];
                $location["crs"] = self::CRS;
            }
            if ($v("placeName") !== null) {
                $location["placeNames"] = [$v("placeName")];
            }
            if ($v("countryCode") !== null) {
                $location["countryCodes"] = [$v("countryCode")];
            }
            if (!empty($location)) {
                $production["location"] = $location;
            }
        }
        /**
         * Curation
         */
        $curation = [
            "existenceStatus" => $v("existenceStatus"),
            "availabilityStatus" => $v("availabilityStatus"),
        ];
        if ($v("collectionName") !== null) {
            $curation["currentRepository"] = ["collectionName" => $v("collectionName")];
        }
        /**
         * Assembly: the registry refuses empty strings and empty objects, so optional parts are only added when filled
         */
        $record = [
            "schemaVersion" => self::SCHEMA_VERSION,
            "identification" => $identification,
            "classification" => $classification,
            "responsibility" => $responsibility,
            "production" => $production,
        ];
        if ($v("description") !== null) {
            $record["physicalDescription"] = ["openPhysicalDescription" => $v("description")];
        }
        $record["curation"] = $curation;
        if (!empty($parentIgsn)) {
            $record["relations"] = [[
                "relationType" => "IsDerivedFrom",
                "targetIdentifier" => ["value" => $parentIgsn, "identifierType" => "DOI"],
                "targetResourceType" => "PhysicalObject",
            ]];
        }
        return $record;
    }

    /**
     * Get the list of the required keys which are empty
     *
     * @param array $values
     * @return array
     */
    static function missing(array $values): array
    {
        $missing = [];
        foreach (self::KEYS as $key => $required) {
            if ($required && self::isEmpty($values[$key] ?? null)) {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    /**
     * Generate a concept of an OTELo vocabulary. The registry reads only the id, the label is its last level
     *
     * @param string|null $id
     * @param string $scheme
     * @return array
     */
    private static function concept(?string $id, string $scheme): array
    {
        $levels = explode(".", (string) $id);
        return [
            "id" => $id,
            "label" => end($levels),
            "schemeName" => "otelo:" . $scheme,
            "schemeURI" => "urn:otelo:vocabulary:" . $scheme,
        ];
    }

    /**
     * Format a date at the precision of the day
     *
     * @param string $value
     * @return string
     */
    private static function formatDate(string $value): string
    {
        $date = date_create($value);
        if ($date === false) {
            throw new PpciException(sprintf(_("La date %s n'est pas valide"), $value));
        }
        return $date->format("Y-m-d");
    }

    /**
     * A zero is a value (coordinates), only null and the empty string are empty
     *
     * @param mixed $value
     * @return boolean
     */
    private static function isEmpty($value): bool
    {
        return $value === null || $value === "";
    }
}
