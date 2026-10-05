<?php

use App\Libraries\IgsnRecord;
use CodeIgniter\Test\CIUnitTestCase;
use Ppci\Libraries\PpciException;

/**
 * Mapping of the values extracted by an IGSN dataset template to an IGSN Core record
 *
 * @internal
 */
final class IgsnRecordTest extends CIUnitTestCase
{
    /**
     * Values of the "Brittany Granite" sample published on the registry,
     * as a dataset template of type igsn extracts them
     *
     * @return array
     */
    private function granite(): array
    {
        return [
            "title" => "Brittany Granite",
            "localName" => "BRT-GRN-2025-07",
            "natureOfSample" => "thin_section",
            "sampleObjectType" => "core.piece",
            "material" => "rock_and_sediment.rock.igneous.plutonic.felsic.granite",
            "provenanceStatus" => "field_sample",
            "collectionDateStart" => "2025-05-12 09:15:00",
            "longitude" => "-3.5",
            "latitude" => "48.2",
            "placeName" => "Ploumanac'h quarry",
            "countryCode" => "FR",
            "existenceStatus" => "exists",
            "availabilityStatus" => "available",
            "collectionName" => "Solid Earth demo collection",
            "collectorFirstname" => "Claire",
            "collectorLastname" => "Martin",
        ];
    }

    public function testBuildsTheGraniteRecord(): void
    {
        $this->assertSame([
            "schemaVersion" => "0.11.0",
            "identification" => [
                "titles" => [["value" => "Brittany Granite", "titleType" => "Main"]],
                "localName" => "BRT-GRN-2025-07",
            ],
            "classification" => [
                "natureOfSample" => [
                    "id" => "thin_section",
                    "label" => "thin_section",
                    "schemeName" => "otelo:nature-of-sample",
                    "schemeURI" => "urn:otelo:vocabulary:nature-of-sample",
                ],
                "sampleObjectTypes" => [[
                    "id" => "core.piece",
                    "label" => "piece",
                    "schemeName" => "otelo:sample-type",
                    "schemeURI" => "urn:otelo:vocabulary:sample-type",
                ]],
                "materialCategories" => [[
                    "id" => "rock_and_sediment.rock",
                    "label" => "rock",
                    "schemeName" => "otelo:material",
                    "schemeURI" => "urn:otelo:vocabulary:material",
                ]],
                "contextCategories" => [
                    [
                        "id" => "rock_and_sediment.rock.igneous.plutonic.felsic.granite",
                        "label" => "granite",
                        "schemeName" => "otelo:material",
                        "schemeURI" => "urn:otelo:vocabulary:material",
                    ],
                    [
                        "id" => "field_sample",
                        "label" => "field_sample",
                        "schemeName" => "otelo:scientificContext",
                        "schemeURI" => "urn:otelo:vocabulary:scientificContext",
                        "notation" => "provenance-status",
                    ],
                ],
            ],
            "responsibility" => [[
                "agent" => ["agentType" => "Person", "firstname" => "Claire", "lastname" => "Martin"],
                "roles" => ["Collector"],
            ]],
            "production" => [
                "collection_date_start" => "2025-05-12",
                "collection_date_end" => "2025-05-12",
                "collectionDatePrecision" => "day",
                "location" => [
                    "geometry" => ["type" => "Point", "coordinates" => [-3.5, 48.2]],
                    "crs" => "http://www.opengis.net/def/crs/OGC/1.3/CRS84",
                    "placeNames" => ["Ploumanac'h quarry"],
                    "countryCodes" => ["FR"],
                ],
            ],
            "curation" => [
                "existenceStatus" => "exists",
                "availabilityStatus" => "available",
                "currentRepository" => ["collectionName" => "Solid Earth demo collection"],
            ],
        ], IgsnRecord::build($this->granite()));
    }

    public function testSendsCoordinatesAsNumbers(): void
    {
        $record = IgsnRecord::build($this->granite());
        $this->assertSame([-3.5, 48.2], $record["production"]["location"]["geometry"]["coordinates"]);
    }

    public function testAlwaysSendsResponsibilityEvenWithoutCollector(): void
    {
        $values = $this->granite();
        unset($values["collectorFirstname"], $values["collectorLastname"]);
        $this->assertSame([], IgsnRecord::build($values)["responsibility"]);
    }

    public function testOmitsEmptyOptionalValues(): void
    {
        $values = $this->granite();
        $values["localName"] = "";
        $values["placeName"] = "";
        $values["countryCode"] = null;
        $values["longitude"] = "";
        $values["latitude"] = "";
        $values["collectionName"] = "";
        $values["projectName"] = "";
        $values["campaign"] = "";
        $values["description"] = "";
        $record = IgsnRecord::build($values);
        $this->assertArrayNotHasKey("localName", $record["identification"]);
        $this->assertArrayNotHasKey("location", $record["production"]);
        $this->assertArrayNotHasKey("projects", $record["production"]);
        $this->assertArrayNotHasKey("currentRepository", $record["curation"]);
        $this->assertArrayNotHasKey("physicalDescription", $record);
    }

    public function testMapsTheOptionalFields(): void
    {
        $values = $this->granite();
        $values["alternateTitle"] = "GRN-07";
        $values["collectionDateEnd"] = "2025-05-14";
        $values["collectionMethod"] = "blasting";
        $values["projectName"] = "Solid Earth Demo Survey";
        $values["campaign"] = "BRT-2025";
        $values["description"] = "Fresh pink granite";
        $record = IgsnRecord::build($values);
        $this->assertSame(["value" => "GRN-07", "titleType" => "Other"], $record["identification"]["titles"][1]);
        $this->assertSame("2025-05-14", $record["production"]["collection_date_end"]);
        $this->assertSame("otelo:sample_description", $record["production"]["collectionMethod"]["schemeName"]);
        $this->assertSame([["name" => "Solid Earth Demo Survey", "campaign" => "BRT-2025"]], $record["production"]["projects"]);
        $this->assertSame(["openPhysicalDescription" => "Fresh pink granite"], $record["physicalDescription"]);
    }

    public function testKeepsAZeroCoordinate(): void
    {
        $values = $this->granite();
        $values["longitude"] = "0";
        $record = IgsnRecord::build($values);
        $this->assertSame([0.0, 48.2], $record["production"]["location"]["geometry"]["coordinates"]);
    }

    public function testSetsTheIgsnOfAnUpdate(): void
    {
        $record = IgsnRecord::build($this->granite(), "01KXDG2000E008000000000000");
        $this->assertSame("01KXDG2000E008000000000000", $record["identification"]["sampleIdentifier"]);
    }

    public function testLinksASubSampleToItsParentWithoutLocation(): void
    {
        $record = IgsnRecord::build($this->granite(), null, "01KXDG2000E008000000000000");
        $this->assertSame([[
            "relationType" => "IsDerivedFrom",
            "targetIdentifier" => ["value" => "01KXDG2000E008000000000000", "identifierType" => "DOI"],
            "targetResourceType" => "PhysicalObject",
        ]], $record["relations"]);
        $this->assertArrayNotHasKey("location", $record["production"]);
    }

    public function testRefusesAnInvalidDate(): void
    {
        $values = $this->granite();
        $values["collectionDateStart"] = "not a date";
        $this->expectException(PpciException::class);
        IgsnRecord::build($values);
    }

    public function testRefusesAnUnknownKey(): void
    {
        $values = $this->granite();
        $values["colour"] = "pink";
        $this->expectException(PpciException::class);
        $this->expectExceptionMessage("colour");
        IgsnRecord::build($values);
    }

    public function testRefusesARecordWithoutItsRequiredKeys(): void
    {
        $values = $this->granite();
        $values["material"] = "";
        $this->expectException(PpciException::class);
        $this->expectExceptionMessage("material");
        IgsnRecord::build($values);
    }

    public function testListsTheMissingRequiredKeys(): void
    {
        $values = $this->granite();
        $values["material"] = "";
        unset($values["existenceStatus"]);
        $this->assertSame(["material", "existenceStatus"], IgsnRecord::missing($values));
    }

    public function testFindsNothingMissingInACompleteRecord(): void
    {
        $this->assertSame([], IgsnRecord::missing($this->granite()));
    }
}
