<?php

namespace App\Models;

use Ppci\Models\PpciModel;

/**
 * Created : 28 sept. 2016
 * Creator : quinton
 * Encoding : UTF-8
 * Copyright 2016 - All rights reserved
 */
class IdentifierType extends PpciModel
{

    function __construct()
    {
        $this->table = "identifier_type";
        $this->fields = array(
            "identifier_type_id" => array(
                "type" => 1,
                "key" => 1,
                "requis" => 1,
                "defaultValue" => 0
            ),
            "identifier_type_name" => array(
                "requis" => 1
            ),
            "identifier_type_code" => array(
                "type" => 0
            ),
            "used_for_search" => array(
                "type" => 0,
                "requis" => 1
            )
        );
        parent::__construct();
    }

    /**
     * Retourne uniquement la cle et le code associe
     *
     * @return array
     */
    function getListeWithCode()
    {
        $sql = "select identifier_type_id, identifier_type_code
            from identifier_type
            where identifier_type_code is not null
            order by identifier_type_code";
        return $this->getListeParam($sql);
    }
    /**
     * Get the identifier type of a code, whatever its case, and create it if it does not exist.
     * Two concurrent creations insert one row only (unique index on the code)
     *
     * @param string $code
     * @return integer
     */
    function getIdFromCodeOrCreate(string $code): int
    {
        $sql = "select identifier_type_id from identifier_type
                where upper(identifier_type_code) = upper(:code:)
                order by identifier_type_id limit 1";
        $row = $this->lireParam($sql, ["code" => $code]);
        if (empty($row["identifier_type_id"])) {
            $this->executeSQL(
                "insert into identifier_type (identifier_type_name, identifier_type_code) values (:code:, :code:) on conflict do nothing",
                ["code" => $code],
                true
            );
            $row = $this->lireParam($sql, ["code" => $code]);
        }
        return (int) $row["identifier_type_id"];
    }
    function getIdFromCode(string $code): int
    {
        $sql = "select identifier_type_id from identifier_type
                where identifier_type_code = :code:";
        $res = $this->lireParam($sql, ["code" => $code]);
        if (!empty($res["identifier_type_id"])) {
            return $res["identifier_type_id"];
        } else {
            return 0;
        }
    }
}
