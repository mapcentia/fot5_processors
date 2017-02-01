<?php

namespace app\conf\wfsprocessors\classes\post;

use app\conf\App;
use app\conf\wfsprocessors\PostInterface;
use app\conf\wfsprocessors\classes\pre\Pre_fot5;

class Post_fot5 implements PostInterface
{
    private $logFile;
    private $serializer;
    private $unserializer;
    private $db;
    private $gc2User;
    private $service;

    function __construct($db)
    {
        $this->db = $db;
        $this->serializer = new \XML_Serializer();
        $unserializer_options = array(
            'parseAttributes' => TRUE,
            'typeHints' => FALSE
        );
        $this->unserializer = new \XML_Unserializer($unserializer_options);
        $this->gc2User = \app\inc\Input::getPath()->part(2);

        // Set TEST or PROD system
        // =======================

        $this->service = \app\inc\Input::getPath()->part(3) == "fot" ? "fotupload.kms.dk" : "fot.kms.dk";

        $this->logFile = fopen(dirname(__FILE__) . "/../../../../../public/logs/geodk_" . App::$param["fot5"]["geodanmark"][$this->gc2User]["user"] . ".log", "w");
    }

    function __destruct()
    {
        fclose($this->logFile);
    }

    private function log($txt)
    {
        fwrite($this->logFile, $txt);
    }

    /**
     * @param $xml
     * @return string
     */
    private function formatXml($xml)
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml);
        return $dom->saveXML();
    }

    /**
     * @param $transactionXml
     * @return mixed
     */
    private function post($transactionXml)
    {
        $ch = curl_init("https://" . $this->service . "/FAS/TransactionServlet");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "user=" . App::$param["fot5"]["geodanmark"][$this->gc2User]["user"] . "&pw=" . App::$param["fot5"]["geodanmark"][$this->gc2User]["pw"] . "&transactionxml=" . $transactionXml);
        return curl_exec($ch);
    }

    public function process()
    {
        global $postgisschema;
        if (!Pre_fot5::$flag) {
            return ["success" => true];
        }
        // TODO Check if empty
        $transactions = Pre_fot5::getTransactions();

        $transactionsReady = '<?xml version="1.0" encoding="UTF-8"?>
                <wfs:Transaction version="1.1.0" service="WFS"
                xmlns="http://schemas.kms.dk/fot/FOT5.1_svid90_inputMedInterval_version1"
                xmlns:ogc="http://www.opengis.net/ogc"
                xmlns:gml="http://www.opengis.net/gml"
                xmlns:wfs="http://www.opengis.net/wfs"
                xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                xsi:schemaLocation="http://schemas.kms.dk/fot/FOT5.1_svid90_inputMedInterval_version1 http://schemas.kms.dk/fot/FOT5.1_svid90_inputMedInterval_version1.xsd http://www.opengis.net/wfs http://schemas.opengis.net/wfs/1.1.0/wfs.xsd">
                ' . $transactions .
            '<wfs:Native vendorId="FOTDK_escapeValidationReport_off" safeToIgnore="false"/>' .
            '</wfs:Transaction>';


        $this->log("<---------- " . date('l jS \of F Y H:i:s') . " user: " . App::$param["fot5"]["geodanmark"][$this->gc2User]["user"] . " ---------->\n\n");
        $this->log($this->formatXml($transactionsReady) . "\n");

        // HACK
        $transactionsReady = str_replace(' srsDimension="3"', "", $transactionsReady);

        // Post the transaction
        $buffer = $this->post($transactionsReady);

        $this->log("<---------- Response fra Geodanmark ---------->\n\n");

        $buffer = preg_replace("/<[0-9]>/", "", $buffer);

        $this->log($this->formatXml($buffer) . "\n\n");
        $this->log("\n\n");

        $status = $this->unserializer->unserialize($buffer);
        if (isset($status->error_message_prefix)) {
            $res["success"] = false;
            $res["message"] = "Noget gik galt";
            return $res;
        }
        $resFromFot = $this->unserializer->getUnserializedData();
        $res = [];
        if ($resFromFot["Exception"]) {
            $res["success"] = false;
            $res["message"] = print_r($resFromFot["Exception"]["ExceptionText"]["fejlrapport"]["objektype"] ?: $resFromFot["Exception"]["ExceptionText"], true);
        } else {
            $res["success"] = true;

            // If single edit, make result array with one key
            // ==============================================

            if (!is_array($resFromFot["wfs:InsertResults"]["wfs:Feature"][0])) {
                $resFromFot["wfs:InsertResults"]["wfs:Feature"][0] = $resFromFot["wfs:InsertResults"]["wfs:Feature"];
                unset($resFromFot["wfs:InsertResults"]["wfs:Feature"]["handle"]);
                unset($resFromFot["wfs:InsertResults"]["wfs:Feature"]["ogc:FeatureId"]);
            }
            //$this->log(print_r($resFromFot, true));
            foreach ($resFromFot["wfs:InsertResults"]["wfs:Feature"] as $feature) {

                $oldFotId = $feature["handle"];
                $newFotId = $feature["ogc:FeatureId"]["fid"];

                $this->log("Old FotID: " . $oldFotId . "\n");
                $this->log("New FotID: " . $newFotId . "\n\n");


                // Store the new FeatureId for BYGNING in PostgreSQL
                // =================================================

                $sql = "UPDATE {$postgisschema}.bygning SET gml_id=:new WHERE gml_id=:old";
                $resUpdate = $this->db->prepare($sql);
                try {
                    $resUpdate->execute(["new" => $newFotId, "old" => $oldFotId]);
                } catch (\PDOException $e) {
                    $res["success"] = false;
                    $res["message"] = $e->getMessage();
                    return $res;
                }

                // Store the new FeatureId for VEJMIDTE in PostgreSQL
                // ==================================================

                $sql = "UPDATE {$postgisschema}.vejmidte SET gml_id=:new WHERE gml_id=:old";
                $resUpdate = $this->db->prepare($sql);
                try {
                    $resUpdate->execute(["new" => $newFotId, "old" => $oldFotId]);
                } catch (\PDOException $e) {
                    $res["success"] = false;
                    $res["message"] = $e->getMessage();
                    return $res;
                }

                // Store the new FeatureId for SOE in PostgreSQL
                // =============================================

                $sql = "UPDATE {$postgisschema}.soe SET gml_id=:new WHERE gml_id=:old";

                $resUpdate = $this->db->prepare($sql);
                try {
                    $resUpdate->execute(["new" => $newFotId, "old" => $oldFotId]);
                } catch (\PDOException $e) {
                    $res["success"] = false;
                    $res["message"] = print_r($e, true);
                    return $res;
                }

                // Store the new FeatureId for VANDLOEBSMIDTE in PostgreSQL
                // ========================================================

                $sql = "UPDATE {$postgisschema}.vandloebsmidte SET gml_id=:new WHERE gml_id=:old";
                $resUpdate = $this->db->prepare($sql);
                try {
                    $resUpdate->execute(["new" => $newFotId, "old" => $oldFotId]);
                } catch (\PDOException $e) {
                    $res["success"] = false;
                    $res["message"] = print_r($e, true);
                    return $res;
                }
            }
        }
        return $res;
    }
}
