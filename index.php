<?php

$accessKey = 'REPLACE ME';
$secretKey = 'REPLACE ME';

define('IS_STAGING', $_SERVER['SERVER_NAME'] === 'new.stage.couchbase.com');
define('IS_LOCAL', $_SERVER['SERVER_NAME'] === 'localhost');
define('INCLUDE_PATH', IS_LOCAL ? '' : '/var/www/domains/couchbase.com/new.stage/htdocs/sites/all/libraries/download/');

if (false /* show main products download page */) {
  // Swap the true/false to enable/disable by_version output:
  // for /download
  define('BY_VERSION', false);
  $product_names = array('couchbase-server');
  $develop_node_id = null;
} else {
  // for /downloads-all
  define('BY_VERSION', true);
  $product_names = array('couchbase-server', 'moxi-server');
  $develop_node_id = null; // 1033 is the *full* Develop page...not what we want
}

$mimetype = (@$_GET['type'] === 'json' ? 'application/json' : 'text/html');
$show_next = (@$_GET['next'] === 'true' ? true : false);

if (IS_LOCAL && @$_GET['fromS3'] !== 'true') {
  $contents = require_once INCLUDE_PATH.'contents.php';
} else if (!IS_LOCAL || @$_GET['fromS3'] === 'true') {
  require_once INCLUDE_PATH.'S3.php';
  $s3 = new S3($accessKey, $secretKey);
  $contents = $s3->getBucket('packages.couchbase.com', 'releases', null, null, '|');
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

  $platform_names = array('rpm' => array('title'=>'Red Hat',  'icon'=>'redhat', 'is_rpm'=> true),
                          'deb' => array('title'=>'Ubuntu',   'icon'=>'ubuntu', 'is_deb'=> true),
                          'exe' => array('title'=>'Windows',  'icon'=>'windows', 'is_exe'=> true),
                          'dmg' => array('title'=>'Mac OS X', 'icon'=>'mac', 'is_dmg'=> true));

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
    else if ($filename === 'index.html') continue;
    else if (substr($filename, -3, 3) === 'md5'
      || substr($filename, -9, 9) === 'blacklist'
      || substr($filename, -7, 7) === 'staging'
      || substr($filename, -3, 3) === 'xml'
      || substr($filename, -3, 3) === 'txt'
      || substr($filename, 0, 10) === 'northscale'
      || substr($filename, 0, 15) === 'CouchbaseServer') continue;
    else if ($product_string === 'couchbase-server'
            && substr($filename, 0, strlen($product_string)) !== $product_string
            && substr($filename, 0, strlen('membase-server')) !== 'membase-server') continue;
    else if ($product_string !== 'couchbase-server'
            && substr($filename, 0, strlen($product_string)) !== $product_string) continue;

    // Check if this download is blacklisted. If it is, skip it.
    if (array_key_exists($url . '.blacklist', $contents)) continue;
    if (!IS_LOCAL && !IS_STAGING && array_key_exists($url . '.staging', $contents)) continue;

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
      preg_match("/([A-Za-z\-]*)([_]?(win2008)?[_\-](x86)[_]?(64)?)?[_]([0-9\.]*(-dev-preview-[0-9])?)[\.|_](.*)/",
        $filename, $matches);
      list(, $product, , , $arch, $bits, $version, $dev_preview, $postfix) = $matches;

      $dev_preview = $dev_preview ? true : false;

      preg_match("/(.*)[-](enterprise|community)$/", $product, $edition_matches);
      if (count($edition_matches) > 1) {
        list (, $product, $edition) = $edition_matches;
      } else {
        $edition = 'community';
      }

      $doc_product = str_replace('-server', '', $product);

      if ($bits === '64') $arch .= '/64';

      if (substr($postfix, 0, 9) === 'setup.exe') $type = 'exe';
      else if (substr($postfix, 0, 3) === 'rpm')  $type = 'rpm';
      else if (substr($postfix, 0, 3) === 'deb')  $type = 'deb';
      else if (substr($postfix, 0, 3) === 'zip')  $type = 'dmg'; // we used to dmg's...
    }

    // if the version string isn't found in the filename, than it's not one of
    // the typical patterns, and we don't care about it...at least we hope not.
    if ($version === null) continue;
    $major_version = substr($version, 0, strpos($version, '.', 3));
    // PHP5.3 edition: $major_version = strstr($version, '.', true);
    $needs_tos = $major_version == 1.7;

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
        $output['releases'][] = compact('major_version', 'version', 'created', 'product', 'doc_product', 'dev_preview', 'needs_tos')
          + array('installers'=>
              array($type =>
                array_merge($platform_names[$type], array($arch => array($edition => $urls))
                )
              )
            );
      } else {
        $output['releases'][] = compact('major_version', 'version', 'created', 'product', 'doc_product', 'dev_preview', 'needs_tos')
          + array($type => $urls);
      }
    }

    $last_version = $version;

    unset($version, $product, $doc_product, $edition, $type, $arch, $bits, $version, $dev_preview, $postfix, $matches, $edition_matches, $needs_tos);
  }

  usort($output['releases'], 'cmp');

  return $output;
}

header('Content-Type: ' . $mimetype);

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
          'title'=> ($product['id'] === 'couchbase-server' && $major_version == 1.7 ? 'Membase Server 1.7' : $product['title'] . ' ' . $major_version),
          'releases'=> array($release)
        );
      }
    }
  }

  // Throw the final current_product into the stack.
  $products_by_major_version[] = $current_product;

  $products = $products_by_major_version;
} else {
  $latest = null;
  function add_latest(&$item, $key, $latest) {
    if ($item['version'] === $latest) {
      $item['latest'] = true;
    }
  }
  foreach ($products as &$product) {
    foreach($product['releases'] as $release) {
      if ($release['dev_preview']) {
        continue;
      } else if ($latest === null) {
        $latest = $release['version'];
        continue;
      } else {
        if ($latest < $release['version']) {
          $latest = $release['version'];
        }
      }
    }
    reset($product['releases']);
    array_walk($product['releases'], 'add_latest', $latest);
  }
}

$products = array('products' => $products,
                  'staging' => (IS_LOCAL || IS_STAGING),
                  'show_next' => $show_next);
$products['multiple_products'] = (count($product_names) > 1) ? true : false;

if ($mimetype === 'application/json') {
  print_r(json_encode($products));
} else {
  require_once INCLUDE_PATH.'Mustache.php';
  $m = new Mustache();

  $main = <<<EOD
{{>header}}
<style type="text/css">
.cb-download-form{top:44px;z-index:1}
.cb-download{display:none}
</style>
<div>
{{#products}}
<div id="{{id}}" style="position:relative;{{#multiple_products}}margin-bottom:50px{{/multiple_products}}">
{{^multiple_products}}
<div class="cb-download-desc">
  Enterprise or Community. <a href="/couchbase-server/editions">Which one is right for me?</a></div>
{{/multiple_products}}
<h3{{^multiple_products}} class="step-1"{{/multiple_products}}>
  {{title}} Downloads</h3>
<div class="cb-download-form">
  <form>
    <select>
    {{#releases}}
      <option value="{{version}}" {{^show_next}}{{#latest}}selected="selected"{{/latest}}{{/show_next}}>{{version}}{{#latest}} - latest{{/latest}}</option>
    {{/releases}}
    </select>
  </form>
</div>
{{#releases}}
<div class="cb-download" data-version="{{version}}"{{#latest}} data-latest="true"{{/latest}}{{#dev_preview}} data-dev-preview="true"{{/dev_preview}}>
  {{#dev_preview}}<a name="next"></a>{{/dev_preview}}
  <div class="cb-download-head-top">
    <div class="download-title">
      <h3>
        Operating System</h3>
    </div>
    <div class="head-title">
      <h2>
        Enterprise Edition</h2>
      <p>
        Recommended for development and production</p>
    </div>
    <div class="head-title">
      <h2>
        Community Edition</h2>
      <p>
        Courtesy builds for enthusiasts</p>
    </div>
    <div class="download-free">
      <a class="why_edition">Why Enterprise?</a>
      <div class="edition_answer" style="display:none">
        Choose Enterprise Edition if you're working on funded project, here's why:<br /> <br />
        <ul class="bullet">
          <li>Rigorously tested, production-ready release with latest bug fixes</li>
          <li>Free for testing and development for any number of nodes</li>
          <li>Free for production up to two nodes</li>
          <li>Annual subscription available, includes support and hot-fixes</li>
        </ul>
        <a href="/couchbase-server/editions">Learn More</a>
      </div>
    </div>
    <div class="download-free">
      <a class="why_edition">Why Community?</a>
      <div class="edition_answer" style="display:none">
        Choose Community Edition if you're working non-commercial projects:<br /><br />
        <ul class="bullet">
          <li>For enthusiasts able to resolve issues independently</li>
          <li>Untested binaries that do not include the latest EE bug fixes</li>
          <li>No constraints on using binaries on production systems</li>
          <li>Help available from the Couchbase user community</li>
        </ul>
        <a href="/couchbase-server/editions">Learn More</a>
      </div>
    </div>
  </div>
  {{#installers}}
    {{#deb}}<div class="cb-download-row">{{>installer}}</div>{{/deb}}
    {{#rpm}}<div class="cb-download-row">{{>installer}}</div>{{/rpm}}
    {{#exe}}<div class="cb-download-row">{{>installer}}</div>{{/exe}}
    {{#dmg}}<div class="cb-download-row">{{>installer}}</div>{{/dmg}}
  {{/installers}}
  {{#source}}
  <div class="cb-download-row-last"{{#needs_tos}} style="height:90px;background-position:0 0;background-color:#EEEDE8"{{/needs_tos}}>
    <div class="download-title">
      <h4>Download Sources Files:</h4>
    </div>
    <div class="download-col1"></div>
    <div class="download-col2">
      <a href="http://packages.couchbase.com/{{url}}" onClick="_gaq.push(['_trackEvent', '{{#staging}}[staging] {{/staging}}Downloads - {{product}} - Source', '{{version}}', '{{filename}}']);">{{filename}}</a></div>
    {{#needs_tos}}<div style="clear:both;padding:25px 0px 25px 260px;text-align:center"><strong>PLEASE NOTE:</strong> By downloading this software you are agreeing to these <a href="/agreement/free-license">terms and conditions</a>.</div>{{/needs_tos}}
  </div>
  {{/source}}
</div>
{{/releases}}
</div>
{{/products}}
</div>
{{^multiple_products}}
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
{{/multiple_products}}
{{#multiple_products}}
  {{>develop}}
{{/multiple_products}}
<script type="text/javascript">
jQuery(function($) {
  {{#products}}
  $('#{{id}}').find('.cb-download-form select').change(function(ev) {
    $('#{{id}}').find('.cb-download').fadeOut('fast');
    $('#{{id}}').find('.cb-download[data-version=' + $(ev.target).val() + ']').fadeIn('fast');
  }).trigger('change');
  {{/products}}

  {{#show_next}}
  $('[data-dev-preview]:first').each(function() {
  });
  {{/show_next}}
  {{^show_next}}
  $('[data-latest] .download-col2:first:contains("N/A")').each(function() {
    var self = $(this);
    var platform = self.attr('data-platform');
    var replacement = self.closest('.cb-download').next().find('.download-col2[data-platform='+platform+']').html();
    self.html(replacement);
  });
  {{/show_next}}

  $('.download-instruction').bt({
    contentSelector: "$(this).siblings('.instruction').html()",
    width: 700,
    fill: 'white',
    cornerRadius: 20,
    padding: 20,
    strokeWidth: 1,
    trigger: ['mouseover', 'click']
  });
  $('.why_edition').bt({
    contentSelector: "$(this).siblings('.edition_answer').html()",
    width: 450,
    fill: 'white',
    cornerRadius: 20,
    padding: 20,
    strokeWidth: 1,
    trigger: ['mouseover', 'click']
  });
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
        <a class="download-instruction">Install instructions</a>
        <div class="instruction" style="display:none;">
          <p><strong>Install Instructions</strong></p>
          <p>&nbsp;</p>
          {{#is_deb}}
          <p><strong>Ubuntu</strong></p>
          <ol>
            <li>
              Download or transfer the download to your Ubuntu system.</li>
            <li>
              Install the package using the dpkg command as a priviledged user under sudo.<br />
              For example:<br />
              <pre>
        sudo dpkg -i {{#x86/64.enterprise.filename}}{{x86/64.enterprise.filename}}{{/x86/64.enterprise.filename}}{{^x86/64.enterprise.filename}}{{x86/64.community.filename}}{{/x86/64.enterprise.filename}}</pre>
            </li>
          </ol>
          {{/is_deb}}
          {{#is_rpm}}
          <p><strong>CentOS or Red Hat</strong></p>
          <ol>
            <li>
              Download or transfer the download to your CentOS or Red Hat system.</li>
            <li>
              Install the package using the rpm command as a privilged user under sudo.<br />
              For example:<br />
              <pre>
        sudo rpm --install {{#x86/64.enterprise.filename}}{{x86/64.enterprise.filename}}{{/x86/64.enterprise.filename}}{{^x86/64.enterprise.filename}}{{x86/64.community.filename}}{{/x86/64.enterprise.filename}}</pre>
            </li>
          </ol>
          {{/is_rpm}}
          {{#is_exe}}
          <p><strong>Windows</strong></p>
          <ol>
            <li>
              Locate the download on your system or transfer it to the system on which you plan to install Couchbase Server.</li>
            <li>
              Double click the {{#x86/64.enterprise.filename}}{{x86/64.enterprise.filename}}{{/x86/64.enterprise.filename}}{{^x86/64.enterprise.filename}}{{x86/64.community.filename}}{{/x86/64.enterprise.filename}}</li>
          </ol>
          <p>You may then open your web browser and navigate to http://&lt;servername&gt;:8091/ to configure your new Couchbase Server installation.</p>
          {{/is_exe}}
          {{#is_dmg}}
          <p><strong>MacOS X</strong></p>
          <ol>
            <li>
              Locate and double click the download to unzip it.</li>
            <li>
              Click and drag the enclosed Couchbase Server application to the Applications folder.</li>
            <li>
              Double click the Couchbase Server application in the applications folder.</li>
          </ol>
          <p>Your web browser should then open to the web console from which you can configure your new Couchbase Server installation.</p>
          {{/is_dmg}}
        </div>
      </div>
    </div>
    {{#x86/64.enterprise}}
    <div class="download-col1">
      <p>
        <a href="http://packages.couchbase.com/{{x86/64.enterprise.url}}" onClick="_gaq.push(['_trackEvent', '{{#staging}}[staging] {{/staging}}Downloads - {{product}} - Enterprise', '{{version}}', '{{title}} x86/64 Installer']);">{{version}} Release</a> | <a href="http://packages.couchbase.com/{{x86/64.enterprise.md5}}">[md5]</a></p>
      {{#x86.enterprise}}
      <p>
        <a href="http://packages.couchbase.com/{{x86.enterprise.url}}" onClick="_gaq.push(['_trackEvent', '{{#staging}}[staging] {{/staging}}Downloads - {{product}} - Enterprise', '{{version}}', '{{title}} x86 Installer']);">{{version}} Release</a> | <a href="http://packages.couchbase.com/{{x86.enterprise.md5}}">[md5]</a></p>
      {{/x86.enterprise}}
      {{^x86.enterprise}}
      <p>N/A</p>
      {{/x86.enterprise}}
      <p class="notes">
        <a href="http://www.couchbase.com/docs/{{doc_product}}-manual-{{major_version}}/{{product}}-rn.html">Release Notes</a> &nbsp;&nbsp; <a href="http://www.couchbase.com/docs/{{doc_product}}-manual-{{major_version}}/">Manual</a></p>
    </div>
    {{/x86/64.enterprise}}
    {{^x86/64.enterprise}}
    <div class="download-col1">
      <p>N/A</p>
      <p>N/A</p>
    </div>
    {{/x86/64.enterprise}}

    {{#x86/64.community}}
    <div class="download-col2" data-platform="{{icon}}">
      <p>
        <a href="http://packages.couchbase.com/{{x86/64.community.url}}" onClick="_gaq.push(['_trackEvent', '{{#staging}}[staging] {{/staging}}Downloads - {{product}} - Community', '{{version}}', '{{title}} x86/64 Installer']);">{{version}} Release</a> | <a href="http://packages.couchbase.com/{{x86/64.community.md5}}">[md5]</a></p>
      {{#x86.community}}
      <p>
        <a href="http://packages.couchbase.com/{{x86.community.url}}" onClick="_gaq.push(['_trackEvent', '{{#staging}}[staging] {{/staging}}Downloads - {{product}} - Community', '{{version}}', '{{title}} x86 Installer']);">{{version}} Release</a> | <a href="http://packages.couchbase.com/{{x86.community.md5}}">[md5]</a></p>
      {{/x86.community}}
      {{^x86.community}}
      <p>N/A</p>
      {{/x86.community}}
      <p class="notes">
        <a href="http://www.couchbase.com/docs/{{doc_product}}-manual-{{major_version}}/{{product}}-rn.html">Release Notes</a> &nbsp;&nbsp; <a href="http://www.couchbase.com/docs/{{doc_product}}-manual-{{major_version}}/">Manual</a></p>
    </div>
    {{/x86/64.community}}
    {{^x86/64.community}}
    <div class="download-col2" data-platform="{{icon}}">
      <p>N/A</p>
      <p>N/A</p>
    </div>
    {{/x86/64.community}}
EOD;

  if ($products['multiple_products'] && !IS_LOCAL
      && $develop_node_id !== null) {
    $node = node_load($develop_node_id);
    $develop = node_view($node);
    watchdog('node1033content', '%develop', array('%develop'=>$develop));
  } else {
    $develop = '';
  }
  $partials = compact('installer', 'develop');
  if (IS_LOCAL) {
    $partials += array('header' => file_get_contents('header.html'),
                      'footer' => file_get_contents('footer.html'));
  }
  echo $m->render($main, $products, $partials);
}