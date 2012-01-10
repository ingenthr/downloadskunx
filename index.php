<?php

require_once 'S3.php';

$contents = require_once 'contents.php';

$mimetype = (@$_GET['type'] === 'html' ? 'text/html' : 'application/json');

/*
$s3 = new S3('accessKey', 'secretKey');
$contents = $s3->getBucket('packages.couchbase.com', 'releases', null, null, '|');
*/

function collectFor($product_string) {
  global $contents;

  $output = array('name' => $product_string,
              'releases' => array());

  $last_version = 0;
  foreach ($contents as $file) {
    $url = $file['name'];
    list(, $version, $filename) = explode('/', $file['name']);

    if ($filename === "") continue;
    else if (!is_numeric($version[0])) continue;
    else if (strpos($version, '-') !== false) continue;
    else if ($filename === 'index.html') continue;
    else if (substr($filename, 0, strlen($product_string)) !== $product_string
      || substr($filename, -3, 3) === 'xml'
      || substr($filename, -3, 3) === 'txt'
      || substr($filename, 0, 10) === 'northscale'
      || substr($filename, 0, 15) === 'CouchbaseServer') continue;

    if (count($output['releases']) > 0) {
      $last_entry =& $output['releases'][count($output['releases'])-1];
    }

    // source only package...no edition
    if (preg_match("/([A-Za-z\-]*)_src-([0-9\.]*)[\.|_](.*)/", $filename, $matches) > 0) {
      list(, $product, $version, $postfix) = $matches;
    } else {
      preg_match("/([A-Za-z\-]*)[-](enterprise|community)([_]?(win2008)?_(x86)[_]?(64)?)?[_]([0-9\.]*)[\.|_](.*)/",
        $filename, $matches);

      list(, $product, $edition, , $type, $arch, $bits, $version, $postfix) = $matches;

      if ($bits === '64') $arch .= '/64';

      if ($type === 'win2008')     $type = 'exe';
      else if (substr($postfix, 0, 3) === 'rpm') $type = 'rpm';
      else if (substr($postfix, 0, 3) === 'deb') $type = 'deb';
      else                         $type = 'source';
    }

    // if the version string isn't found in the filename, than it's not one of
    // the typical patterns, and we don't care about it...at least we hope not.
    if ($version === null) continue;

    if (substr($filename, -3, 3) === 'md5') {
      if ($type !== 'source') {
        $last_entry['installers'][$type][$arch][$edition]['md5'] = $file['name'];
      } else {
        $last_entry['source'][$edition]['md5'] = $file['name'];
      }
      continue;
    }

    $created = date('Y-m-d', $file['time']);

    if ($last_version === /*this*/ $version) {
      // append to the previous entry
      if (array_key_exists($type, $last_entry['installers'])) {
        $last_entry['installers'][$type][$arch][$edition] = array_filter(compact('url'));
      } else if ($type === 'source') {
        $last_entry['source'] = array($edition => array_filter(compact('url', 'filename')));
      } else {
        $last_entry['installers'][$type] = array($arch => array($edition => array_filter(compact('url'))));
      }
    } else {
      // create a new entry
      if ($type !== 'source') {
        $output['releases'][] = compact('version', 'created')
          + array('installers'=>
              array($type =>
                array($arch =>
                  array($edition => array_filter(compact('url')))
                )
              )
            );
      } else {
        $output['releases'][] = compact('version', 'created')
          + array($type =>
              array($edition => array_filter(compact('url', 'filename')))
            );
      }
    }

    $last_version = $version;

    unset($version, $product, $edition, $type, $arch, $bits, $version, $postfix);
  }

  function cmp($a, $b) {
    if ($a == $b) {
      return 0;
    }
    return ($a > $b) ? -1 : 1;
  }
  usort($output['releases'], 'cmp');

  return $output;
}

header('Content-Type: ' . $mimetype);

$membase_releases = collectFor('membase-server');

if ($mimetype === 'application/json') {
  print_r(json_encode($membase_releases));
} else {
  require_once 'Mustache.php';
  $m = new Mustache();
  echo $m->render(file_get_contents('downloads.html'), $membase_releases, array('installer' => file_get_contents('installer.html')));
}