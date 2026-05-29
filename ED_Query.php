<?php
/**
 * aeds_loader_final.php
 *
 * - Load header/items from Oracle by ?invoice=PACK_SHIP_NO (oci_connect)
 * - Or upload an AEDS XML file to populate the form
 * - Edit header and multiple items in the form
 * - Save current form as AEDS_output.xml
 *
 * Update DB credentials, table names, and mappings as needed.
 */

/* -------------------------
   DB connection (edit if required)
   ------------------------- */
$dbUser       = 'mm';
$dbPass       = 'mm123';
$dbConnString = 'reports'; // TNS name or connection string

/* -------------------------
   Tables and columns (edit if required)
   ------------------------- */
$headerTable     = 'PACKING_SHIPPING_HEADER';
$itemsTable      = 'PACKING_SHIPPING_DETAIL';
$invoiceColumn   = 'PACK_SHIP_NO';
$itemOrderColumn = 'LINE'; // optional ordering column

/* -------------------------
   Header -> SAD_XML mapping (DB column name, STATIC:..., or null)
   ------------------------- */
$headerToFieldMap = [
    'Customs_clearance_office_code' => 'STATIC:P03',
    'Consignee_name'                => 'SHIP_TO_NAME',
    'Consignee_address1'            => 'SHIP_TO_ADDRESS_1',
    'Consignee_address2'            => 'SHIP_TO_ADDRESS_2',
    'Consignee_city'                => 'SHIP_TO_CITY',
    'Consignee_zipcode'             => 'SHIP_TO_POST_CODE',
    'Declarant_code'                => 'DECLARANT_TIN',
    'User_reference_number'         => 'USER_REF_NO',
    'Exporter_code'                 => 'STATIC:205275073000',
    'Manifest_reference_number'     => '',
    'Name_of_financially_responsible_body' => 'STATIC:MacroAsia - SEZ',
    'Country_of_last_consignment'   => 'COUNTRY_LAST_CONSIGNMENT',
    'Identification_of_means_of_transport_at_departure' => 'MEANS_OF_TRANSPORT',
    'Container_flag'                => 'CONTAINER_FLAG',
    'Terms_of_delivery_code'        => 'TERMS_OF_DELIVERY_CODE',
    'Terms_of_delivery_place'       => 'TERMS_OF_DELIVERY_PLACE',
    'Active_means_of_transport'     => 'ACTIVE_MEANS_OF_TRANSPORT',
    'Place_of_loading-unloading_code' => 'PLACE_OF_LOADING',
    'Terms_of_payment_code'         => 'TERMS_OF_PAYMENT_CODE',
    'Border_customs_office_code'    => 'BORDER_CUSTOMS_OFFICE',
    'Location_of_goods'             => 'LOCATION_OF_GOODS',
    'Province_of_origin_id'         => 'PROVINCE_OF_ORIGIN',
    'LCL'                           => 'LCL',
    'FCL'                           => 'FCL',
    'Invoice_currency_code'         => 'STATIC:USD',
    'Total_amount_invoice'          => 'TOTAL_AMOUNT',
    'Bank_code'                     => 'BANK_CODE',
    'Bank_branch_code'              => 'BANK_BRANCH_CODE',
    'Bank_file_reference_number'    => 'BANK_FILE_REF',
    'Peza_prepayment'               => 'STATIC:PEZA-DEFAULT-ACCOUNT',
    'Sad_Customs_Office'            => 'STATIC:P03',
    'Second_of_the_nature_of_transactions' => 'NATURE_SECOND'
];

/* -------------------------
   Item -> SAD_XML mapping (DB column name, STATIC:..., or null)
   ------------------------- */
$itemToFieldMap = [
    'Item_number' => 'LINE', // replaced by sequential counter when loading
    'Marks_and_numbers_pack_part1' => 'STATIC:AS ADDRESSED',
    'Marks_and_numbers_pack_part2' => 'MARKS_PART2',
    'Number_of_packages' => 'QTY',
    'Type_of_packages' => 'UOM',
    'Description_of_goods_part1' => 'PN_DESCRIPTION',
    'Description_of_goods_part2' => 'SN',
    'Description_of_goods_part3' => 'DESCRIPTION3',
    'Commodity_code_part1' => 'ECCN',
    'Commodity_code_part2' => 'HS_CODE2',
    'Commodity_code_part3' => 'HS_CODE3',
    'Gross_mass' => 'GROSS_WEIGHT',
    'Net_mass' => 'NET_WEIGHT',
    'Country_of_origin_code' => 'STATIC:PH',
    'Extended_customs_procedure' => 'EXTENDED_CUSTOMS_PROCEDURE',
    'National_procedure-additional_code' => 'NATIONAL_PROCEDURE_CODE',
    'Supplementary_units_code' => 'SUPP_UNITS_CODE',
    'Supplementary_units' => 'SUPP_UNITS',
    'Airway_bill' => 'AIRWAY_BILL',
    'Item_price' => 'VALUE',
    'License_number' => 'STATIC:1',
    'Amount_deducted_from_license' => 'STATIC:00',
    'Quantity_deducted_from_license' => 'STATIC:00',
    'Additional_information_code' => 'ADDITIONAL_INFO_CODE',
    'Invoice_reference' => 'PACK_SHIP_NO',
    'Rserved_field' => 'RESERVED_FIELD'
];

/* -------------------------
   Helpers
   ------------------------- */
function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function resolveMapping($mapValue, $row) {
    if ($mapValue === null || $mapValue === '') return '';
    if (is_string($mapValue) && stripos($mapValue, 'STATIC:') === 0) {
        return substr($mapValue, strlen('STATIC:'));
    }
    $col = strtoupper($mapValue);
    if (is_array($row) && array_key_exists($col, $row)) {
        return $row[$col] ?? '';
    }
    return '';
}

/* -------------------------
   Parse uploaded AEDS XML into arrays
   ------------------------- */
function parseSadXmlString($xmlText) {
    $result = ['fields' => [], 'items' => []];
    if (!$xmlText) return $result;

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    if (@$doc->loadXML($xmlText) === false) return $result;
    $xpath = new DOMXPath($doc);

    $getText = function($context, $tag) use ($xpath) {
        if (!$context) return '';
        $res = $context->getElementsByTagName($tag);
        if ($res->length > 0) return trim($res->item(0)->textContent);
        $q = ".//*[local-name() = '$tag']";
        $r = $xpath->query($q, $context);
        return ($r && $r->length > 0) ? trim($r->item(0)->textContent) : '';
    };

    // Top-level fields (including those in example)
    $topTags = [
        'Customs_clearance_office_code','Declarant_code','User_reference_number','Exporter_code',
        'Manifest_reference_number','Name_of_financially_responsible_body','Country_of_last_consignment',
        'Container_flag','Peza_prepayment','Sad_Customs_Office','Invoice_currency_code','Total_amount_invoice',
        'Second_of_the_nature_of_transactions','Identification_of_means_of_transport_at_departure',
        'Place_of_loading-unloading_code','Terms_of_payment_code','Border_customs_office_code',
        'Location_of_goods','Province_of_origin_id','LCL','FCL','Bank_code','Bank_branch_code','Bank_file_reference_number',
        'Terms_of_delivery_code','Terms_of_delivery_place','Active_means_of_transport'
    ];
    foreach ($topTags as $t) {
        $nodes = $doc->getElementsByTagName($t);
        if ($nodes->length > 0) {
            $result['fields'][$t] = trim($nodes->item(0)->textContent);
        } else {
            $res = $xpath->query("//*[local-name()='$t']");
            $result['fields'][$t] = ($res && $res->length>0) ? trim($res->item(0)->textContent) : '';
        }
    }

    // Consignee segment
    $consNodes = $doc->getElementsByTagName('Consignee_segment');
    if ($consNodes->length > 0) {
        $cons = $consNodes->item(0);
        $result['fields']['Consignee_name'] = $getText($cons, 'Consignee_name');
        $result['fields']['Consignee_address1'] = $getText($cons, 'Consignee_address1');
        $result['fields']['Consignee_address2'] = $getText($cons, 'Consignee_address2');
        $result['fields']['Consignee_city'] = $getText($cons, 'Consignee_city');
        $result['fields']['Consignee_zipcode'] = $getText($cons, 'Consignee_zipcode');
    }

    // Items
    $itemNodes = $doc->getElementsByTagName('Item_segment');
    $lineCounter = 1;
    foreach ($itemNodes as $itemNode) {
        $it = [];
        // Item_number
        $numNodes = $itemNode->getElementsByTagName('Item_number');
        $num = ($numNodes->length > 0) ? trim($numNodes->item(0)->textContent) : '';
        $it['Item_number'] = $num !== '' ? $num : (string)$lineCounter;

        // Marks_and_number_segment
        $marks = null;
        foreach ($itemNode->childNodes as $c) {
            if ($c->nodeType === XML_ELEMENT_NODE && strcasecmp($c->localName, 'Marks_and_number_segment') === 0) { $marks = $c; break; }
        }
        if ($marks) {
            $it['Marks_and_numbers_pack_part1'] = $getText($marks, 'Marks_and_numbers_pack_part1');
            $it['Marks_and_numbers_pack_part2'] = $getText($marks, 'Marks_and_numbers_pack_part2');
            $it['Number_of_packages'] = $getText($marks, 'Number_of_packages');
            $it['Type_of_packages'] = $getText($marks, 'Type_of_packages');
            $it['Description_of_goods_part1'] = $getText($marks, 'Description_of_goods_part1');
            $it['Description_of_goods_part2'] = $getText($marks, 'Description_of_goods_part2');
            $it['Description_of_goods_part3'] = $getText($marks, 'Description_of_goods_part3');
        } else {
            $it['Marks_and_numbers_pack_part1'] = $getText($itemNode, 'Marks_and_numbers_pack_part1');
            $it['Marks_and_numbers_pack_part2'] = $getText($itemNode, 'Marks_and_numbers_pack_part2');
            $it['Number_of_packages'] = $getText($itemNode, 'Number_of_packages');
            $it['Type_of_packages'] = $getText($itemNode, 'Type_of_packages');
            $it['Description_of_goods_part1'] = $getText($itemNode, 'Description_of_goods_part1');
            $it['Description_of_goods_part2'] = $getText($itemNode, 'Description_of_goods_part2');
            $it['Description_of_goods_part3'] = $getText($itemNode, 'Description_of_goods_part3');
        }

        // Commodity_segment
        $commodity = null;
        foreach ($itemNode->childNodes as $c) {
            if ($c->nodeType === XML_ELEMENT_NODE && strcasecmp($c->localName, 'Commodity_segment') === 0) { $commodity = $c; break; }
        }
        if ($commodity) {
            $it['Commodity_code_part1'] = $getText($commodity, 'Commodity_code_part1');
            $it['Commodity_code_part2'] = $getText($commodity, 'Commodity_code_part2');
            $it['Commodity_code_part3'] = $getText($commodity, 'Commodity_code_part3');
        } else {
            $it['Commodity_code_part1'] = $getText($itemNode, 'Commodity_code_part1');
            $it['Commodity_code_part2'] = $getText($itemNode, 'Commodity_code_part2');
            $it['Commodity_code_part3'] = $getText($itemNode, 'Commodity_code_part3');
        }

        // Mass_segment
        $mass = null;
        foreach ($itemNode->childNodes as $c) {
            if ($c->nodeType === XML_ELEMENT_NODE && strcasecmp($c->localName, 'Mass_segment') === 0) { $mass = $c; break; }
        }
        if ($mass) {
            $it['Gross_mass'] = $getText($mass, 'Gross_mass');
            $it['Net_mass'] = $getText($mass, 'Net_mass');
        } else {
            $it['Gross_mass'] = $getText($itemNode, 'Gross_mass');
            $it['Net_mass'] = $getText($itemNode, 'Net_mass');
        }

        $it['Country_of_origin_code'] = $getText($itemNode, 'Country_of_origin_code');
        $it['Item_price'] = $getText($itemNode, 'Item_price');

        // Additional_information_segment
        $add = null;
        foreach ($itemNode->childNodes as $c) {
            if ($c->nodeType === XML_ELEMENT_NODE && strcasecmp($c->localName, 'Additional_information_segment') === 0) { $add = $c; break; }
        }
        if ($add) {
            $it['License_number'] = $getText($add, 'License_number');
            $it['Amount_deducted_from_license'] = $getText($add, 'Amount_deducted_from_license');
            $it['Quantity_deducted_from_license'] = $getText($add, 'Quantity_deducted_from_license');
            $it['Additional_information_code'] = $getText($add, 'Additional_information_code');
            $it['Invoice_reference'] = $getText($add, 'Invoice_reference');
            $it['Rserved_field'] = $getText($add, 'Rserved_field');
        }

        $result['items'][] = $it;
        $lineCounter++;
    }

    return $result;
}

/* -------------------------
   Build SAD XML from arrays
   ------------------------- */
function buildSadXml($fields, $items) {
    $escape = function($s) {
        if ($s === null) return '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= "<SAD_XML>\n";
    $xml .= "  <Customs_clearance_office_code>" . $escape($fields['Customs_clearance_office_code'] ?? '') . "</Customs_clearance_office_code>\n";

    $xml .= "  <Consignee_segment>\n";
    $xml .= "    <Consignee_name>" . $escape($fields['Consignee_name'] ?? '') . "</Consignee_name>\n";
    $xml .= "    <Consignee_address1>" . $escape($fields['Consignee_address1'] ?? '') . "</Consignee_address1>\n";
    $xml .= "    <Consignee_address2>" . $escape($fields['Consignee_address2'] ?? '') . "</Consignee_address2>\n";
    $xml .= "    <Consignee_city>" . $escape($fields['Consignee_city'] ?? '') . "</Consignee_city>\n";
    $xml .= "    <Consignee_zipcode>" . $escape($fields['Consignee_zipcode'] ?? '') . "</Consignee_zipcode>\n";
    $xml .= "  </Consignee_segment>\n";

    $topList = [
        'Declarant_code','User_reference_number','Exporter_code','Manifest_reference_number',
        'Name_of_financially_responsible_body','Country_of_last_consignment'
    ];
    foreach ($topList as $t) {
        $xml .= "  <{$t}>" . $escape($fields[$t] ?? '') . "</{$t}>\n";
    }

    $xml .= "  <Means_of_transport_at_departure_segment>\n";
    $xml .= "    <Identification_of_means_of_transport_at_departure>" . $escape($fields['Identification_of_means_of_transport_at_departure'] ?? '') . "</Identification_of_means_of_transport_at_departure>\n";
    $xml .= "  </Means_of_transport_at_departure_segment>\n";

    $xml .= "  <Container_flag>" . $escape($fields['Container_flag'] ?? '') . "</Container_flag>\n";

    $xml .= "  <Terms_of_delivery_segment>\n";
    $xml .= "    <Terms_of_delivery_code>" . $escape($fields['Terms_of_delivery_code'] ?? '') . "</Terms_of_delivery_code>\n";
    $xml .= "    <Terms_of_delivery_place>" . $escape($fields['Terms_of_delivery_place'] ?? '') . "</Terms_of_delivery_place>\n";
    $xml .= "  </Terms_of_delivery_segment>\n";

    $xml .= "  <Active_means_of_transport_segment>\n";
    $xml .= "    <Active_means_of_transport>" . $escape($fields['Active_means_of_transport'] ?? '') . "</Active_means_of_transport>\n";
    $xml .= "  </Active_means_of_transport_segment>\n";

    $xml .= "  <Invoice_segment>\n";
    $xml .= "    <Invoice_currency_code>" . $escape($fields['Invoice_currency_code'] ?? '') . "</Invoice_currency_code>\n";
    $xml .= "    <Total_amount_invoice>" . $escape($fields['Total_amount_invoice'] ?? '') . "</Total_amount_invoice>\n";
    $xml .= "  </Invoice_segment>\n";

    $xml .= "  <Nature_segment>\n";
    $xml .= "    <Second_of_the_nature_of_transactions>" . $escape($fields['Second_of_the_nature_of_transactions'] ?? '') . "</Second_of_the_nature_of_transactions>\n";
    $xml .= "  </Nature_segment>\n";

    $xml .= "  <Transport_segment>\n";
    $xml .= "    <Place_of_loading-unloading_code>" . $escape($fields['Place_of_loading-unloading_code'] ?? '') . "</Place_of_loading-unloading_code>\n";
    $xml .= "    <Terms_of_payment_code>" . $escape($fields['Terms_of_payment_code'] ?? '') . "</Terms_of_payment_code>\n";
    $xml .= "    <Border_customs_office_code>" . $escape($fields['Border_customs_office_code'] ?? '') . "</Border_customs_office_code>\n";
    $xml .= "    <Location_of_goods>" . $escape($fields['Location_of_goods'] ?? '') . "</Location_of_goods>\n";
    $xml .= "    <Province_of_origin_id>" . $escape($fields['Province_of_origin_id'] ?? '') . "</Province_of_origin_id>\n";
    $xml .= "    <LCL>" . $escape($fields['LCL'] ?? '') . "</LCL>\n";
    $xml .= "    <FCL>" . $escape($fields['FCL'] ?? '') . "</FCL>\n";
    $xml .= "  </Transport_segment>\n";

    $xml .= "  <Bank_information_segment>\n";
    $xml .= "    <Bank_code>" . $escape($fields['Bank_code'] ?? '') . "</Bank_code>\n";
    $xml .= "    <Bank_branch_code>" . $escape($fields['Bank_branch_code'] ?? '') . "</Bank_branch_code>\n";
    $xml .= "    <Bank_file_reference_number>" . $escape($fields['Bank_file_reference_number'] ?? '') . "</Bank_file_reference_number>\n";
    $xml .= "  </Bank_information_segment>\n";

    $xml .= "  <Peza_prepayment>" . $escape($fields['Peza_prepayment'] ?? '') . "</Peza_prepayment>\n";
    $xml .= "  <Sad_Customs_Office>" . $escape($fields['Sad_Customs_Office'] ?? '') . "</Sad_Customs_Office>\n";

    foreach ($items as $it) {
        $xml .= "  <Item_segment>\n";
        $xml .= "    <Item_number>" . $escape($it['Item_number'] ?? '') . "</Item_number>\n";
        $xml .= "    <Marks_and_number_segment>\n";
        $xml .= "      <Marks_and_numbers_pack_part1>" . $escape($it['Marks_and_numbers_pack_part1'] ?? '') . "</Marks_and_numbers_pack_part1>\n";
        $xml .= "      <Marks_and_numbers_pack_part2>" . $escape($it['Marks_and_numbers_pack_part2'] ?? '') . "</Marks_and_numbers_pack_part2>\n";
        $xml .= "      <Number_of_packages>" . $escape($it['Number_of_packages'] ?? '') . "</Number_of_packages>\n";
        $xml .= "      <Type_of_packages>" . $escape($it['Type_of_packages'] ?? '') . "</Type_of_packages>\n";
        $xml .= "      <Description_of_goods_part1>" . $escape($it['Description_of_goods_part1'] ?? '') . "</Description_of_goods_part1>\n";
        $xml .= "      <Description_of_goods_part2>" . $escape($it['Description_of_goods_part2'] ?? '') . "</Description_of_goods_part2>\n";
        $xml .= "      <Description_of_goods_part3>" . $escape($it['Description_of_goods_part3'] ?? '') . "</Description_of_goods_part3>\n";
        $xml .= "    </Marks_and_number_segment>\n";

        $xml .= "    <Commodity_segment>\n";
        $xml .= "      <Commodity_code_part1>" . $escape($it['Commodity_code_part1'] ?? '') . "</Commodity_code_part1>\n";
        $xml .= "      <Commodity_code_part2>" . $escape($it['Commodity_code_part2'] ?? '') . "</Commodity_code_part2>\n";
        $xml .= "      <Commodity_code_part3>" . $escape($it['Commodity_code_part3'] ?? '') . "</Commodity_code_part3>\n";
        $xml .= "    </Commodity_segment>\n";

        $xml .= "    <Mass_segment>\n";
        $xml .= "      <Gross_mass>" . $escape($it['Gross_mass'] ?? '') . "</Gross_mass>\n";
        $xml .= "      <Net_mass>" . $escape($it['Net_mass'] ?? '') . "</Net_mass>\n";
        $xml .= "    </Mass_segment>\n";

        $xml .= "    <Country_of_origin_code>" . $escape($it['Country_of_origin_code'] ?? '') . "</Country_of_origin_code>\n";

        $xml .= "    <Customs_procedure_segment>\n";
        $xml .= "      <Extended_customs_procedure>" . $escape($it['Extended_customs_procedure'] ?? '') . "</Extended_customs_procedure>\n";
        $xml .= "      <National_procedure-additional_code>" . $escape($it['National_procedure-additional_code'] ?? '') . "</National_procedure-additional_code>\n";
        $xml .= "    </Customs_procedure_segment>\n";

        $xml .= "    <Supplementary_units_segment>\n";
        $xml .= "      <Supplementary_units_code>" . $escape($it['Supplementary_units_code'] ?? '') . "</Supplementary_units_code>\n";
        $xml .= "      <Supplementary_units>" . $escape($it['Supplementary_units'] ?? '') . "</Supplementary_units>\n";
        $xml .= "    </Supplementary_units_segment>\n";

        $xml .= "    <Airwaybill_units_segment>\n";
        $xml .= "      <Airway_bill>" . $escape($it['Airway_bill'] ?? '') . "</Airway_bill>\n";
        $xml .= "    </Airwaybill_units_segment>\n";

        $xml .= "    <Item_price>" . $escape($it['Item_price'] ?? '') . "</Item_price>\n";

        $xml .= "    <Additional_information_segment>\n";
        $xml .= "      <License_number>" . $escape($it['License_number'] ?? '') . "</License_number>\n";
        $xml .= "      <Amount_deducted_from_license>" . $escape($it['Amount_deducted_from_license'] ?? '') . "</Amount_deducted_from_license>\n";
        $xml .= "      <Quantity_deducted_from_license>" . $escape($it['Quantity_deducted_from_license'] ?? '') . "</Quantity_deducted_from_license>\n";
        $xml .= "      <Additional_information_code>" . $escape($it['Additional_information_code'] ?? '') . "</Additional_information_code>\n";
        $xml .= "      <Invoice_reference>" . $escape($it['Invoice_reference'] ?? '') . "</Invoice_reference>\n";
        $xml .= "      <Rserved_field>" . $escape($it['Rserved_field'] ?? '') . "</Rserved_field>\n";
        $xml .= "    </Additional_information_segment>\n";

        $xml .= "  </Item_segment>\n";
    }

    $xml .= "</SAD_XML>\n";
    return $xml;
}

/* -------------------------
   Request handling
   ------------------------- */
$invoiceNumber = isset($_GET['invoice']) ? trim($_GET['invoice']) : '';
$fields = [];
$items  = [];
$error  = '';
$source = ''; // 'db' or 'xml' or ''

// 1) Handle XML upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['aeds_xml_file']) && $_FILES['aeds_xml_file']['error'] === UPLOAD_ERR_OK) {
    $fileTmp = $_FILES['aeds_xml_file']['tmp_name'];
    $fileSize = filesize($fileTmp);
    if ($fileSize > 5 * 1024 * 1024) {
        $error = 'Uploaded file too large (max 5MB).';
    } else {
        $xmlText = file_get_contents($fileTmp);
        $parsed = parseSadXmlString($xmlText);
        $fields = $parsed['fields'];
        $items  = $parsed['items'];
        $source = 'xml';
    }
}

// 2) Handle Save as XML (posted form)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_xml'])) {
    // Collect posted header fields
    $postedFields = [];
    foreach (array_keys($headerToFieldMap) as $f) {
        $postedFields[$f] = $_POST[$f] ?? '';
    }
    // Also collect common top-level fields that may not be in mapping
    $extraTop = ['Identification_of_means_of_transport_at_departure','Place_of_loading-unloading_code','Terms_of_payment_code','Border_customs_office_code','Location_of_goods','Province_of_origin_id','LCL','FCL','Bank_code','Bank_branch_code','Bank_file_reference_number','Terms_of_delivery_code','Terms_of_delivery_place','Active_means_of_transport','Second_of_the_nature_of_transactions','Peza_prepayment','Sad_Customs_Office','Invoice_currency_code','Total_amount_invoice'];
    foreach ($extraTop as $t) {
        if (!array_key_exists($t, $postedFields)) $postedFields[$t] = $_POST[$t] ?? '';
    }

    // Collect posted items (arrays)
    $postedItems = [];
    $count = 0;
    if (isset($_POST['Item_number']) && is_array($_POST['Item_number'])) {
        $count = count($_POST['Item_number']);
    } else {
        foreach ($itemToFieldMap as $itemField => $mapVal) {
            if (isset($_POST[$itemField]) && is_array($_POST[$itemField])) { $count = count($_POST[$itemField]); break; }
        }
    }

    for ($i = 0; $i < $count; $i++) {
        $it = [];
        foreach ($itemToFieldMap as $itemField => $mapVal) {
            $val = $_POST[$itemField][$i] ?? '';
            if ($itemField === 'Item_number' && $val === '') $val = (string)($i + 1);
            $it[$itemField] = $val;
        }
        // also include additional fields that might be present in form
        $it['Amount_deducted_from_license'] = $_POST['Amount_deducted_from_license'][$i] ?? ($it['Amount_deducted_from_license'] ?? '');
        $it['Quantity_deducted_from_license'] = $_POST['Quantity_deducted_from_license'][$i] ?? ($it['Quantity_deducted_from_license'] ?? '');
        $postedItems[] = $it;
    }

    $xmlOut = buildSadXml($postedFields, $postedItems);
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="AEDS_output.xml"');
    echo $xmlOut;
    exit;
}

// 3) If GET invoice provided and not loaded from XML, load from DB
if ($invoiceNumber !== '' && $source !== 'xml') {
    $conn = @oci_connect($dbUser, $dbPass, $dbConnString);
    if (!$conn) {
        $err = oci_error();
        $error = $err ? $err['message'] : 'Unable to connect to Oracle DB';
    } else {
        // Header
        $sqlHeader = "SELECT * FROM " . $headerTable . " WHERE " . $invoiceColumn . " = :ci";
        $stidH = @oci_parse($conn, $sqlHeader);
        if ($stidH) {
            oci_bind_by_name($stidH, ':ci', $invoiceNumber);
            if (@oci_execute($stidH)) {
                $rowH = oci_fetch_array($stidH, OCI_ASSOC+OCI_RETURN_NULLS);
                if ($rowH) {
                    foreach ($headerToFieldMap as $fieldName => $mapValue) {
                        $fields[$fieldName] = resolveMapping($mapValue, $rowH);
                    }
                    $source = 'db';
                } else {
                    $error = "Header not found for: " . h($invoiceNumber);
                }
            } else {
                $e = oci_error($stidH);
                $error = $e ? $e['message'] : 'Failed to execute header query';
            }
            oci_free_statement($stidH);
        } else {
            $e = oci_error($conn);
            $error = $e ? $e['message'] : 'Failed to prepare header query';
        }

        // Items
        $sqlItems = "SELECT * FROM " . $itemsTable . " LEFT JOIN PN_INTERCHANGEABLE ON PACKING_SHIPPING_DETAIL.PN = PN_INTERCHANGEABLE.PN_INTERCHANGEABLE WHERE " . $invoiceColumn . " = :ci";
        if (!empty($itemOrderColumn)) $sqlItems .= " ORDER BY " . $itemOrderColumn;
        $stidI = @oci_parse($conn, $sqlItems);
        if ($stidI) {
            oci_bind_by_name($stidI, ':ci', $invoiceNumber);
            if (@oci_execute($stidI)) {
                $lineCounter = 1;
                while ($rowI = oci_fetch_array($stidI, OCI_ASSOC+OCI_RETURN_NULLS)) {
                    $it = [];
                    foreach ($itemToFieldMap as $itemField => $mapValue) {
                        if ($itemField === 'Item_number') {
                            $it['Item_number'] = (string)$lineCounter;
                            continue;
                        }
                        $it[$itemField] = resolveMapping($mapValue, $rowI);
                    }
                    $items[] = $it;
                    $lineCounter++;
                }
            } else {
                $e = oci_error($stidI);
                $error = $e ? $e['message'] : 'Failed to execute items query';
            }
            oci_free_statement($stidI);
        } else {
            $e = oci_error($conn);
            $error = $e ? $e['message'] : 'Failed to prepare items query';
        }

        oci_close($conn);
    }
}

/* Ensure arrays exist */
$fields = $fields ?? [];
$items  = $items ?? [];

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>AEDS Loader — DB or XML Upload</title>
  <style>
    body { font-family: Arial, sans-serif; margin:18px; }
    .section { border:1px solid #ddd; padding:12px; margin:12px 0; border-radius:6px; }
    label { display:block; margin:6px 0; }
    input[type="text"], textarea { width:100%; padding:6px; box-sizing:border-box; }
    .item { border:1px dashed #bbb; padding:10px; margin:10px 0; background:#fafafa; }
    .controls { display:flex; gap:8px; align-items:center; }
    .error { color:#900; }
    .note { color:#444; font-size:13px; }
    .save-row { display:flex; gap:8px; align-items:center; margin-top:12px; }
  </style>
</head>
<body>
  <h1>AEDS Loader — Load from DB or Upload XML</h1>

  <div class="section">
    <form method="get" action="">
      <label>
        PACK_SHIP_NO (load from DB)
        <input type="text" name="invoice" value="<?php echo h($invoiceNumber); ?>" placeholder="Enter PACK_SHIP_NO and press Load">
      </label>
      <div class="controls" style="margin-top:8px;">
        <button type="submit">Load from DB</button>
        <a href="<?php echo strtok($_SERVER["REQUEST_URI"], '?'); ?>" style="margin-left:8px;">Clear</a>
        <?php if ($error): ?><span class="error" style="margin-left:12px;"><?php echo h($error); ?></span><?php endif; ?>
      </div>
      <div class="note" style="margin-top:8px;">
        Or upload an AEDS XML file below to populate the form. Uploaded XML will override DB load.
      </div>
    </form>

    <form method="post" enctype="multipart/form-data" style="margin-top:10px;">
      <label>Upload AEDS XML file (max 5MB)
        <input type="file" name="aeds_xml_file" accept=".xml,application/xml">
      </label>
      <div class="controls" style="margin-top:8px;">
        <button type="submit">Upload and Load XML</button>
      </div>
    </form>
  </div>

  <form method="post" action="">
    <div class="section">
      <h2>Customs / Consignee</h2>
      <label>Customs Office Code
        <input type="text" name="Customs_clearance_office_code" value="<?php echo h($fields['Customs_clearance_office_code'] ?? ''); ?>">
      </label>
      <label>Consignee Name
        <input type="text" name="Consignee_name" value="<?php echo h($fields['Consignee_name'] ?? ''); ?>">
      </label>
      <label>Consignee Address1
        <input type="text" name="Consignee_address1" value="<?php echo h($fields['Consignee_address1'] ?? ''); ?>">
      </label>
      <label>Consignee Address2
        <input type="text" name="Consignee_address2" value="<?php echo h($fields['Consignee_address2'] ?? ''); ?>">
      </label>
      <label>Consignee City
        <input type="text" name="Consignee_city" value="<?php echo h($fields['Consignee_city'] ?? ''); ?>">
      </label>
      <label>Consignee Zipcode
        <input type="text" name="Consignee_zipcode" value="<?php echo h($fields['Consignee_zipcode'] ?? ''); ?>">
      </label>
    </div>

    <div class="section">
      <h2>Header Details</h2>
      <?php foreach (['Declarant_code','User_reference_number','Exporter_code','Manifest_reference_number','Name_of_financially_responsible_body','Country_of_last_consignment'] as $f): ?>
        <label><?php echo h($f); ?>
          <input type="text" name="<?php echo h($f); ?>" value="<?php echo h($fields[$f] ?? ''); ?>">
        </label>
      <?php endforeach; ?>
      <label>Identification of Means of Transport at Departure
        <input type="text" name="Identification_of_means_of_transport_at_departure" value="<?php echo h($fields['Identification_of_means_of_transport_at_departure'] ?? ''); ?>">
      </label>
      <label>Container Flag
        <input type="text" name="Container_flag" value="<?php echo h($fields['Container_flag'] ?? ''); ?>">
      </label>
      <label>Invoice Currency Code
        <input type="text" name="Invoice_currency_code" value="<?php echo h($fields['Invoice_currency_code'] ?? ''); ?>">
      </label>
      <label>Total Amount Invoice
        <input type="text" name="Total_amount_invoice" value="<?php echo h($fields['Total_amount_invoice'] ?? ''); ?>">
      </label>
      <label>Peza Prepayment
        <input type="text" name="Peza_prepayment" value="<?php echo h($fields['Peza_prepayment'] ?? ''); ?>">
      </label>
      <label>SAD Customs Office
        <input type="text" name="Sad_Customs_Office" value="<?php echo h($fields['Sad_Customs_Office'] ?? ''); ?>">
      </label>
    </div>

    <div class="section">
      <h2>Items</h2>

      <?php if (count($items) === 0): ?>
        <div class="item"><strong>No items loaded.</strong></div>
      <?php else: ?>
        <?php foreach ($items as $idx => $it): ?>
          <div class="item">
            <div><strong>Item #<?php echo ($idx + 1); ?></strong></div>
            <?php
              $renderField = function($name, $value, $label = null) {
                  $label = $label ?? $name;
                  echo '<label>' . h($label) . "\n";
                  echo '<input type="text" name="' . h($name) . '[]" value="' . h($value) . '">';
                  echo "</label>\n";
              };
            ?>
            <?php $renderField('Item_number', $it['Item_number'] ?? '', 'Item Number'); ?>
            <?php $renderField('Marks_and_numbers_pack_part1', $it['Marks_and_numbers_pack_part1'] ?? '', 'Marks part1'); ?>
            <?php $renderField('Marks_and_numbers_pack_part2', $it['Marks_and_numbers_pack_part2'] ?? '', 'Marks part2'); ?>
            <?php $renderField('Number_of_packages', $it['Number_of_packages'] ?? '', 'Number of packages'); ?>
            <?php $renderField('Type_of_packages', $it['Type_of_packages'] ?? '', 'Type of packages'); ?>
            <?php $renderField('Description_of_goods_part1', $it['Description_of_goods_part1'] ?? '', 'Description part1 (PN_DESCRIPTION)'); ?>
            <?php $renderField('Description_of_goods_part2', $it['Description_of_goods_part2'] ?? '', 'Description part2 (SN)'); ?>
            <?php $renderField('Description_of_goods_part3', $it['Description_of_goods_part3'] ?? '', 'Description part3'); ?>
            <?php $renderField('Commodity_code_part1', $it['Commodity_code_part1'] ?? '', 'Commodity code part1'); ?>
            <?php $renderField('Commodity_code_part2', $it['Commodity_code_part2'] ?? '', 'Commodity code part2'); ?>
            <?php $renderField('Commodity_code_part3', $it['Commodity_code_part3'] ?? '', 'Commodity code part3'); ?>
            <?php $renderField('Gross_mass', $it['Gross_mass'] ?? '', 'Gross mass'); ?>
            <?php $renderField('Net_mass', $it['Net_mass'] ?? '', 'Net mass'); ?>
            <?php $renderField('Country_of_origin_code', $it['Country_of_origin_code'] ?? '', 'Country of origin code'); ?>
            <?php $renderField('Item_price', $it['Item_price'] ?? '', 'Item price (VALUE)'); ?>
            <?php $renderField('License_number', $it['License_number'] ?? '', 'License number'); ?>
            <?php $renderField('Amount_deducted_from_license', $it['Amount_deducted_from_license'] ?? '', 'Amount deducted from license'); ?>
            <?php $renderField('Quantity_deducted_from_license', $it['Quantity_deducted_from_license'] ?? '', 'Quantity deducted from license'); ?>
            <?php $renderField('Invoice_reference', $it['Invoice_reference'] ?? '', 'Invoice reference'); ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div class="save-row">
        <button type="submit" name="save_xml" value="1">Save as XML</button>
        <span class="note">Download the current form as <strong>AEDS_output.xml</strong>.</span>
      </div>
    </div>
  </form>
</body>
</html>
