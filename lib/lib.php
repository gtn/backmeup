<?php
function filenameReplaceBadChars( $filename, $folder=false ) {

	$patterns = array(
			"/\\s/",  # Leerzeichen
			"/\\&/",  # Kaufmaennisches UND
			"/\\+/",  # Plus-Zeichen
			"/\\</",  # < Zeichen
			"/\\>/",  # > Zeichen
			"/\\?/",  # ? Zeichen
			"/\"/",   # " Zeichen
			"/\\:/",  # : Zeichen
			"/\\|/",  # | Zeichen
			"/\\\\/", # \ Zeichen
			"/\\*/"   # * Zeichen
	);
	if(!$folder)
		$patterns[] = "/\\//";  # / Zeichen

	$replacements = array(
			" ",
			" ",
			" ",
			" ",
			" ",
			" ",
			" ",
			" ",
			" ",
			" ",
			" "
	);
	if(!$folder)
		$replacements[] = "";

	return preg_replace( $patterns, $replacements, $filename );
	 
}
?>