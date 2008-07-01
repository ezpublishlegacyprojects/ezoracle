<?php

// Copy this file to root directory of your eZ publish installation.

function _die( $value )
{
    header( $_SERVER['SERVER_PROTOCOL'] . " 500 Internal Server Error" );
    die( $value );
}

if ( !isset( $_SERVER['SCRIPT_URL'] ) ) {
	_die( "Please use a virtual hosting setup to access this script.\n" );
}
$filename = ltrim( $_SERVER['SCRIPT_URL'], "/" );

if ( !function_exists( 'ocilogon' ) )
    _die( "PECL oci8 extension (http://pecl.php.net/package/oci8) is required to use Oracle clustering functionality.\n" );

if ( !( $db = @ocilogon( STORAGE_USER, STORAGE_PASS, STORAGE_DB ) ) )
    _die( "Unable to connect to storage server.\n" );

$query = "SELECT name, filesize, datatype, mtime, lob FROM ezdbfile WHERE name_hash = '" . md5( $filename ) . "'";
$statement = ociparse( $db, $query );
if ( !ociexecute( $statement, OCI_DEFAULT ) )
    _die( "Error fetching image.\n" );

//$chunkSize = STORAGE_CHUNK_SIZE;
/// @todo test: is ocifetchinto faster?
/// @todo test for php version: with <= 4.2.1 this syntax is not available!
if ( ocifetchstatement( $statement, $rows, 0, 1, OCI_FETCHSTATEMENT_BY_ROW ) )
{
    ocifreestatement( $statement );
	$row = $rows[0];
    // output HTTP headers
    $path     = $row['NAME'];
    $size     = $row['FILESIZE'];
    $mimeType = $row['DATATYPE'];
    $mtime    = $row['MTIME'];
    $mdate    = gmdate( 'D, d M Y H:i:s T', $mtime );

    header( "Content-Length: $size" );
    header( "Content-Type: $mimeType" );
    header( "Last-Modified: $mdate" );
    /* Set cache time out to 10 minutes, this should be good enough to work around an IE bug */
    header( "Expires: ". gmdate('D, d M Y H:i:s', time() + 6000) . 'GMT' );
    header( "Connection: close" );
    header( "X-Powered-By: eZ Ppublish" );
    header( "Accept-Ranges: none" );
    header( 'Served-by: ' . $_SERVER["SERVER_NAME"] );

    // output image data
    echo $row['LOB'];
}
else
{
    ocifreestatement( $statement );
    header( $_SERVER['SERVER_PROTOCOL'] . " 404 Not Found" );
?>
<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
<HTML><HEAD>
<TITLE>404 Not Found</TITLE>
</HEAD><BODY>
<H1>Not Found</H1>
The requested URL <?php echo htmlspecialchars( $filename ); ?> was not found on this server.
</BODY></HTML>
<?php
}
// oci_close does not exist on php 4
//oci_close( $db );
?>