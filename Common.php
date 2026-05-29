<?php

	function ConnectDB(&$link) {
		$link = oci_connect("mm", "mm123", "reports") or die("ConnectDB : " . oci_error());
	}

	function CloseDB(&$link) {
		OCILogoff($link);
	}

	function OpenQuery($query, $link, &$result) {

		$result = OCIParse($link, $query);
		OCIExecute($result, OCI_DEFAULT) or die("OpenQuery : " . oci_error());
	}
	
	function GetCIHeader($TRAX_CI) {

		$BGCount = 0;
		$BGColor = "";
		$result = NULL;
		
		$SELECT = "";
		$FROM = "";
		$WHERE = "";
		$GROUP = "";
		$ORDER = "";

		$SELECT = "Select *";
		$FROM = "from PACKING_SHIPPING_HEADER";
		$WHERE = "where PACK_SHIP_NO = " . $TRAX_CI;
		
		$sSQL = $SELECT . " " . $FROM . " " . $WHERE . " " . $GROUP . " " . $ORDER;

		ConnectDB($link);

		OpenQuery($sSQL, $link, $result);

		CloseDB($link);

		OCIFetch($result);
		
		$Table = "<table border='0' cellspace='1'  width='100%'>";
		$Table = $Table . "<tr>";
		$Table = $Table . "<td valign='top'><p>DECLARATION</p></td>";
		$Table = $Table . "<td valign='top'><p>EX3</p></td>";
		$Table = $Table . "</tr>";
		$Table = $Table . "<tr>";
		$Table = $Table . "<td valign='top'><p>EXPORTER</p></td>";
		$Table = $Table . "<td valign='top'><p>LUFTHANSA TECHNIK PHILIPINES, INC. MACRO ASIA SPECIAL ECONOMIC ZONE VILLAMOR AIR BASE PASAY CITY 1309</p></td>";
		$Table = $Table . "</tr>";
		$Table = $Table . "<tr>";
		$Table = $Table . "<td valign='top'><p>TIN</p></td>";
		$Table = $Table . "<td valign='top'><p>205275073000</p></td>";
		$Table = $Table . "</tr>";
		$Table = $Table . "<tr>";
		$Table = $Table . "<td valign='top'><p>CONSIGNEE NAME</p></td>";
		$Table = $Table . "<td valign='top'><p>" . ociresult($result,"SHIP_TO_NAME") . "</p></td>";
		$Table = $Table . "</tr>";
		$Table = $Table . "<tr>";
		$Table = $Table . "<td valign='top'><p>CONSIGNEE ADDRESS 1</p></td>";
		$Table = $Table . "<td valign='top'><p>" . ociresult($result,"SHIP_TO_ADDRESS_1") . "</p></td>";
		$Table = $Table . "</tr>";
		$Table = $Table . "<tr>";
		$Table = $Table . "<td valign='top'><p>CONSIGNEE ADDRESS 2</p></td>";
		$Table = $Table . "<td valign='top'><p>" . ociresult($result,"SHIP_TO_ADDRESS_2") . "</p></td>";
		$Table = $Table . "</tr>";
		$Table = $Table . "</table>";
		
		oci_free_statement($result);

		return $Table;
	}
	
?>