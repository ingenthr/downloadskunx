<?php

$accessKey = 'REPLACE ME';
$secretKey = 'REPLACE ME';

$mimetype = (@$_GET['type'] === 'json' ? 'application/json' : 'text/html');

if ($_SERVER['SERVER_NAME'] === 'localhost' && @$_GET['fromS3'] !== 'true') {
  $contents = require_once 'contents.php';
} else if ($_SERVER['SERVER_NAME'] === 'new.stage.couchbase.com' || @$_GET['fromS3'] === 'true') {
  require_once 'S3.php';
  $s3 = new S3($accessKey, $secretKey);
  $contents = $s3->getBucket('packages.couchbase.com', 'releases', null, null, '|');
  if (function_exists('cache_set')) {
    cache_set('s3downloadsListing', $contents);
  }
} else {
  $contents = cache_get('s3downloadsListing');
}

// cmp is a comparitor function used in collectFor.
// It really should be a lamda, but alas we're still on PHP 5.2.x
function cmp($a, $b) {
  if ($a['version'] == $b['version']) {
    return 0;
  }
  return ($a['version'] > $b['version']) ? -1 : 1;
}

function collectFor($product_string, $contents) {

  $platform_names = array('rpm' => array('title'=>'Red Hat',  'icon'=>'redhat'),
                          'deb' => array('title'=>'Ubuntu',   'icon'=>'ubuntu'),
                          'exe' => array('title'=>'Windows',  'icon'=>'windows'),
                          'dmg' => array('title'=>'Mac OS X', 'icon'=>'mac'));

  $output = array('id' => $product_string,
                  'title' => ucwords(str_replace('-', ' ', $product_string)),
                  'releases' => array());

  $last_version = 0;
  foreach ($contents as $file) {
    $url = $file['name'];
    list(, $version, $filename) = explode('/', $file['name']);

    if ($filename === "") continue;
    else if ($version < 1.7) continue;
    else if (!is_numeric($version[0])) continue;
    else if (strpos($version, '-') !== false) continue;
    else if ($filename === 'index.html') continue;
    else if (substr($filename, -3, 3) === 'md5'
      || substr($filename, -3, 3) === 'xml'
      || substr($filename, -3, 3) === 'txt'
      || substr($filename, 0, 10) === 'northscale'
      || substr($filename, 0, 15) === 'CouchbaseServer') continue;
    else if ($product_string === 'couchbase-server'
            && substr($filename, 0, strlen($product_string)) !== $product_string
            && substr($filename, 0, strlen('membase-server')) !== 'membase-server') continue;
    else if ($product_string !== 'couchbase-server'
            && substr($filename, 0, strlen($product_string)) !== $product_string) continue;

    if (count($output['releases']) > 0) {
      $last_entry =& $output['releases'][count($output['releases'])-1];
    }

    $md5 = (array_key_exists($url . '.md5', $contents) ? $url . '.md5' : null);

    // source only package...no edition
    if (preg_match("/([A-Za-z\-]*)([-](enterprise|community)[_])?(_src-([0-9\.]*)|([0-9\.\-a-z]*)_src)[\.|_](.*)/", $filename, $matches) > 0) {
      list(, $product, , $edition, , $version, $alt_version, $postfix) = $matches;
      $version = $version === "" ? $alt_version : $version;
      $type = 'source';
    } else {
      preg_match("/([A-Za-z\-]*)([_]?(win2008)?_(x86)[_]?(64)?)?[_]([0-9\.]*)[\.|_](.*)/",
        $filename, $matches);
      list(, $product, , , $arch, $bits, $version, $postfix) = $matches;

      preg_match("/.*(enterprise|community)$/", $product, $edition_matches);
      if (count($edition_matches) > 1) {
        list (, $edition) = $edition_matches;
      } else {
        $edition = 'community';
      }

      if ($bits === '64') $arch .= '/64';

      if (substr($postfix, 0, 9) === 'setup.exe') $type = 'exe';
      else if (substr($postfix, 0, 3) === 'rpm')  $type = 'rpm';
      else if (substr($postfix, 0, 3) === 'deb')  $type = 'deb';
      else if (substr($postfix, 0, 3) === 'dmg')  $type = 'dmg';
    }

    // if the version string isn't found in the filename, than it's not one of
    // the typical patterns, and we don't care about it...at least we hope not.
    if ($version === null) continue;

    $created = date('Y-m-d', $file['time']);

    $urls = array_filter(compact('url', 'filename', 'md5'));
    if ($last_version === /*this*/ $version) {
      // append to the previous entry
      if (is_array($last_entry['installers']) && array_key_exists($type, $last_entry['installers'])) {
        $last_entry['installers'][$type][$arch][$edition] = $urls;
      } else if ($type === 'source') {
        $last_entry['source'] = $urls;
      } else {
        $last_entry['installers'][$type] = array_merge($platform_names[$type], array($arch => array($edition => $urls)));
      }
    } else {
      // create a new entry
      if ($type !== 'source') {
        $output['releases'][] = compact('version', 'created')
          + array('installers'=>
              array($type =>
                array_merge($platform_names[$type], array($arch => array($edition => $urls))
                )
              )
            );
      } else {
        $output['releases'][] = compact('version', 'created')
          + array($type => $urls);
      }
    }

    $last_version = $version;

    unset($version, $product, $edition, $type, $arch, $bits, $version, $postfix, $matches, $edition_matches);
  }

  usort($output['releases'], 'cmp');

  return $output;
}

header('Content-Type: ' . $mimetype);

$product_names = array('couchbase-server', 'moxi-server');
$products = array();

foreach ($product_names as $product_name) {
  $products[] = collectFor($product_name, $contents);
}

$products = array('products' => $products);

if ($mimetype === 'application/json') {
  print_r(json_encode($products));
} else {
  require_once 'Mustache.php';
  $m = new Mustache();
  echo $m->render(file_get_contents('downloads.html'), $products, array('installer' => file_get_contents('installer.html')));
}