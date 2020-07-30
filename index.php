<?php
//TWIG templating:
require_once './vendor/autoload.php';
//OCLC Metadata service:
require_once 'Metadata_Service.php';
//OCLC Collecton Management service:
require_once 'Collman_Service.php';

//script for "manually" exporting records from WMS
//the user can copy paste OCN's and add them to a list
//and finally stores the list into a .mrc file

//the cmarcedit command, see: https://marcedit.reeset.net/cmarcedit-exe-using-the-command-line
//$cmarcedit_command must contain the path to cmarcedit.exe
$cmarcedit_command = '..\marcedit\cmarcedit';

$target = "mrc"; //default, change this in "xml" when you only want xml output or "mrk" for readable marc format

$export_dir = './WMS_export';

//first and last lines of the marcxml file
$xml_open = '<?xml version="1.0" encoding="UTF-8" ?>'."\n".
'<marc:collection xmlns:marc="http://www.loc.gov/MARC21/slim" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.loc.gov/MARC21/slim http://www.loc.gov/standards/marcxml/schema/MARC21slim.xsd">';
$xml_close = '</marc:collection>';

//$marcxml_dir contains the marcxml file that is worked on
$marcxml_dir = $export_dir.'/marcxml';
//$result_dir contains the file that should be sent to OCLC
$result_dir = $export_dir.'/result';
//$archive_dir contains the archive of the marcxml files
$archive_dir = $export_dir.'/marcxml_archive';
//$archive_dir contains the archive of the marcxml files
$leftover_dir = $export_dir.'/xleftover';

//session variables remember what already has been exported
session_start();

$debug = FALSE;
if (array_key_exists('debug',$_GET)) $debug = TRUE;

$command = '';
$ocn = null;
$already_exported = FALSE;
$marc = '';
$file_name = isset($_SESSION['file']) ? $_SESSION['file'] : '';
$message = '';

function initialize() {
  //this is a new session, the page is opened or the button start again is pressed
  global $export_dir, $marcxml_dir, $result_dir, $archive_dir, $leftover_dir,
  $file_name, $xml_open, $xml_close;

  // remove all session variables
  $message = '';
  session_unset();
  // destroy the session
  session_destroy();
  // start new session
  session_start();
  $_SESSION['file'] = '';
  $_SESSION['count'] = 0;
  $_SESSION['ocns'] = array();

  //make directories when needed
  if (!file_exists($export_dir)) mkdir($export_dir);
  if (!file_exists($marcxml_dir)) mkdir($marcxml_dir);
  if (!file_exists($result_dir)) mkdir($result_dir);
  if (!file_exists($archive_dir)) mkdir($archive_dir);
  if (!file_exists($leftover_dir)) mkdir($leftover_dir);

  //get the filename of the marcxml file if present, otherwise make one
  //names are like: api.export.*.xml
  $here = getcwd();
  chdir($marcxml_dir);
  $files = glob('api.export.*.xml');
  //back to the working directory
  chdir($here);
  if (count($files) > 0) {
    $message = "Leftover working file(s) ('".join("'; '",$files)."') in directory '$marcxml_dir' are moved to directory '$leftover_dir'!";
    foreach ($files as $fn) {
      $moved = rename($marcxml_dir.'/'.$fn,$leftover_dir.'/'.$fn);
    }
  }

  $file_name = 'api.export.'.date("\DYmd.\THis").'.xml';
  //add first lines:
  file_put_contents($marcxml_dir.'/'.$file_name, $xml_open);
  $_SESSION['file'] = $file_name;
  return $message;
}

if (array_key_exists('action',$_GET) && ($_GET['action'] == 'send')) {
  /*the send button is clicked, so
  - close the marcxml file
  - convert the file to a mrc file with marcedit
  - move the marcxml file to the archive_dir
  */
  //add the last line in $closexml
  file_put_contents($marcxml_dir.'/'.$file_name, $xml_close, FILE_APPEND);
  if ($target == "xml") {
    $moved = rename($marcxml_dir.'/'.$file_name,$result_dir.'/'.$file_name);
  }
  else {
    //the file is converted to a file with the same name but extension '.mrc'
    $dest_name = preg_replace('/\.xml$/', '.mrc', $file_name);
    
    //command:cmarcedit -s .filename.xml -d .filename.mrc -xmlmarc
    $command = $cmarcedit_command.' -s '.$marcxml_dir.'/'.$file_name.' -d '.$result_dir.'/'.$dest_name.' -xmlmarc';
    //echo $command;
    $output =  exec($command, $output_cm, $rv);
    if ($rv == 0) {
      $message = join("\n", $output_cm);
      if ($target == "mrk") {
        //convert to mrk

        //remove mrc

      }
    }
    else {
      $message = "Error: MarcEdit not available! ($rv) ".join("; ", $output_cm);
      $moved = copy($marcxml_dir.'/'.$file_name,$result_dir.'/'.$file_name);
    }
  }

  //move xml file to archive
  $here = getcwd();
  chdir($archive_dir);
  $files = glob($file_name.'.*');
  chdir($here);
  $moved = rename($marcxml_dir.'/'.$file_name,$archive_dir.'/'.$file_name.'.'.count($files));

  $message .= initialize();
  //end of handling the send button
}
else if (array_key_exists('ocn',$_GET)) {
  //an OCN has been sent: get a BIB and a LHR record and add them to the file
  $ocn = trim($_GET['ocn']);
  //check if this ocn was already exported
  if (isset($_SESSION['ocns']) && in_array($ocn, $_SESSION['ocns'])) {
    //nothing much to do, ocn is already exported
    $message = "OCN: '$ocn' is already exported.";
  }
  else {
    $bib = new Metadata_Service('keys_collman.php');
    //atom+xml is requered: gives marc xml structured bib record
    $bib->metadata_headers = ['Accept' => 'application/atom+xml'];

    $lhr = new Collection_Management_Service('keys_collman.php');
    //this service does not send marc xml, so atom+json is used
    //atom+json is required, because the returned json is used in the TWIG template to create marc xml
    $lhr->collman_headers = ['Accept' => 'application/atom+json'];

    $succeeded = $bib->get_bib_ocn($ocn);
    if ($succeeded) {
      //check bib record
      if (array_key_exists('record', $bib->metadata_json)) {
        $ldr = $bib->metadata_json["record"][0]["leader"][0];
        if (substr($ldr, 7, 1) == 'm') {
          //only monograph are allowed in the export
          $bib_ocn = '';
          //now lhr's
          $succeeded = $lhr->get_lhrs_of_ocn($ocn);
          if ($succeeded) {
            //convert to marcxml
            $marc = $lhr->json2marc('marcxml',$bib->ocn_001);
            if ($marc) {
              if (array_key_exists("holdingLocation",$lhr->collman["entries"][0]["content"]) &&
              ($lhr->collman["entries"][0]["content"]["holdingLocation"] == "NLVA")) {
                //this is a valid lhr record
                
                //session admin
                $_SESSION['count']++;
                $_SESSION['ocns'][] = $ocn;

                //export to file
                file_put_contents($marcxml_dir.'/'.$file_name, $bib->metadata_str('marcxml') , FILE_APPEND);
                file_put_contents($marcxml_dir.'/'.$file_name, $marc, FILE_APPEND);
              }
              else {
                $message = "Error: Could not get a valid LHR record on ocn '$ocn' from WorldCat. Please try again.";
              }
            }
            else {
              $message = "Error: Could not get a valid LHR record on ocn '$ocn' from WorldCat. Please try again.";
            }
          }
          else {
            $message = "Error: Could not get an LHR record on ocn '$ocn' from WorldCat. Please try again.";
          }
        }
        else {
          $message = "This OCN is not a monograph. Therefore not exported.";
        }
      }
      else {
        $message = "Error: Could not get a valid BIB record on ocn '$ocn' from WorldCat. Please try again.";
      }
    }
    else {
      $message = "Error: Could not get a BIB record on ocn '$ocn' from WorldCat. Please try again.";
    }
  }
}
else {
  $message = initialize();
}
?>
<!DOCTYPE html>
<html>
  <head>
    <title>LHR Export</title>
    <meta charset="utf-8" />
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css" integrity="sha384-Vkoo8x4CGsO3+Hhxv8T/Q5PaXtkKtu6ug5TOeNV6gBiFeWPGFN9MuhOf23Q9Ifjh" crossorigin="anonymous">
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/js/bootstrap.min.js" integrity="sha384-wfSDF2E50Y2D1uUdj0O3uMBJnjuUD4Ih7YwaYd1iqfktj0Uod8GCExl3Og8ifwB6" crossorigin="anonymous"></script>
    <script src="https://kit.fontawesome.com/0102238803.js" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="css/circ.css">
    <script type="text/javascript" src="js/jsoneditor.min.js"></script>
    <script type="text/javascript" src="schema/metadataSchema.js"></script>
  </head>

  <body>
    <div id="editor" class="container m-3"></div>
    <div class="container m-3">
      <button id='submitOCN'>Export LHR data</button><br/><br/>
    </div>
    <div class="container m-3">
      <div class="row">
        <div id="res" class="col"></div>
      </div>
      <div class="row">
        <div id="message" class="col"><?php if (strlen($message) > 0) echo $message; ?></div>
      </div>
      <div class="row">
        <div class="col-md-4">Export file:</div>
        <div class="col"><?php echo $_SESSION['file'] ?></div>
      </div>
      <div class="row">
        <div class="col-md-4">OCN's exported:</div>
        <div class="col"><?php echo $_SESSION['count'] ?></div>
      </div>
      <div class="row">
        <div class="col-md-4">OCN's:</div>
        <div class="col"><?php echo implode(', ',$_SESSION['ocns']) ?></div>
      </div>
      <div class="row pt-3">
        <div class="col">
          <pre><?php if ($ocn) echo str_replace(array('<','>'), array('&lt;','&gt;'), $marc) ?></pre>
        </div>
      </div>
    </div>
    <div class="container m-3">
      <button id='send'>Send file to Syndeo</button>
      <button id='empty'>Start over</button>
    </div>

    <?php if ($debug) { ?>
    <div>
      Publication:
      <pre>
        <?php if ($ocn) echo $bib;?>
      </pre>
      <pre>
        <?php if ($ocn) echo $lhr;?>
      </pre>
    </div>
    <?php } ?>
    <script type="text/javascript" src="js/metadataForm.js"></script>

  </body>

</html>