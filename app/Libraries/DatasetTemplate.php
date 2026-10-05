<?php

namespace App\Libraries;

use App\Models\CollectionIgsn;
use App\Models\DatasetColumn;
use App\Models\DatasetTemplate as ModelsDatasetTemplate;
use App\Models\DatasetType;
use App\Models\ExportFormat;
use Ppci\Libraries\PpciException;
use Ppci\Libraries\PpciLibrary;
use Ppci\Models\PpciModel;

class DatasetTemplate extends PpciLibrary
{
    /**
     * @var ModelsDatasetTemplate
     */
    protected PpciModel $dataclass;

    

    function __construct()
    {
        parent::__construct();
        $this->dataclass = new ModelsDatasetTemplate();
        $this->keyName = "dataset_template_id";
        if (isset($_REQUEST[$this->keyName])) {
            $this->id = $_REQUEST[$this->keyName];
        }
    }

    function list()
    {
        $this->vue = service('Smarty');
        /**
         * Display the list of all records of the table
         */
        $this->vue->set($this->dataclass->getListe(2), "data");
        $this->vue->set("export/datasetTemplateList.tpl", "corps");
        $this->vue->help("gestion/préparer-un-modèle-d'exportation.html#Créer_un_modèle_de_jeu_de_données_0");
        return $this->vue->send();
    }
    function display()
    {
        $this->vue = service('Smarty');
        /**
         * Display the detail of the record
         */
        $this->vue->set($this->dataclass->getDetail($this->id), "data");
        $this->vue->set("export/datasetTemplateDisplay.tpl", "corps");
        $dc = new DatasetColumn();
        $this->vue->set($dc->getListFromParent($this->id), "columns");
        $this->vue->help("gestion/préparer-un-modèle-d'exportation.html#Créer_un_modèle_de_jeu_de_données_0");
        return $this->vue->send();
    }
    function change()
    {
        $this->vue = service('Smarty');
        /**
         * open the form to modify the record
         * If is a new record, generate a new record with default value :
         * $_REQUEST["idParent"] contains the identifiant of the parent record 
         */
        $data = $this->dataRead($this->id, "export/datasetTemplateChange.tpl");
        $dt = new DatasetType();
        $datasetTypes = $dt->getListe(1);
        /**
         * The igsn type is only proposed if the publication into IGSN is enabled,
         * or kept for a template of that type: otherwise its type would be changed when saved
         */
        if (!$this->appConfig->IGSN_enabled && $data["dataset_type_id"] != CollectionIgsn::DATASET_TYPE_ID) {
            $datasetTypes = array_values(array_filter($datasetTypes, fn($type) => $type["dataset_type_id"] != CollectionIgsn::DATASET_TYPE_ID));
        }
        $this->vue->set($datasetTypes, "datasetTypes");
        $ef = new ExportFormat();
        $this->vue->set($ef->getListe(1), "exportFormats");
        return $this->vue->send();
    }
    function write()
    {
        try {
            $this->id = $this->dataWrite($_REQUEST);
            if ($this->id > 0) {
                $_REQUEST[$this->keyName] = $this->id;
                return true;
            } else {
                return false;
            }
        } catch (PpciException) {
            return false;
        }
    }

    function delete()
    {
        /*
		 * delete record
		 */
        /**
         * The columns are deleted before the template: if the template is still used
         * by a collection published into IGSN, its deletion fails and the columns must be kept
         */
        $db = $this->dataclass->db;
        $db->transBegin();
        try {
            $this->dataDelete($this->id, true);
            $db->transCommit();
            $this->message->set(_("Suppression effectuée"));
            return true;
        } catch (PpciException $e) {
            $db->transRollback();
            return false;
        }
    }
    function duplicate()
    {
        try {
            $db = $this->dataclass->db;
            $db->transBegin();
            $_REQUEST[$this->keyName] = $this->dataclass->duplicate($this->id);
            $db->transCommit();
            return false;
        } catch (PpciException $e) {
            $this->message->set($e->getMessage(), true);
            if ($db->transEnabled) {
                $db->transRollback();
            }
            return true;
        }
    }
}
