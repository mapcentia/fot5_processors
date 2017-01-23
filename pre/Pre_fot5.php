<?php

namespace app\conf\wfsprocessors\classes\pre;

use app\conf\App;
use app\conf\wfsprocessors\PreInterface;
use app\inc\Util;

class Pre_fot5 implements PreInterface
{
    public static $transactions;
    public static $count;

    /**
     * Flag if transaction is pre-processes, so Post can tell and run
     * @var boolean
     */
    public static $flag;
    private $logFile;
    private $serializer;
    private $gmlCon;
    private $unserializer;
    private $db;
    private $layer;
    private $metaData;
    private $gc2User;


    /**
     * Holds the current feature being updated
     * @var
     */
    private $liveFeature;

    function __construct($db)
    {
        // Init objects
        // ============

        $this->db = $db;
        $this->serializer = new \XML_Serializer();
        $this->gmlCon = new \gmlConverter();
        $this->metaData = [];
        $unserializer_options = array(
            'parseAttributes' => TRUE,
            'typeHints' => FALSE
        );
        $this->gc2User = \app\inc\Input::getPath()->part(2);
        $this->unserializer = new \XML_Unserializer($unserializer_options);
        $this->logFile = fopen(dirname(__FILE__) . "/../../../../../public/logs/geodk_" . App::$param["fot5"]["geodanmark"][$this->gc2User]["user"] . ".log", "w");
        if (!self::$count) {
            self::$count = 1;
        }
    }

    function __destruct()
    {
        fclose($this->logFile);
    }

    /**
     * @return mixed
     */
    public static function getTransactions()
    {
        return self::$transactions;
    }

    private function log($txt)
    {
        fwrite($this->logFile, $txt);
    }

    /**
     * @param $arr
     * @param $operationType
     * @return string
     */
    private function createProperties($arr, $operationType, $length = null)
    {
        $properties = "";
        $geom = "";
        $lineLengthTo = null;
        if ($operationType == "insert") {
            $tmp = [];
            foreach ($arr as $k => $v) {
                array_push($tmp, ["Name" => $k, "Value" => $v]);
            }
            $arr = $tmp;
        }
        foreach ($arr as $prop) {
            switch ($prop["Name"]) {
                case "the_geom";

                    // Create new GML3 by using PostGIS
                    // ================================

                    $geom = $prop["Value"];
                    $this->serializer->serialize($geom);
                    $wktArr = $this->gmlCon->gmlToWKT($this->serializer->getSerializedData(), array());
                    $sql = "WITH q AS (SELECT ST_GeomFromText('{$wktArr[0][0]}',{$wktArr[1][0]}) as gml3) SELECT ST_AsGML(3, ST_Transform(gml3,25832),14,4) AS gml3, ST_GeometryType(gml3) AS type, ST_Length(ST_Transform(gml3,25832)) AS length from q;";
                    $res = $this->db->execQuery($sql);
                    $row = $this->db->fetchRow($res);

                    if ($row["type"] == "ST_Polygon") {
                        $geom = $this->createProperty("gml:surfaceProperty", $row["gml3"], $operationType);
                    } else {
                        $geom = $this->createProperty("gml:curveProperty", $row["gml3"], $operationType);
                    }

                    // Length from new feature
                    // =======================

                    $lineLengthTo = $row["length"];
                    break;
            }
        }
        $lineLengthFrom = "0.00";

        // Length from live feature
        // ========================

        if (!$lineLengthTo) {
            $lineLengthTo = $length;
        }

        foreach ($arr as $prop) {
            $attrArray[] = $prop["Name"];
        }
        $attrList = [
            "objekt_status" => ["Objekt_status", false],
            "kommunekode" => ["Kommunekode", false],
            "geometri_status" => ["Geometri_status", false],
            "vejmidtetype" => ["Vejmidtetype", false],
            "vejmyndighed" => ["Vejmyndighed", false],
            "fiktiv" => ["Fiktiv", true],
            "niveau" => ["Niveau", true],
            "plads" => ["Plads", true],
            "rundkoersel" => ["Rundkoersel", true],
            "trafikart" => ["Trafikart", true],
            "vejklasse" => ["Vejklasse", true],
            "overflade" => ["Overflade", true],
            "tilogfrakoersel" => ["Tilogfrakoersel", true],
            "slutknude_vej" => ["Slutknude_Vej", false],
            "startknude_vej" => ["Startknude_Vej", false],
            "arealkvalitet" => ["Arealkvalitet", false],
            "bygningstype" => ["Bygningstype", false],
            "bygning_id" => ["Bygning_ID", false],
            "metode_3d" => ["Metode_3D", false],
            "maalested_bygning" => ["Maalested_Bygning", false],
            "under_minimum_bygning" => ["Under_Minimum_Bygning", false],

            // SOE
            "oe_under_minimum" => ["Oe_Under_Minimum", false],
            "salt_soe" => ["Salt_Soe", false],
            "soe_under_minimum" => ["Soe_Under_Minimum", false],
            "soetype" => ["Soetype", false],
            "temporaer" => ["Temporaer", false],

            // VANDSLOEBSMIDTE
            "ejer_vandloebsmidte" => ["Ejer_Vandloebsmidte", false],
            "hovedforloeb" => ["Hovedforloeb", false],
            "midtebredde" => ["Midtebredde", true],
            "netvaerk" => ["Netvaerk", false],
            "retning" => ["Retning", false],
            "slutknude_vandloebsmidte" => ["Slutknude_Vandloebsmidte", false],
            "startknude_vandloebsmidte" => ["Startknude_Vandloebsmidte", false],
            "synlig_vandloebsmidte" => ["Synlig_Vandloebsmidte", true],
            "vandloebstype" => ["Vandloebstype", false],

            // META
            "meta_noejagtighed" => ["meta_noejagtighed", false],


        ];
        $flags = [];

        foreach ($arr as $prop) {
            switch ($prop["Name"]) {
                case "gml_id":
                    break;
                case "the_geom":
                    break;

                case "meta_noejagtighed":
                    $this->metaData["meta_noejagtighed"] = $prop["Value"];
                    break;
                default:
                    if (array_reverse(explode("_", $prop["Name"]))[0] == "fra" || array_reverse(explode("_", $prop["Name"]))[0] == "til") {

                        // In case of Synlig_Vandloebsmidte with an underscore
                        // ===================================================

                        if (sizeof(explode("_", $prop["Name"])) > 2) {
                            $propName = explode("_", $prop["Name"])[0] . "_" . explode("_", $prop["Name"])[1];
                        } else {
                            $propName = explode("_", $prop["Name"])[0];
                        }

                        if (!in_array($propName, $attrArray) && !isset($flags[$propName])) {
                            $val = $this->createPgArray($this->liveFeature["gml:featureMember"][$this->layer][$attrList[$propName][0]], $this->layer, $attrList[$propName][0]);
                            $properties .= $this->createProperty($attrList[$propName][0],
                                $val,
                                $operationType,
                                ["fra" => $lineLengthFrom, "til" => $lineLengthTo],
                                $arr);
                            $flags[$propName] = true;
                        }
                        break;
                    }

                    $properties .= $this->createProperty($attrList[$prop["Name"]][0], $prop["Value"],
                        $operationType,
                        $attrList[$prop["Name"]][1] ? ["fra" => $lineLengthFrom, "til" => $lineLengthTo] : null,
                        $attrList[$prop["Name"]][1] ? $arr : null);
                    break;
            }
        }
        return $properties . $geom;
    }

    private function createPgArray($arr, $layer, $el)
    {
        if (is_array($arr[0])) {
            $tmp = [];
            foreach ($arr as $v) {
                $tmp[] = $v[$layer . "_" . $el]["indhold"];
            }
            $val = "{\"" . implode("\",\"", $tmp) . "\"}";
        } else {
            $val = "{\"" . $this->liveFeature["gml:featureMember"][$layer][$el][$layer . "_" . $el]["indhold"] . "\"}";;
        }
        return $val;
    }

    /**
     * @param $name
     * @param $value
     * @param $operationType
     * @param null $attrs
     * @return string
     */
    private function createProperty($name, $value, $operationType, $attrs = null, $fullFeature = null)
    {
        if (!$name) {
            return null;
        }
        $tmp = [];
        $attrStr = "";
        $fra = [];
        $til = [];
        if (is_array($attrs)) {
            foreach ($attrs as $k => $v) {
                $tmp[] = " {$k}=\"{$v}\"";
            }
            $attrStr = implode("", $tmp);
            if ($value) {
                $value = $this->pgArrayParse($value);
            }
        }

        // Update
        // ======

        if ($operationType == "update") {
            $el = "";

            // Set attributes Fra and Til on intervals bigger than one
            // =======================================================

            if (is_array($value) && sizeof($value) > 1) {
                for ($i = 0; $i < sizeof($value); $i++) {
                    $a = $this->liveFeature["gml:featureMember"][$this->layer][$name][$i] ?: [$this->layer . "_" . $name => array_values($this->liveFeature["gml:featureMember"][$this->layer][$name])[0]];
                    for ($u = 0; $u < sizeof($fullFeature); $u++) {
                        if ($fullFeature[$u]["Name"] == strtolower($name) . "_fra" && (!$fra[$name][$i])) {
                            $fra[$name][$i] = $this->pgArrayParse($fullFeature[$u]["Value"])[$i];
                        }
                        if ($fullFeature[$u]["Name"] == strtolower($name) . "_til" && (!$til[$name][$i])) {
                            $til[$name][$i] = $this->pgArrayParse($fullFeature[$u]["Value"])[$i];
                        }

                        // We set 'Til' of last interval to whole distance of feature
                        // ==========================================================

                        if ($i == sizeof($value) - 1) {
                            $til[$name][$i] = $attrs["til"];
                        }
                    }


                    $attrStr = " fra=\"" . ($fra[$name][$i] ?: $a[$this->layer . "_" . $name]["Fra"]) . "\" til=\"" . ($til[$name][$i] ?: $a[$this->layer . "_" . $name]["Til"]) . "\"";
                    $el .= "<wfs:Property>\n\t<wfs:Name>{$name}</wfs:Name>\n\t";
                    $el .= "<wfs:Value{$attrStr}>" . trim($value[$i]) . "</wfs:Value>\n";
                    $el .= "</wfs:Property>\n";
                }
            } else {

                // Set attributes Fra and Til on non intervals and
                // intervals with length of one
                // ===============================================
                if (is_array($value)) {
                    $value = $value[0];
                }
                $el .= "<wfs:Property>\n\t<wfs:Name>{$name}</wfs:Name>\n\t";
                $el .= "<wfs:Value{$attrStr}>" . trim($value) . "</wfs:Value>\n";
                $el .= "</wfs:Property>\n";
            }

            // Insert
            // ======

        } else {
            $el = "";
            if (is_array($value) && sizeof($value) > 1) {
                for ($i = 0; $i < sizeof($value); $i++) {
                    for ($u = 0; $u < sizeof($fullFeature); $u++) {
                        if ($fullFeature[$u]["Name"] == strtolower($name) . "_fra" && (!$fra[$name][$i])) {
                            $fra[$name][$i] = $this->pgArrayParse($fullFeature[$u]["Value"])[$i];
                        }
                        if ($fullFeature[$u]["Name"] == strtolower($name) . "_til" && (!$til[$name][$i])) {
                            $til[$name][$i] = $this->pgArrayParse($fullFeature[$u]["Value"])[$i];
                        }

                        // We set 'Til' of last interval to whole distance of feature
                        // ==========================================================
                        if ($i == sizeof($value) - 1) {
                            $til[$name][$i] = $attrs["til"];
                        }
                    }

                    $attrStr = " fra=\"" . $fra[$name][$i] . "\" til=\"" . $til[$name][$i] . "\"";
                    $el .= "<{$name}{$attrStr}>" . trim($value[$i]) . "</{$name}>\n";
                }
            } else {
                if (is_array($value)) {
                    $value = $value[0];
                }
                $el .= "<{$name}{$attrStr}>" . trim($value) . "</{$name}>\n";
            }
        }
        return $el;
    }

    /**
     * Whitelist with layer names this processor runs on
     * @return array
     */
    static public function getLayerWhitelist()
    {
        return ["BYGNING", "VEJMIDTE", "SOE", "VANDLOEBSMIDTE"];
    }

    /**
     * @param $name
     * @return bool
     */
    private function checkTypeName($name)
    {
        $name = strtoupper($name);
        if (in_array($name, self::getLayerWhitelist())) {
            $this->layer = $name;
            self::$flag = true;
            return true;
        } else {
            return false;
        }
    }

    /**
     * @param $literal
     * @return array|void
     */
    private function pgArrayParse($literal)
    {
        if ($literal == '') return;
        preg_match_all('/(?<=^\{|,)(([^,"{]*)|\s*"((?:[^"\\\\]|\\\\(?:.|[0-9]+|x[0-9a-f]+))*)"\s*)(,|(?<!^\{)(?=\}$))/i', $literal, $matches, PREG_SET_ORDER);
        $values = [];
        foreach ($matches as $match) {
            $values[] = $match[3] != '' ? stripcslashes($match[3]) : (strtolower($match[2]) == 'null' ? null : $match[2]);
        }
        return $values;
    }

    /**
     * @param array $values
     * @return string
     */
    private function createMetaObject($values = [])
    {
        $date = date('Y-m-d\TH:i:s');
        $xml = '<ObjektMetadata>
                    <!-- Attributmetadata. Seneste metode til fastlæggelse objektets attributter., "Direkte fotogrammetri", "Manuelt" -->
                    <AstepDesc>' . ($values["AstepDesc"] ?: "Manuel") . '</AstepDesc>

                    <!-- Nøjagtighed. Nøjagtighed af Z koordinater -->
                    <OLVquanValDQPosAcc>' . ($values["OLVquanValDQPosAcc"] ?: "0.30 m") . '</OLVquanValDQPosAcc>

                    <!--Nøjagtighed. Enhed for Z - nøjagtigheden, altid meter-->
                    <OLVquanValUnitDQPosAcc>Meter</OLVquanValUnitDQPosAcc>

                     <!--Stedfæstelsestid. Hvornår blev objektets Z - koordinater fastlagt-->
                    <OLVstepDateTm>' . $date . '</OLVstepDateTm>

                    <!--Stedfæstelsesmetode. Metode for stedfæstelse af objektets Z - koordinater, "Direkte fotogrammetri", "Manuelt"-->
                    <OLVstepDesc>' . ($values["OLVstepDesc"] ?: "Manuel") . '</OLVstepDesc>

                    <!--Nøjagtighed. Nøjagtighed af XY koordinater-->
                    <OPLquanValDQPosAcc>' . ($this->metaData["meta_noejagtighed"] ?: $values["OPLquanValDQPosAcc"]) . '</OPLquanValDQPosAcc>

                    <!--Nøjagtighed. Enhed for XY - nøjagtigheden-->
                    <OPLquanValUnitDQPosAcc>Meter</OPLquanValUnitDQPosAcc>

                    <!--Stedfæstelsestid. Hvornår blev objektets XY - koordinater fastlagt-->
                    <OPLstepDateTm>' . $date . '</OPLstepDateTm>

                    <!--Stedfæstelsesmetode. Metode for stedfæstelse af objektets XY - koordinater-->
                    <OPLstepDesc>' . ($values["OPLstepDesc"] ?: "Manuel") . '</OPLstepDesc>

                    <!--Producentinfo. Hvilken organisation har sidst rettet på objektet-->
                    <OPROOrgName>' . App::$param["fot5"]["geodanmark"][$this->gc2User]["prod"] . '</OPROOrgName>

                    <!--Producentinfo. Hvilken rolle har denne organisation, altid "Principal investigator"-->
                    <OPROrole>Principal investigator</OPROrole>

                    <!--Myndighedskontakt. Hvem har ansvaret for objektet-->
                    <ORPOrgName>' . App::$param["fot5"]["geodanmark"][$this->gc2User]["prod"] . '</ORPOrgName>

                    <!--Myndighedskontakt. Hvilken rolle har denne organisation-->
                    <ORProle>' . ($values["ORProle"] ?: "owner") . '</ORProle>

                    <!--Specifikation. Datotype, altid Publication date-->
                    <OresDateType>Publication date</OresDateType>

                    <!--Specifikation. Datoen for den angivne specifikation-->
                    <OresRefDate>2010-03-28</OresRefDate>

                    <!--Specifikation. Navnet på FOT-specifikation, som objektet følger. -->
                    <OresTitle>FOT 5.1</OresTitle>

                    <!--Proceshistorie. Supplerende beskrivelse af den handling, der senest har ramt objektet . -->
                    <Ostatement>Redigeret via MapCentia GC2 </Ostatement>

                </ObjektMetadata>';
        return $xml;
    }

    private function snapCoordToZ($coord)
    {
        global $coords;
        $z = -999;
        $snapToleranceTmp = 10;
        $countTmp = 0;
        for ($u = 1; $u < sizeof($coords); $u++) {
            $diffX = $coords[$u][0] - $coord[0];
            $diffY = $coords[$u][1] - $coord[1];
            $diff = sqrt(pow($diffX, 2) + pow($diffY, 2));

            // calculation of distance between the two point
            // ============================================
            if ($diff <= $snapToleranceTmp) {
                $snapToleranceTmp = $diff;
                $countTmp = $u;
                $z = $coords[$u][2];
            }
        }
        //$this->log("Eks.: " . $coords[$countTmp][0] . " " . $coords[$countTmp][1] . " " . $coords[$countTmp][2] . "\n");
        //$this->log("Ny  : " . round($coord[0],2) . " " . round($coord[1],2) . " " . $z . "\n\n");
        return $z;
    }

    private function getZCoord($p)
    {
        $res = \app\inc\Util::wget("http://services.kortforsyningen.dk/?servicename=RestGeokeys_v2&elevationmodel=dtm&method=hoejde&geop=" . $p[0] . "," . $p[1] . "&login=" . App::$param["fot5"]["kortforsyningen"]["login"] . "&password=" . App::$param["fot5"]["kortforsyningen"]["password"]);
        $obj = json_decode($res);
        $this->log(print_r($obj, true));
        return $obj->hoejde;
    }

    // Start of implemented methods
    // ============================

    /**
     * @param $arr
     * @return array
     */
    public function processUpdate($arr, $typeName)
    {

        global $postgisschema;

        /**
         * If layer is NOT in whitelist, return a success and unchanged $arr
         */
        if (!$this->checkTypeName($typeName)) {
            $res = [];
            $res["arr"] = $arr;
            $res["success"] = true;
            $res["message"] = $arr;
            return $res;
        }

        /**
         * Get FotId by looking up gml_id in table, because some clients doesn't send unaltered fields .
         */
        $tableAndGid = explode(".", $arr["Filter"]["FeatureId"]["fid"]);
        $sql = "SELECT gml_id, ST_Length(the_geom) AS length FROM {$postgisschema}.{$tableAndGid[0]} WHERE gid={$tableAndGid[1]}";
        $res = $this->db->execQuery($sql);
        $row = $this->db->fetchRow($res);
        $fotId = $row["gml_id"];
        $fotLength = $row["length"];

        /**
         *  Get the live feature from Kortforsyningen WFS
         */
        if ($fotId) {
            $featureFromWfs = Util::wget("http://kortforsyningen.kms.dk/fot2007_nohistory_test?LOGIN=" . App::$param["fot5"]["kortforsyningen"]["login"] . "&PASSWORD=" . App::$param["fot5"]["kortforsyningen"]["password"] . "&SERVICE=WFS&VERSION=1.0.0&REQUEST=GetFeature&TYPENAME={$this->layer}&SRSNAME=urn:ogc:def:crs:EPSG::25832&featureId=" . $fotId);
            //TODO check if there is a feature
            //makeExceptionReport("Kan ikke hente feature fra Kortforsyningen. Måske er den opdateret i mellemtiden?");

        } else {
            makeExceptionReport("Hej");
        }

        // Unserialize live FOT feature from GeoDanmark and extract coords
        // ===============================================================

        global $coords;
        global $metaObjectId;
        $coords = [];
        $this->unserializer->unserialize($featureFromWfs);
        $fotArr = $this->unserializer->getUnserializedData();
        $this->liveFeature = $fotArr;
        array_walk_recursive($fotArr["gml:featureMember"][$this->layer], function (&$item, $key) {
            global $coords;
            global $metaObjectId;
            if ($key == "_content") {
                $bits = explode(" ", $item);
                $tmp = [];
                for ($i = 0; $i < sizeof($bits); $i++) {
                    $tmp[] = $bits[$i];
                    if (is_int(($i + 1) / 3)) {
                        $coords[] = $tmp;
                        $tmp = [];
                    }
                }
            }
            if ($key == "objektMetadata") {
                $metaObjectId = $item;
            }
        });

        // Get the live metadata object from Kortforsyningen WFS
        // and unserialize to array
        // =====================================================

        $metaDataFromWfs = Util::wget("http://kortforsyningen.kms.dk/fot2007_nohistory_test?LOGIN=" . App::$param["fot5"]["kortforsyningen"]["login"] . "&PASSWORD=" . App::$param["fot5"]["kortforsyningen"]["password"] . "&SERVICE=WFS&VERSION=1.0.0&REQUEST=GetFeature&TYPENAME=ObjektMetadata&SRSNAME=urn:ogc:def:crs:EPSG::25832&featureId=" . $metaObjectId);
        //TODO check if there is a feature

        $this->unserializer->setOptions(["parseAttributes" => false]);
        $this->unserializer->unserialize($metaDataFromWfs);
        $metaDataArr = $this->unserializer->getUnserializedData()["gml:featureMember"]["ObjektMetadata"];
        if (!$metaDataArr) {
            makeExceptionReport("Kan ikke hente metadata-objektet fra Fot. Måske er det ikke parat fra en tidligere editering. Prøv at gemme igen om et par sekunder.");
        }

        // Add Z coord to edited feature from client
        // =========================================

        array_walk_recursive($arr, function (&$item, $key) {
            if ($key == "coordinates") {
                $coordsWithZ = [];
                $coords = explode(" ", $item);
                foreach ($coords as $coord) {
                    $z = $this->snapCoordToZ(explode(",", $coord));

                    if ($z < -100) {
                        $z = $this->getZCoord(explode(",", $coord));
                    }

                    $coord = $coord . "," . $z;
                    $coordsWithZ[] = $coord;
                }
                $item = implode(" ", $coordsWithZ);
            }
        });


        // If transaction only contains geometry and is VEJMIDTE
        // =====================================================

        if (isset($fotArr["gml:featureMember"]["VEJMIDTE"])) {
            $liveAttrs = $fotArr["gml:featureMember"]["VEJMIDTE"];
            if ($arr["Property"][0]["Name"] == "the_geom" && sizeof($arr["Property"]) == 1 && isset($arr["Property"][0]["Value"]["LineString"])) {
                $this->log(print_r("\n*** Feature indeholder kun geom og er linestring ***\n", true));

                // Fiktiv
                // ======

                $arr["Property"][1]["Name"] = "fiktiv";
                $arr["Property"][1]["Value"] = $this->createPgArray(
                    $liveAttrs["Fiktiv"],
                    $this->layer,
                    "Fiktiv"
                );


                // Overflade
                // =========

                $arr["Property"][2]["Name"] = "overflade";
                $arr["Property"][2]["Value"] = $this->createPgArray(
                    $liveAttrs["Overflade"],
                    $this->layer,
                    "Overflade"
                );

                // Plads
                // =====

                $arr["Property"][3]["Name"] = "plads";
                $arr["Property"][3]["Value"] = $this->createPgArray(
                    $liveAttrs["Plads"],
                    $this->layer,
                    "Plads"
                );

                // Rundkoersel
                // ===========

                $arr["Property"][4]["Name"] = "rundkoersel";
                $arr["Property"][4]["Value"] = $this->createPgArray(
                    $liveAttrs["Rundkoersel"],
                    $this->layer,
                    "Rundkoersel"
                );

                // Tilogfrakoersel
                // ===============

                $arr["Property"][5]["Name"] = "tilogfrakoersel";
                $arr["Property"][5]["Value"] = $this->createPgArray(
                    $liveAttrs["Tilogfrakoersel"],
                    $this->layer,
                    "Tilogfrakoersel"
                );

                // Trafikart
                // =========

                $arr["Property"][6]["Name"] = "trafikart";
                $arr["Property"][6]["Value"] = $this->createPgArray(
                    $liveAttrs["Trafikart"],
                    $this->layer,
                    "Trafikart"
                );

                // Vejklasse
                // =========

                $arr["Property"][7]["Name"] = "vejklasse";
                $arr["Property"][7]["Value"] = $this->createPgArray(
                    $liveAttrs["Vejklasse"],
                    $this->layer,
                    "Vejklasse"
                );

            }
        }

        // If transaction only contains geometry and is VANDLOEBSMIDTE
        // ===========================================================
        if (isset($fotArr["gml:featureMember"]["VANDLOEBSMIDTE"])) {
            $liveAttrs = $fotArr["gml:featureMember"]["VANDLOEBSMIDTE"];
            if ($arr["Property"][0]["Name"] == "the_geom" && sizeof($arr["Property"]) == 1 && isset($arr["Property"][0]["Value"]["LineString"])) {
                $this->log(print_r("\n*** Feature indeholder kun geom og er linestring ***\n", true));

                // Midtebredde
                // ===========

                $arr["Property"][1]["Name"] = "midtebredde";
                $arr["Property"][1]["Value"] = $this->createPgArray(
                    $liveAttrs["Midtebredde"],
                    $this->layer,
                    "Midtebredde"
                );

                // Synlig_vandloebsmidte
                // =====================

                $arr["Property"][2]["Name"] = "synlig_vandloebsmidte";
                $arr["Property"][2]["Value"] = $this->createPgArray(
                    $liveAttrs["Synlig_Vandloebsmidte"],
                    $this->layer,
                    "Synlig_Vandloebsmidte"
                );

            }
        }

        /**
         * Create transaction XML
         */

        $properties = $this->createProperties($arr["Property"], "update", $fotLength);
        self::$transactions .= '
            <wfs:Insert handle="ObjektMetadata.' . self::$count . '">' .

            $this->createMetaObject($metaDataArr)

            . '

            </wfs:Insert>
            <wfs:Update typeName="' . $this->layer . '" handle="TEST">
                <wfs:Property>
                    <wfs:Name>objektMetadata</wfs:Name>
                    <wfs:Value>ObjektMetadata.' . self::$count . '</wfs:Value>
                </wfs:Property>
                    ' . $properties . '
                <ogc:Filter>
                    <ogc:GmlObjectId gml:id="' . $fotId . '"/>
                </ogc:Filter>
            </wfs:Update>';
        self::$count = self::$count + 1;


        $res = [];
        $res["arr"] = $arr;
        $res["success"] = true;
        $res["message"] = $arr;
        return $res;
    }

    /**
     * @param $arr
     * @return array
     */
    public function processInsert($arr, $typeName)
    {
        if (!$this->checkTypeName($typeName)) {
            $res = [];
            $res["arr"] = $arr;
            $res["success"] = true;
            $res["message"] = $arr;
            return $res;
        }

        /**
         * Add a temp gml_id
         */
        $arr["gml_id"] = uniqid("gc2_tmp_id_");

        /**
         * Add Z coord to edited feature from client
         */
        array_walk_recursive($arr, function (&$item, $key) {
            if ($key == "coordinates") {
                $coordsWithZ = [];
                $coords = explode(" ", $item);
                foreach ($coords as $coord) {
                    $z = $this->getZCoord(explode(",", $coord));
                    $coord = $coord . "," . $z;
                    $coordsWithZ[] = $coord;
                }
                $item = implode(" ", $coordsWithZ);
            }
        });

        $properties = $this->createProperties($arr, "insert");

        self::$transactions .=
            '<wfs:Insert handle="ObjektMetadata.' . self::$count . '">' .

            $this->createMetaObject() . '

            </wfs:Insert>
            <wfs:Insert handle="' . $arr["gml_id"] . '">
                <' . $this->layer . '>
                     <objektMetadata>ObjektMetadata.' . self::$count . '</objektMetadata>
                     ' . $properties . '
                </' . $this->layer . '>
            </wfs:Insert>';
        self::$count++;

        $res = [];
        $res["arr"] = $arr;
        $res["success"] = true;
        $res["message"] = $arr;
        return $res;
    }


    public function processDelete($arr, $typeName)
    {
        global $postgisschema;

        if (!is_array($arr["Filter"]["FeatureId"][0])) {
            $arr["Filter"]["FeatureId"][0] = $arr["Filter"]["FeatureId"];
            unset($arr["Filter"]["FeatureId"]["fid"]);
        }

        if (!$this->checkTypeName($typeName)) {
            $res = [];
            $res["arr"] = $arr;
            $res["success"] = true;
            $res["message"] = $arr;
            return $res;
        }

        foreach ($arr["Filter"]["FeatureId"] as $featureId) {
            // Get FotId by looking up gml_id in table, because some clients doesn't send unaltered fields.
            $tableAndGid = explode(".", $featureId["fid"]);
            $sql = "SELECT * FROM {$postgisschema}.{$tableAndGid[0]} WHERE gid={$tableAndGid[1]}";
            $res = $this->db->execQuery($sql);
            $row = $this->db->fetchRow($res);
            $fotId = $row["gml_id"];

            self::$transactions .= '<wfs:Delete typeName="' . $this->layer . '">
                    <ogc:Filter>
                        <ogc:GmlObjectId gml:id="' . $fotId . '"/>
                    </ogc:Filter>
                </wfs:Delete>';
        }
        $res = [];
        $res["arr"] = $arr;
        $res["success"] = true;
        $res["message"] = $arr;
        return $res;
    }
}



