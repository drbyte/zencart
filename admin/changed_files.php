<?php
// Changed files report by That Software Guy
// https://www.thatsoftwareguy.com/zencart_changed_files.html
require('includes/application_top.php');
if (file_exists(DIR_WS_LANGUAGES . $_SESSION['language'] . '/' . 'changed_files.php')) {
  include(DIR_WS_LANGUAGES . $_SESSION['language'] . '/' . 'changed_files.php');
}

if (!class_exists('RecursiveDirectoryIterator') || (!class_exists('RecursiveIteratorIterator'))) {
   die("RecursiveDirectoryIterator and/or RecursiveIteratorIterator have not been defined on your system"); 
}

// Add any filetypes you do not want reported 
$filetype_exclusions = array(".log", ".txt", ".sql"); 

$file_exclusions = array(
    '.git/',
    '.gitignore',
    '.gitattributes',
    '.phpunit',
    'not_for_release',
    '.idea/',
    'dev-',
    'vendor/',
);

$start_dir = DIR_FS_CATALOG; 
$it = new RecursiveDirectoryIterator($start_dir);
$files = array(); 
foreach(new RecursiveIteratorIterator($it) as $file)
{
    if (!is_dir($file)) { 
        $mtime = @filemtime($file); 
        $size = @filesize($file); 
        if ($mtime == FALSE) continue; 
        $type = @pathinfo($file, PATHINFO_EXTENSION); 
        if ($type == FALSE) continue; 
        $type = "." . $type; 
        if (in_array($type, $filetype_exclusions)) continue; 
        foreach ($file_exclusions as $excluded) {
          if (strstr($file, $start_dir . $excluded)) continue 2;
        }
        $files[] = array('name' => $file, 
                         'size' => $size, 
                         'mtime' => $mtime); 
    }
}
ksort($files);
// usort($files, "file_cmp");

$vs = new VersionServer;
$manifest = $vs->getFilesManifest('156');
$expectedFiles = json_decode($manifest, true);

?>
<!doctype html public "-//W3C//DTD HTML 4.01 Transitional//EN">
<html <?php echo HTML_PARAMS; ?>>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=<?php echo CHARSET; ?>">
<title><?php echo TITLE; ?></title>
<link rel="stylesheet" type="text/css" href="includes/stylesheet.css">
<link rel="stylesheet" type="text/css" href="includes/cssjsmenuhover.css" media="all" id="hoverJS">
<link rel="stylesheet" type="text/css" href="includes/admin_access.css" />
<script language="javascript" src="includes/menu.js"></script>
<script language="javascript" src="includes/general.js"></script>
<script type="text/javascript">
  <!--
  function init()
  {
    cssjsmenu('navbar');
    if (document.getElementById)
    {
      var kill = document.getElementById('hoverJS');
      kill.disabled = true;
    }
  }
  // -->
</script>
</head>
<body onload="init()">
<!-- header //-->
<?php require(DIR_WS_INCLUDES . 'header.php'); ?>
<!-- header_eof //-->

<!-- body //-->
<div id="pageWrapper">
  <h1><?php echo HEADING_TITLE ?></h1>
<?php 
foreach ($files as $file) {
    $name = str_replace(DIR_FS_CATALOG, "/", $file['name']);
    $matched = false;
    $foundHash = '';
    $fileBelongs = isset($expectedFiles[$name]);

    if ($fileBelongs) {
        $foundHash = md5(file_get_contents($file['name']));
        $matched = $foundHash == $expectedFiles[$name]['hash'];
        unset($expectedFiles[$name]);
    }

    if ($matched) continue;
    echo $name . '&nbsp;&nbsp;' . date('Y-m-d H:i:s', $file['mtime']) . '&nbsp;&nbsp;' . $file['size'] . FILE_BYTES;
    echo ' &nbsp; (';
    if ($matched) echo '<strong style="color:green">MATCH</strong>';
    if ($fileBelongs && !$matched) echo '<strong style="color:red">CHANGED</strong>';
    if (!$fileBelongs) echo '<strong style="color:blue">NEW</strong>';
    echo ') ';
    echo '<br>';
}
?>
</div>
<!-- body_eof //-->

<div class="bottom">
<!-- footer //-->
<?php require(DIR_WS_INCLUDES . 'footer.php'); ?>
<!-- footer_eof //-->
</div>
<br>
</body>
</html>
<?php require(DIR_WS_INCLUDES . 'application_bottom.php'); ?>
<?php
function file_cmp($a, $b) {
   if ($a['mtime'] == $b['mtime'])
      return 0;
   if ($a['mtime'] < $b['mtime'])
      return 1;
   return -1;
}
