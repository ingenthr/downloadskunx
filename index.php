<?php

$accessKey = 'REPLACE ME';
$secretKey = 'REPLACE ME';

// Swap the true/false to enable/disable by_version output:
define('BY_VERSION', (isset($_GET['by_version']) && $_GET['by_version'] === 'true' ? true : false)); // for /downloads
//define('BY_VERSION', (isset($_GET['by_version']) && $_GET['by_version'] === 'true' ? false : true)); // for /downloads-all

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

      preg_match("/(.*)[-](enterprise|community)$/", $product, $edition_matches);
      if (count($edition_matches) > 1) {
        list (, $product, $edition) = $edition_matches;
      } else {
        $edition = 'community';
      }

      $product = str_replace('-server', '', $product);

      if ($bits === '64') $arch .= '/64';

      if (substr($postfix, 0, 9) === 'setup.exe') $type = 'exe';
      else if (substr($postfix, 0, 3) === 'rpm')  $type = 'rpm';
      else if (substr($postfix, 0, 3) === 'deb')  $type = 'deb';
      else if (substr($postfix, 0, 3) === 'dmg')  $type = 'dmg';
    }

    // if the version string isn't found in the filename, than it's not one of
    // the typical patterns, and we don't care about it...at least we hope not.
    if ($version === null) continue;
    $major_version = substr($version, 0, strpos($version, '.', 3));
    // PHP5.3 edition: $major_version = strstr($version, '.', true);

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
        $output['releases'][] = compact('major_version', 'version', 'created', 'product')
          + array('installers'=>
              array($type =>
                array_merge($platform_names[$type], array($arch => array($edition => $urls))
                )
              )
            );
      } else {
        $output['releases'][] = compact('major_version', 'version', 'created', 'product')
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

if (BY_VERSION === true) {
  $products_by_major_version = array();
  foreach ($products as $product) {
    foreach ($product['releases'] as $release) {
      if (isset($major_version) && $major_version === $release['major_version']) {
        $current_product['releases'][] = $release;
      } else {
        if (isset($current_product)) {
          $products_by_major_version[] = $current_product;
        }
        $major_version = $release['major_version'];
        $current_product = array(
          'id'=> $product['id'] . '-' . str_replace('.', '-', $major_version),
          'title'=>$product['title'] . ' ' . $major_version,
          'releases'=> array($release)
        );
      }
    }
  }

  // Throw the final current_product into the stack.
  $products_by_major_version[] = $current_product;

  $products = $products_by_major_version;
}
$products = array('products' => $products);

if ($mimetype === 'application/json') {
  print_r(json_encode($products));
} else {
  require_once 'Mustache.php';
  $m = new Mustache();

  $main = <<<EOD
{{>header}}
<style type="text/css">
.cb-download-form{top:44px;z-index:1}
.cb-download{display:none}
</style>
<div>
{{#products}}
<div style="position:relative" id="{{id}}">
<div class="cb-download-desc">
  Enterprise Edition or Community Edition. <a href="/couchbase-server/editions">Which one is right for me?</a></div>
<h3 class="step-1">
  {{title}} Downloads</h3>
<div class="cb-download-form">
  <form>
    <select>
    {{#releases}}
      <option name="{{version}}">{{version}}</option>
    {{/releases}}
    </select>
  </form>
</div>
{{#releases}}
<div class="cb-download" data-version="{{version}}">
  <div class="cb-download-head-top">
    <div class="download-title">
      <h3>
        Operating System</h3>
    </div>
    <div class="head-title">
      <h2>
        Enterprise Edition</h2>
      <p>
        Most stable binaries certified for production</p>
    </div>
    <div class="head-title">
      <h2>
        Community Edition</h2>
      <p>
        Binaries recommended for non-commercial use</p>
    </div>
    <div class="download-free">
      Free Download</div>
    <div class="download-free">
      Free Download</div>
  </div>
  {{#installers}}
    {{#deb}}<div class="cb-download-row">{{>installer}}</div>{{/deb}}
    {{#rpm}}<div class="cb-download-row">{{>installer}}</div>{{/rpm}}
    {{#exe}}<div class="cb-download-row">{{>installer}}</div>{{/exe}}
  {{/installers}}
  {{#source}}
  <div class="cb-download-row-last">
    <div class="download-title">
      <h4>Download Sources Files:</h4>
    </div>
    <div class="download-col1"></a></div>
    <div class="download-col2">
      <a href="{{url}}">{{filename}}</a></div>
  </div>
  {{/source}}
</div>
{{/releases}}
</div>
{{/products}}
</div>
<p class="cb-all-downloads">
  <b><a class="first" href="/downloads-all">View all of our Downloads</a></b> &nbsp;&nbsp; <a href="/couchbase-single-server">Looking for Couchbase Single Server?</a></p>
<div class="container-6-inner">
  <div class="grid-4 first">
    <h3 class="step-2">
      Watch how to quick start your cluster</h3>
    <iframe src="http://player.vimeo.com/video/35242219?title=0&amp;byline=0&amp;portrait=0&amp;color=A30A0A" width="644" height="362" frameborder="0" webkitAllowFullScreen mozallowfullscreen allowFullScreen></iframe></div>
  <div class="grid-2 last">
    <h3 class="step-3">
      Download client libraries</h3>
    <div class="sidebar-width">
      <div class="sidebar-box" style="height:322px">
        <div class="section">
          <ul>
            <li class="first">
              <a href="/develop/java/current">Java Client Library</a></li>
            <li>
              <a href="/develop/net/current">.NET Client Library</a></li>
            <li>
              <a href="/develop/php/current">PHP Client Library</a></li>
            <li>
              <a href="/develop/Ruby/current">Ruby Client Library</a></li>
            <li>
              <a href="/develop/c/current">C Client Library</a></li>
            <li class="last">
              <a href="/develop">Additional SDK&#39;s</a></li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</div>
<script type="text/javascript" src="js/jquery.min.js"></script>
<script type="text/javascript">
jQuery(function($) {
  {{#products}}
  $('#{{id}}').find('.cb-download-form select').change(function(ev) {
    $('#{{id}}').find('.cb-download').fadeOut('fast');
    $('#{{id}}').find('.cb-download[data-version=' + $(ev.target).val() + ']').fadeIn('fast');
  }).trigger('change');
  {{/products}}
});
</script>
{{>footer}}
EOD;

  $installer = <<<EOD
    <div class="download-title">
      <div class="logo">
        <img alt="" src="/sites/default/files/uploads/all/images/logo_{{icon}}.png" /></div>
      <h4>
        64-bit {{title}}</h4>
      <h4>
        32-bit {{title}}</h4>
      <div class="instructions">
        <a href="#">Install instructions</a></div>
    </div>
    {{#x86/64.enterprise}}
    <div class="download-col1">
      <p>
        <a href="http://packages.couchbase.com/{{x86/64.enterprise.url}}">{{version}} Release</a> | <a href="http://packages.couchbase.com/{{x86/64.enterprise.md5}}">[md5]</a></p>
      <p>
        <a href="http://packages.couchbase.com/{{x86.enterprise.url}}">{{version}} Release</a> | <a href="http://packages.couchbase.com/{{x86.enterprise.md5}}">[md5]</a></p>
      <p class="notes">
        <a href="http://www.couchbase.com/docs/{{product}}-manual-{{major_version}}/{{product}}-server-rn.html">Release Notes</a> &nbsp;&nbsp; <a href="http://www.couchbase.com/docs/{{product}}-manual-{{major_version}}/">Manual</a></p>
    </div>
    {{/x86/64.enterprise}}
    {{^x86/64.enterprise}}
    <div class="download-col1">
      <p>N/A</p>
      <p>N/A</p>
    </div>
    {{/x86/64.enterprise}}

    {{#x86/64.community}}
    <div class="download-col2">
      <p>
        <a href="http://packages.couchbase.com/{{x86/64.community.url}}">{{version}} Release</a> | <a href="http://packages.couchbase.com/{{x86/64.community.md5}}">[md5]</a></p>
      <p>
        <a href="http://packages.couchbase.com/{{x86.community.url}}">{{version}} Release</a> | <a href="http://packages.couchbase.com/{{x86.community.md5}}">[md5]</a></p>
      <p class="notes">
        <a href="http://www.couchbase.com/docs/{{product}}-manual-{{major_version}}/{{product}}-server-rn.html">Release Notes</a> &nbsp;&nbsp; <a href="http://www.couchbase.com/docs/{{product}}-manual-{{major_version}}/">Manual</a></p>
    </div>
    {{/x86/64.community}}
    {{^x86/64.community}}
    <div class="download-col1">
      <p>N/A</p>
      <p>N/A</p>
    </div>
    {{/x86/64.community}}
EOD;

  $partials = compact('installer');
  if ($_SERVER['SERVER_NAME'] === 'localhost') {
    $partials += array('header' => file_get_contents('header.html'),
                      'footer' => file_get_contents('footer.html'));
  }
  echo $m->render($main, $products, $partials);
}