<?php
//TWIG templating:
require_once './vendor/autoload.php';
//OCLC Metadata service:
require_once 'Metadata_Service.php';
//OCLC Collecton Management service:
require_once 'Collman_Service.php';

//session variables remember what already has been exported
session_start();

$debug = FALSE;
if (array_key_exists('debug',$_GET)) $debug = TRUE;

//location of export files
$marcxml_dir = './WMS_export/marcxml';
$mrc_dir = './WMS_export/mrc';
$archive_dir = './WMS_export/marcxml_archive';

//take care: this will not work on Linux
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
  if (!file_exists($marcxml_dir)) mkdir($marcxml_dir, 0777, TRUE);
  if (!file_exists($mrc_dir)) mkdir($mrc_dir, 0777, TRUE);
  if (!file_exists($archive_dir)) mkdir($archive_dir, 0777, TRUE);
}

//first and last lines of the marcxml file
$xml_open = '<?xml version="1.0" encoding="UTF-8" ?>'."\n".
'<marc:collection xmlns:marc="http://www.loc.gov/MARC21/slim" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.loc.gov/MARC21/slim http://www.loc.gov/standards/marcxml/schema/MARC21slim.xsd">';
$xml_close = '</marc:collection>';

//check export file
//name is api.export.*.xml
$file_name = '';
$here = getcwd();
$message = '';
$command = '';
if (array_key_exists('action',$_GET) && ($_GET['action'] == 'send')) {
  /*the send button is clicked, so
  close the file
  make a mrc file with marcedit
  move the file to the archive_dir
  */
  chdir($marcxml_dir);
  //collect the file name
  $files = glob('api.export.*.xml');
  //back to the working directory
  chdir($here);

  if (count($files) == 0) {
    $message = "Directory '$marcxml_dir' does not contain an export file.";
  }
  else if (count($files) == 1) {
    $file_name = $files[0];
    //add the last line in $closexml
    file_put_contents($marcxml_dir.'/'.$file_name, $xml_close, FILE_APPEND);

    //the file is converted to a file with the same name but extension '.mrc'
    $dest_name = preg_replace('/\.xml$/', '.mrc', $file_name);

    //convert: cmarcedit -s .filename.xml -d .filename.mrc -xmlmarc
    $command = '.\marcedit\cmarcedit -s '.$marcxml_dir.'/'.$file_name.' -d '.$mrc_dir.'/'.$dest_name.' -xmlmarc';
    $output =  exec($command, $output_cm, $rv);
    if ($rv == 0) {
      $message = join("\n", $output_cm);
      //move to archive
      chdir($archive_dir);
      $files = glob($file_name.'.*');
      chdir($here);
      $moved = rename($marcxml_dir.'/'.$file_name,$archive_dir.'/'.$file_name.'.'.count($files));

      // remove all session variables
      session_unset();
      // destroy the session
      session_destroy();
    }
    else {
      $message = "Error: MarcEdit not available! ($rv)";
    }
  }
  else {
    $message = "Error: directory '$marcxml_dir' contains more then 1 file!";
  }
  //end of handling the send button
}
else {
  //reload of the page
  //check file:
  chdir($marcxml_dir);
  $files = glob('api.export.*.xml');
  //back to the working directory
  chdir($here);

  if (count($files) == 0) {
    //no output file yet:
    $file_name = 'api.export.'.date("\DYmd.\THis").'.xml';
    //add first lines:
    file_put_contents($marcxml_dir.'/'.$file_name, $xml_open);
  }
  else if (count($files) == 1) {
    //there is an output file
    $file_name = $files[0];
  }
  else {
    $message = "Error: directory '$marcxml_dir' contains more then 1 file!";
  }
}

$bib = new Metadata_Service('keys_collman.php');
//atom+xml is requered: gives marc xml structured bib record
$bib->metadata_headers = ['Accept' => 'application/atom+xml'];

$lhr = new Collection_Management_Service('keys_collman.php');
//this service does not send marc xml, so atom+json is used
//atom+json is required, because the returned json is used in the TWIG template to create marc xml
$lhr->collman_headers = ['Accept' => 'application/atom+json'];

//check ocn and handle session variables
$ocn = null;
$already_exported = FALSE;
if (array_key_exists('ocn',$_GET)) {
  $ocn = trim($_GET['ocn']);
  //check if this ocn was already exported
  if (isset($_SESSION['ocns']) && in_array($ocn, $_SESSION['ocns'])) $already_exported = TRUE;
}
?>
<!DOCTYPE html>
<html>
  <head>
    <title>LHR Export</title>
    <meta charset="utf-8" />
    <link rel="stylesheet" type="text/css" href="css/bootstrap-combined.min.css" id="theme_stylesheet">
    <link rel="stylesheet" type="text/css" href="css/font-awesome.css" id="icon_stylesheet">
    <link rel="stylesheet" type="text/css" href="css/circ.css">

    <script type="text/javascript" src="js/jquery.min.js"></script>
    <script type="text/javascript" src="js/jsoneditor.min.js"></script>
    <script type="text/javascript" src="schema/metadataSchema.js"></script>
  </head>

  <body>
    <div id="editor"></div>
    <div id="buttons">
      <button id='submitOCN'>Export LHR data</button>
      <button id='empty'>Empty form</button><br/><br/>
      <button id='send'>Send file to Syndeo</button>
    </div>
    <script type="text/javascript" src="js/metadataForm.js"></script>
    <div id="res">
      <?php
      if ($ocn) {
        //decrease in order to increase when the ocn is really ok
        //did we do this ocn before?
        if ($already_exported) {
          echo "<p>OCN: '$ocn' is already exported.</p>";
        }
        else {
          $succeeded = $bib->get_bib_ocn($ocn);
          if ($succeeded) {
            //check bib record
            if (array_key_exists('entry', $bib->metadata) && array_key_exists('record', $bib->metadata_json)) {
              $ldr = $bib->metadata_json["record"][0]["leader"][0];
              if (substr($ldr, 7, 1) == 'm') {
                //only monograph are allowed in the export

                //now lhr's
                $succeeded = $lhr->get_lhrs_of_ocn($ocn);
                if ($succeeded) {
                  //convert to marcxml
                  $marc = $lhr->json2marc('marcxml');
                  if ($marc) {
                    if (array_key_exists("holdingLocation",$lhr->collman["entries"][0]["content"]) &&
                    ($lhr->collman["entries"][0]["content"]["holdingLocation"] == "NLVA")) {
                      //this is a valid lhr record

                      if (!isset($_SESSION['count'])) {
                        //initialize session headers
                        $_SESSION['count'] = 1;
                        $_SESSION['ocns'] = array($ocn);
                      }
                      else {
                        $_SESSION['count']++;
                        $_SESSION['ocns'][] = $ocn;
                      }

                      //export to file
                      file_put_contents($marcxml_dir.'/'.$file_name, $bib->metadata_str('marcxml') , FILE_APPEND);
                      file_put_contents($marcxml_dir.'/'.$file_name, $marc, FILE_APPEND);

                      //feedback on screen
                      echo "<p>OCN's exported: ".$_SESSION['count']."</p>";
                      echo "<p>OCN's: ".implode(', ',$_SESSION['ocns'])."</p>";
                      echo "<h5>API output LHR</h5>";
                      $html = str_replace(array('<','>'), array('&lt;','&gt;'), $marc);
                      echo "<pre>$html</pre>";
                    }
                    else {
                      echo "<p>Error: Could not get a valid LHR record on ocn '$ocn' from WorldCat. Please try again.</p>";
                    }
                  }
                  else {
                    echo "<p>Error: Could not get a valid LHR record on ocn '$ocn' from WorldCat. Please try again.</p>";
                  }
                }
                else {
                  echo "<p>Error: Could not get an LHR record on ocn '$ocn' from WorldCat. Please try again.</p>";
                }
              }
              else {
                echo "<p>This OCN is not a monograph. Therefore not exported.</p>";
              }
            }
            else if (array_key_exists('error',$bib->metadata)) {
              echo "<p>Error:</p>";
              foreach ($bib->metadata['error'] as $err) {
                if (array_key_exists('code',$err) && array_key_exists('message',$err)) echo " - ".$err['code'][0].": ".$err['message'][0]."<br/>";
              }
            }
            else {
              echo "<p>Error: Could not get a valid BIB record on ocn '$ocn' from WorldCat. Please try again.</p>";
            }
          }
          else {
            echo "<p>Error: Could not get a BIB record on ocn '$ocn' from WorldCat. Please try again.</p>";
          }
        }
      }
      else if (strlen($message) > 0) {
        echo "<pre>$message</pre>";
      }
      ?>
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

  </body>

</html>