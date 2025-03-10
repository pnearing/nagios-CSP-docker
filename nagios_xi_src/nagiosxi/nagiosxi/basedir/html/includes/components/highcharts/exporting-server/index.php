<?php


require_once(dirname(__FILE__) . '/../../../common.inc.php');

// Initialization stuff
pre_init();
init_session();
grab_request_vars(false);
check_prereqs();
check_authentication(false);


/**
 * This file is part of the exporting module for Highcharts JS.
 * www.highcharts.com/license
 * 
 *  
 * Available POST variables:
 *
 * $filename  string   The desired filename without extension
 * $type      string   The MIME type for export. 
 * $width     int      The pixel width of the exported raster image. The height is calculated.
 * $svg       string   The SVG source code to convert.
 */


// Load svg-sanitizer
require_once(__DIR__ . '/svg-sanitizer/data/XPath.php');
require_once(__DIR__ . '/svg-sanitizer/data/AttributeInterface.php');
require_once(__DIR__ . '/svg-sanitizer/data/TagInterface.php');
require_once(__DIR__ . '/svg-sanitizer/data/AllowedAttributes.php');
require_once(__DIR__ . '/svg-sanitizer/data/AllowedTags.php');
require_once(__DIR__ . '/svg-sanitizer/Exceptions/NestingException.php');
require_once(__DIR__ . '/svg-sanitizer/ElementReference/Resolver.php');
require_once(__DIR__ . '/svg-sanitizer/ElementReference/Subject.php');
require_once(__DIR__ . '/svg-sanitizer/ElementReference/Usage.php');
require_once(__DIR__ . '/svg-sanitizer/Helper.php');
require_once(__DIR__ . '/svg-sanitizer/Sanitizer.php');

use enshrined\svgSanitize\Sanitizer;

///////////////////////////////////////////////////////////////////////////////
ini_set('magic_quotes_gpc', 'off');

$type = $_POST['type'];
$svg = (string) $_POST['svg'];
$filename = (string) $_POST['filename'];

// prepare variables
if (!$filename or !preg_match('/^[A-Za-z0-9\-._ ]+$/', $filename)) {
    $filename = 'chart';
}

// check for malicious attack in SVG
if(strpos($svg,"<!ENTITY") !== false || strpos($svg,"<!DOCTYPE") !== false){
    exit("Execution is stopped, the posted SVG could contain code for a malicious attack");
}

// Sanitize the SVG before we load it...
$sanitizer = new Sanitizer();
$svg = $sanitizer->sanitize($svg);

$tempName = md5(rand());

// allow no other than predefined types
if ($type == 'image/png') {
    $typeString = '-m image/png';
    $ext = 'png';
    
} elseif ($type == 'image/jpeg') {
    $typeString = '-m image/jpeg';
    $ext = 'jpg';

} elseif ($type == 'application/pdf') {
    $typeString = '-m application/pdf';
    $ext = 'pdf';

} elseif ($type == 'image/svg+xml') {
    $ext = 'svg';

} else { // prevent fallthrough from global variables
    $ext = 'txt';
}

$outfile = sys_get_temp_dir()."/$tempName.$ext";

if (isset($typeString)) {
    
    // size
    $width = '';
    if ($_POST['width']) {
        $width = (int)$_POST['width'];
        if ($width) $width = "-w $width";
    }

    // generate the temporary file
    if (!file_put_contents(sys_get_temp_dir()."/$tempName.svg", $svg)) { 
        die("Couldn't create temporary file. Check that the directory permissions for
            the ".sys_get_temp_dir()." directory are set to 777.");
    }
    
    // do the conversion
    $output = shell_exec("OPENSSL_CONF=/etc/ssl/ phantomjs highcharts-convert.js -infile ".sys_get_temp_dir()."/$tempName.svg -outfile $outfile -width $width -type $ext");

    // catch error
    if (!is_file($outfile) || filesize($outfile) < 10) {
        echo "<pre>" . htmlentities($output) . "</pre>";
        echo "Error while converting SVG. ";
        
        if (strpos($output, 'SVGConverter.error.while.rasterizing.file') !== false) {
            echo "
            <h4>Debug steps</h4>
            <ol>
            <li>Copy the SVG:<br/><textarea rows=5>" . htmlentities(str_replace('>', ">\n", $svg)) . "</textarea></li>
            <li>Go to <a href='http://validator.w3.org/#validate_by_input' target='_blank'>validator.w3.org/#validate_by_input</a></li>
            <li>Paste the SVG</li>
            <li>Click More Options and select SVG 1.1 for Use Doctype</li>
            <li>Click the Check button</li>
            </ol>";
        }
    }
    
    // stream it
    else {
        header("Content-Disposition: attachment; filename=\"$filename.$ext\"");
        header("Content-Type: $type");
        echo file_get_contents($outfile);
    }
    
    // delete it
    unlink(sys_get_temp_dir()."/$tempName.svg");
    unlink($outfile);

// SVG can be streamed directly back
} else if ($ext == 'svg') {
    header("Content-Disposition: attachment; filename=\"$filename.$ext\"");
    header("Content-Type: $type");
    echo $svg;
    
} else {
    echo "Invalid type";
}
?>