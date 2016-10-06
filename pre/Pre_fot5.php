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
     * Flag if transaction is pre-processes, so Post can tell
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

    function __construct($db)
    {
        /**
         * Init objects
         */
        $this->db = $db;
        $this->serializer = new \XML_Serializer();
        $this->gmlCon = new \gmlConverter();
        $this->metaData = [];
        $unserializer_options = array(
            'parseAttributes' => TRUE,
            'typeHints' => FALSE
        );
        $this->unserializer = new \XML_Unserializer($unserializer_options);
        $this->logFile = fopen(dirname(__FILE__) . "/../../../../../public/logs/geodanmark.log", "a");
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
                    //Create new GML3 by using PostGIS
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

                    /**
                     * Length from new feature
                     */
                    $lineLengthTo = $row["length"];
                    break;

            }
        }
        $lineLengthFrom = "0.00";

        /**
         * Length from live feature
         */
        if (!$lineLengthTo) {
            $lineLengthTo = $length;
        }
        foreach ($arr as $prop) {
            switch ($prop["Name"]) {
                case "objekt_status":
                    $properties .= $this->createProperty("Objekt_status", $prop["Value"], $operationType);
                    break;
                case "_cprkommune":
                    $properties .= $this->createProperty("CPRkommune", $prop["Value"], $operationType);
                    break;
                case "_cprvejkode":
                    $properties .= $this->createProperty("CPRvejkode", $prop["Value"], $operationType);
                    break;
                case "kommunekode":
                    $properties .= $this->createProperty("Kommunekode", $prop["Value"], $operationType);
                    break;
                case "geometri_status":
                    $properties .= $this->createProperty("Geometri_status", $prop["Value"], $operationType);
                    break;
                case "vejmidtetype":
                    $properties .= $this->createProperty("Vejmidtetype", $prop["Value"], $operationType);
                    break;
                case "vejmyndighed":
                    $properties .= $this->createProperty("Vejmyndighed", $prop["Value"], $operationType);
                    break;
                case "fiktiv":
                    $properties .= $this->createProperty("Fiktiv", $prop["Value"], $operationType, ["fra" => $lineLengthFrom, "til" => $lineLengthTo]);
                    break;
                case "niveau":
                    $properties .= $this->createProperty("Niveau", $prop["Value"], $operationType, ["fra" => $lineLengthFrom, "til" => $lineLengthTo]);
                    break;
                case "plads":
                    $properties .= $this->createProperty("Plads", $prop["Value"], $operationType, ["fra" => $lineLengthFrom, "til" => $lineLengthTo]);
                    break;
                case "rundkoersel":
                    $properties .= $this->createProperty("Rundkoersel", $prop["Value"], $operationType, ["fra" => $lineLengthFrom, "til" => $lineLengthTo]);
                    break;
                case "trafikart":
                    $properties .= $this->createProperty("Trafikart", $prop["Value"], $operationType, ["fra" => $lineLengthFrom, "til" => $lineLengthTo]);
                    break;
                case "vejklasse":
                    $properties .= $this->createProperty("Vejklasse", $prop["Value"], $operationType, ["fra" => $lineLengthFrom, "til" => $lineLengthTo]);
                    break;
                case "_vejbredde":
                    $properties .= $this->createProperty("Vejbredde", $prop["Value"], $operationType);
                    break;
                case "overflade":
                    $properties .= $this->createProperty("Overflade", $prop["Value"], $operationType, ["fra" => $lineLengthFrom, "til" => $lineLengthTo]);
                    break;
                case "tilogfrakoersel":
                    $properties .= $this->createProperty("Tilogfrakoersel", $prop["Value"], $operationType, ["fra" => $lineLengthFrom, "til" => $lineLengthTo]);
                    break;
                case "slutknude_vej":
                    $properties .= $this->createProperty("Slutknude_Vej", $prop["Value"], $operationType);
                    break;
                case "startknude_vej":
                    $properties .= $this->createProperty("Startknude_Vej", $prop["Value"], $operationType);
                    break;
                case "arealkvalitet":
                    $properties .= $this->createProperty("Arealkvalitet", $prop["Value"], $operationType);
                    break;
                case "bygningstype":
                    $properties .= $this->createProperty("Bygningstype", $prop["Value"], $operationType);
                    break;
                case "bygning_id":
                    $properties .= $this->createProperty("Bygning_ID", $prop["Value"], $operationType);
                    break;
                case "metode_3d":
                    $properties .= $this->createProperty("Metode_3D", $prop["Value"], $operationType);
                    break;
                case "bygning_id":
                    $properties .= $this->createProperty("Bygning_ID", $prop["Value"], $operationType);
                    break;
                case "maalested_bygning":
                    $properties .= $this->createProperty("Maalested_Bygning", $prop["Value"], $operationType);
                    break;
                case "under_minimum_bygning":
                    $properties .= $this->createProperty("Under_Minimum_Bygning", $prop["Value"], $operationType);
                    break;
                case "the_geom":

                    break;

                // Metadata
                case "meta_producentinfo":
                    if (!$prop["Value"]) {
                        makeExceptionReport("Du skal angive 'meta_producentinfo'");
                    }
                    $this->metaData["meta_producentinfo"] = $prop["Value"];
                    break;
            }
        }
        return $properties . $geom;
    }

    /**
     * @param $name
     * @param $value
     * @param $operationType
     * @param null $attrs
     * @return string
     */
    private function createProperty($name, $value, $operationType, $attrs = null)
    {
        $tmp = [];
        $attrStr = "";
        if (is_array($attrs)) {
            foreach ($attrs as $k => $v) {
                $tmp[] = " {$k}=\"{$v}\"";
            }
            $attrStr = implode("", $tmp);
        }
        if ($operationType == "update") {
            $el = "<wfs:Property>\n\t<wfs:Name>{$name}</wfs:Name>\n\t<wfs:Value{$attrStr}>{$value}</wfs:Value>\n</wfs:Property>\n";

        } else {
            $el = "<{$name}{$attrStr}>{$value}</{$name}>\n";
        }
        return $el;
    }

    /**
     * @return array
     */
    static public function getLayerWhitelist(){
        return ["BYGNING", "VEJMIDTE"];
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
                    <OPLquanValDQPosAcc>' . "0.30 m" . '</OPLquanValDQPosAcc>

                    <!--Nøjagtighed. Enhed for XY - nøjagtigheden-->
                    <OPLquanValUnitDQPosAcc>Meter</OPLquanValUnitDQPosAcc>

                    <!--Stedfæstelsestid. Hvornår blev objektets XY - koordinater fastlagt-->
                    <OPLstepDateTm>' . $date . '</OPLstepDateTm>

                    <!--Stedfæstelsesmetode. Metode for stedfæstelse af objektets XY - koordinater-->
                    <OPLstepDesc>' . ($values["OPLstepDesc"] ?: "Manuel") . '</OPLstepDesc>

                    <!--Producentinfo. Hvilken organisation har sidst rettet på objektet-->
                    <OPROOrgName>' . ($this->metaData["meta_producentinfo"] ?: $values["OPROOrgName"]) . '</OPROOrgName>

                    <!--Producentinfo. Hvilken rolle har denne organisation, altid "Principal investigator"-->
                    <OPROrole>Principal investigator</OPROrole>

                    <!--Myndighedskontakt. Hvem har ansvaret for objektet-->
                    <ORPOrgName>' . ($this->metaData["meta_producentinfo"] ?: $values["ORPOrgName"]) . '</ORPOrgName>

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

    /**
     * @param $arr
     * @return array
     */
    public function processUpdate($arr, $typeName)
    {
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
        $sql = "SELECT gml_id, ST_Length(the_geom) AS length FROM geodanmark.{$tableAndGid[0]} WHERE gid={$tableAndGid[1]}";
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

        /**
         * Unserialize live FOT feature from GeoDanmark and extract coords
         */
        global $coords;
        global $metaObjectId;
        $coords = [];
        $this->unserializer->unserialize($featureFromWfs);
        $fotArr = $this->unserializer->getUnserializedData();
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


        /**
         *  Get the live metadata object from Kortforsyningen WFS and unserialize to array
         */
        $metaDataFromWfs = Util::wget("http://kortforsyningen.kms.dk/fot2007_nohistory_test?LOGIN=" . App::$param["fot5"]["kortforsyningen"]["login"] . "&PASSWORD=" . App::$param["fot5"]["kortforsyningen"]["password"] . "&SERVICE=WFS&VERSION=1.0.0&REQUEST=GetFeature&TYPENAME=ObjektMetadata&SRSNAME=urn:ogc:def:crs:EPSG::25832&featureId=" . $metaObjectId);
        //TODO check if there is a feature

        $this->unserializer->setOptions(["parseAttributes" => false]);
        $this->unserializer->unserialize($metaDataFromWfs);
        $metaDataArr = $this->unserializer->getUnserializedData()["gml:featureMember"]["ObjektMetadata"];
        if (!$metaDataArr) {
            makeExceptionReport("Kan ikke hente metadata-objektet fra Fot. Måske er det ikke parat fra en tidligere editering. Prøv at gemme igen om et par sekunder.");
        }

        /**
         * Add Z coord to edited feature from client
         */
        array_walk_recursive($arr, function (&$item, $key) {
            if ($key == "coordinates") {
                $coordsWithZ = [];
                $coords = explode(" ", $item);
                foreach ($coords as $coord) {
                    $coord = $coord . ",1.23";
                    $coordsWithZ[] = $coord;
                }
                $item = implode(" ", $coordsWithZ);
            }
        });

        //$this->log(print_r($fotArr, true));
        //die();
        /**
         * Only geometry and linestring
         */
        $liveAttrs = $fotArr["gml:featureMember"]["VEJMIDTE"];
        if ($arr["Property"][0]["Name"] == "the_geom" && sizeof($arr["Property"]) == 1 && isset($arr["Property"][0]["Value"]["LineString"])) {
            $this->log(print_r("\n*** Feature indeholder kun geom og er linestring ***\n", true));

            $arr["Property"][1]["Name"] = "fiktiv";
            $arr["Property"][1]["Value"] = $liveAttrs["Fiktiv"]["VEJMIDTE_Fiktiv"]["indhold"];

            $arr["Property"][2]["Name"] = "overflade";
            $arr["Property"][2]["Value"] = $liveAttrs["Overflade"]["VEJMIDTE_Overflade"]["indhold"];

            $arr["Property"][3]["Name"] = "plads";
            $arr["Property"][3]["Value"] = $liveAttrs["Plads"]["VEJMIDTE_Plads"]["indhold"];

            $arr["Property"][4]["Name"] = "rundkoersel";
            $arr["Property"][4]["Value"] = $liveAttrs["Rundkoersel"]["VEJMIDTE_Rundkoersel"]["indhold"];

            $arr["Property"][5]["Name"] = "tilogfrakoersel";
            $arr["Property"][5]["Value"] = $liveAttrs["Tilogfrakoersel"]["VEJMIDTE_Tilogfrakoersel"]["indhold"];

            $arr["Property"][6]["Name"] = "trafikart";
            $arr["Property"][6]["Value"] = $liveAttrs["Trafikart"]["VEJMIDTE_Trafikart"]["indhold"];

            $arr["Property"][7]["Name"] = "vejklasse";
            $arr["Property"][7]["Value"] = $liveAttrs["Vejklasse"]["VEJMIDTE_Vejklasse"]["indhold"];
        }
        //$this->log(print_r($arr, true));


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
                    $coord = $coord . ",1.23";
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
        if (!$this->checkTypeName($typeName)) {
            $res = [];
            $res["arr"] = $arr;
            $res["success"] = true;
            $res["message"] = $arr;
            return $res;
        }

        /**
         * Get FotId by looking up gml_id in table, because some clients doesn't send unaltered fields.
         */
        $tableAndGid = explode(".", $arr["Filter"]["FeatureId"]["fid"]);
        $sql = "SELECT * FROM geodanmark.{$tableAndGid[0]} WHERE gid={$tableAndGid[1]}";
        $res = $this->db->execQuery($sql);
        $row = $this->db->fetchRow($res);
        $fotId = $row["gml_id"];

        self::$transactions .= '<wfs:Delete typeName="' . $this->layer . '">
                    <ogc:Filter>
                        <ogc:GmlObjectId gml:id="' . $fotId . '"/>
                    </ogc:Filter>
                </wfs:Delete>';

        $res = [];
        $res["arr"] = $arr;
        $res["success"] = true;
        $res["message"] = $arr;
        return $res;
    }
}



