<?php

$accessKey = 'REPLACE ME';
$secretKey = 'REPLACE ME';

$mimetype = (@$_GET['type'] === 'json' ? 'application/json' : 'text/html');

if ($_SERVER['SERVER_NAME'] === 'localhost' && @$_GET['fromS3'] !== 'true') {
  $contents = require_once 'contents.php';
} else if ($_SERVER['SERVER_NAME'] === 'new.stage.couchbase.com' || @$_GET['fromS3'] === 'true') {
  /**
  * $Id$
  *
  * Copyright (c) 2011, Donovan Schönknecht.  All rights reserved.
  *
  * Redistribution and use in source and binary forms, with or without
  * modification, are permitted provided that the following conditions are met:
  *
  * - Redistributions of source code must retain the above copyright notice,
  *   this list of conditions and the following disclaimer.
  * - Redistributions in binary form must reproduce the above copyright
  *   notice, this list of conditions and the following disclaimer in the
  *   documentation and/or other materials provided with the distribution.
  *
  * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
  * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
  * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
  * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
  * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
  * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
  * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
  * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
  * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
  * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
  * POSSIBILITY OF SUCH DAMAGE.
  *
  * Amazon S3 is a trademark of Amazon.com, Inc. or its affiliates.
  */

  /**
  * Amazon S3 PHP class
  *
  * @link http://undesigned.org.za/2007/10/22/amazon-s3-php-class
  * @version 0.5.0-dev
  */
  class S3
  {
    // ACL flags
    const ACL_PRIVATE = 'private';
    const ACL_PUBLIC_READ = 'public-read';
    const ACL_PUBLIC_READ_WRITE = 'public-read-write';
    const ACL_AUTHENTICATED_READ = 'authenticated-read';

    const STORAGE_CLASS_STANDARD = 'STANDARD';
    const STORAGE_CLASS_RRS = 'REDUCED_REDUNDANCY';

    private static $__accessKey = null; // AWS Access key
    private static $__secretKey = null; // AWS Secret key
    private static $__sslKey = null;

    public static $endpoint = 's3.amazonaws.com';
    public static $proxy = null;

    public static $useSSL = false;
    public static $useSSLValidation = true;
    public static $useExceptions = false;

    // SSL CURL SSL options - only needed if you are experiencing problems with your OpenSSL configuration
    public static $sslKey = null;
    public static $sslCert = null;
    public static $sslCACert = null;

    private static $__signingKeyPairId = null; // AWS Key Pair ID
    private static $__signingKeyResource = false; // Key resource, freeSigningKey() must be called to clear it from memory


    /**
    * Constructor - if you're not using the class statically
    *
    * @param string $accessKey Access key
    * @param string $secretKey Secret key
    * @param boolean $useSSL Enable SSL
    * @return void
    */
    public function __construct($accessKey = null, $secretKey = null, $useSSL = false, $endpoint = 's3.amazonaws.com')
    {
      if ($accessKey !== null && $secretKey !== null)
        self::setAuth($accessKey, $secretKey);
      self::$useSSL = $useSSL;
      self::$endpoint = $endpoint;
    }


    /**
    * Set the sertvice endpoint
    *
    * @param string $host Hostname
    * @return void
    */
    public function setEndpoint($host)
    {
      self::$endpoint = $host;
    }

    /**
    * Set AWS access key and secret key
    *
    * @param string $accessKey Access key
    * @param string $secretKey Secret key
    * @return void
    */
    public static function setAuth($accessKey, $secretKey)
    {
      self::$__accessKey = $accessKey;
      self::$__secretKey = $secretKey;
    }


    /**
    * Check if AWS keys have been set
    *
    * @return boolean
    */
    public static function hasAuth() {
      return (self::$__accessKey !== null && self::$__secretKey !== null);
    }


    /**
    * Set SSL on or off
    *
    * @param boolean $enabled SSL enabled
    * @param boolean $validate SSL certificate validation
    * @return void
    */
    public static function setSSL($enabled, $validate = true)
    {
      self::$useSSL = $enabled;
      self::$useSSLValidation = $validate;
    }


    /**
    * Set SSL client certificates (experimental)
    *
    * @param string $sslCert SSL client certificate
    * @param string $sslKey SSL client key
    * @param string $sslCACert SSL CA cert (only required if you are having problems with your system CA cert)
    * @return void
    */
    public static function setSSLAuth($sslCert = null, $sslKey = null, $sslCACert = null)
    {
      self::$sslCert = $sslCert;
      self::$sslKey = $sslKey;
      self::$sslCACert = $sslCACert;
    }


    /**
    * Set proxy information
    *
    * @param string $host Proxy hostname and port (localhost:1234)
    * @param string $user Proxy username
    * @param string $pass Proxy password
    * @param constant $type CURL proxy type
    * @return void
    */
    public static function setProxy($host, $user = null, $pass = null, $type = CURLPROXY_SOCKS5)
    {
      self::$proxy = array('host' => $host, 'type' => $type, 'user' => null, 'pass' => 'null');
    }


    /**
    * Set the error mode to exceptions
    *
    * @param boolean $enabled Enable exceptions
    * @return void
    */
    public static function setExceptions($enabled = true)
    {
      self::$useExceptions = $enabled;
    }


    /**
    * Set signing key
    *
    * @param string $keyPairId AWS Key Pair ID
    * @param string $signingKey Private Key
    * @param boolean $isFile Load private key from file, set to false to load string
    * @return boolean
    */
    public static function setSigningKey($keyPairId, $signingKey, $isFile = true)
    {
      self::$__signingKeyPairId = $keyPairId;
      if ((self::$__signingKeyResource = openssl_pkey_get_private($isFile ?
      file_get_contents($signingKey) : $signingKey)) !== false) return true;
      self::__triggerError('S3::setSigningKey(): Unable to open load private key: '.$signingKey, __FILE__, __LINE__);
      return false;
    }


    /**
    * Free signing key from memory, MUST be called if you are using setSigningKey()
    *
    * @return void
    */
    public static function freeSigningKey()
    {
      if (self::$__signingKeyResource !== false)
        openssl_free_key(self::$__signingKeyResource);
    }


    /**
    * Internal error handler
    *
    * @internal Internal error handler
    * @param string $message Error message
    * @param string $file Filename
    * @param integer $line Line number
    * @param integer $code Error code
    * @return void
    */
    private static function __triggerError($message, $file, $line, $code = 0)
    {
      if (self::$useExceptions)
        throw new S3Exception($message, $file, $line, $code);
      else
        trigger_error($message, E_USER_WARNING);
    }


    /**
    * Get a list of buckets
    *
    * @param boolean $detailed Returns detailed bucket list when true
    * @return array | false
    */
    public static function listBuckets($detailed = false)
    {
      $rest = new S3Request('GET', '', '', self::$endpoint);
      $rest = $rest->getResponse();
      if ($rest->error === false && $rest->code !== 200)
        $rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
      if ($rest->error !== false)
      {
        self::__triggerError(sprintf("S3::listBuckets(): [%s] %s", $rest->error['code'],
        $rest->error['message']), __FILE__, __LINE__);
        return false;
      }
      $results = array();
      if (!isset($rest->body->Buckets)) return $results;

      if ($detailed)
      {
        if (isset($rest->body->Owner, $rest->body->Owner->ID, $rest->body->Owner->DisplayName))
        $results['owner'] = array(
          'id' => (string)$rest->body->Owner->ID, 'name' => (string)$rest->body->Owner->ID
        );
        $results['buckets'] = array();
        foreach ($rest->body->Buckets->Bucket as $b)
          $results['buckets'][] = array(
            'name' => (string)$b->Name, 'time' => strtotime((string)$b->CreationDate)
          );
      } else
        foreach ($rest->body->Buckets->Bucket as $b) $results[] = (string)$b->Name;

      return $results;
    }


    /*
    * Get contents for a bucket
    *
    * If maxKeys is null this method will loop through truncated result sets
    *
    * @param string $bucket Bucket name
    * @param string $prefix Prefix
    * @param string $marker Marker (last file listed)
    * @param string $maxKeys Max keys (maximum number of keys to return)
    * @param string $delimiter Delimiter
    * @param boolean $returnCommonPrefixes Set to true to return CommonPrefixes
    * @return array | false
    */
    public static function getBucket($bucket, $prefix = null, $marker = null, $maxKeys = null, $delimiter = null, $returnCommonPrefixes = false)
    {
      $rest = new S3Request('GET', $bucket, '', self::$endpoint);
      if ($maxKeys == 0) $maxKeys = null;
      if ($prefix !== null && $prefix !== '') $rest->setParameter('prefix', $prefix);
      if ($marker !== null && $marker !== '') $rest->setParameter('marker', $marker);
      if ($maxKeys !== null && $maxKeys !== '') $rest->setParameter('max-keys', $maxKeys);
      if ($delimiter !== null && $delimiter !== '') $rest->setParameter('delimiter', $delimiter);
      $response = $rest->getResponse();
      if ($response->error === false && $response->code !== 200)
        $response->error = array('code' => $response->code, 'message' => 'Unexpected HTTP status');
      if ($response->error !== false)
      {
        self::__triggerError(sprintf("S3::getBucket(): [%s] %s",
        $response->error['code'], $response->error['message']), __FILE__, __LINE__);
        return false;
      }

      $results = array();

      $nextMarker = null;
      if (isset($response->body, $response->body->Contents))
      foreach ($response->body->Contents as $c)
      {
        $results[(string)$c->Key] = array(
          'name' => (string)$c->Key,
          'time' => strtotime((string)$c->LastModified),
          'size' => (int)$c->Size,
          'hash' => substr((string)$c->ETag, 1, -1)
        );
        $nextMarker = (string)$c->Key;
      }

      if ($returnCommonPrefixes && isset($response->body, $response->body->CommonPrefixes))
        foreach ($response->body->CommonPrefixes as $c)
          $results[(string)$c->Prefix] = array('prefix' => (string)$c->Prefix);

      if (isset($response->body, $response->body->IsTruncated) &&
      (string)$response->body->IsTruncated == 'false') return $results;

      if (isset($response->body, $response->body->NextMarker))
        $nextMarker = (string)$response->body->NextMarker;

      // Loop through truncated results if maxKeys isn't specified
      if ($maxKeys == null && $nextMarker !== null && (string)$response->body->IsTruncated == 'true')
      do
      {
        $rest = new S3Request('GET', $bucket, '', self::$endpoint);
        if ($prefix !== null && $prefix !== '') $rest->setParameter('prefix', $prefix);
        $rest->setParameter('marker', $nextMarker);
        if ($delimiter !== null && $delimiter !== '') $rest->setParameter('delimiter', $delimiter);

        if (($response = $rest->getResponse(true)) == false || $response->code !== 200) break;

        if (isset($response->body, $response->body->Contents))
        foreach ($response->body->Contents as $c)
        {
          $results[(string)$c->Key] = array(
            'name' => (string)$c->Key,
            'time' => strtotime((string)$c->LastModified),
            'size' => (int)$c->Size,
            'hash' => substr((string)$c->ETag, 1, -1)
          );
          $nextMarker = (string)$c->Key;
        }

        if ($returnCommonPrefixes && isset($response->body, $response->body->CommonPrefixes))
          foreach ($response->body->CommonPrefixes as $c)
            $results[(string)$c->Prefix] = array('prefix' => (string)$c->Prefix);

        if (isset($response->body, $response->body->NextMarker))
          $nextMarker = (string)$response->body->NextMarker;

      } while ($response !== false && (string)$response->body->IsTruncated == 'true');

      return $results;
    }


    /**
    * Put a bucket
    *
    * @param string $bucket Bucket name
    * @param constant $acl ACL flag
    * @param string $location Set as "EU" to create buckets hosted in Europe
    * @return boolean
    */
    public static function putBucket($bucket, $acl = self::ACL_PRIVATE, $location = false)
    {
      $rest = new S3Request('PUT', $bucket, '', self::$endpoint);
      $rest->setAmzHeader('x-amz-acl', $acl);

      if ($location !== false)
      {
        $dom = new DOMDocument;
        $createBucketConfiguration = $dom->createElement('CreateBucketConfiguration');
        $locationConstraint = $dom->createElement('LocationConstraint', $location);
        $createBucketConfiguration->appendChild($locationConstraint);
        $dom->appendChild($createBucketConfiguration);
        $rest->data = $dom->saveXML();
        $rest->size = strlen($rest->data);
        $rest->setHeader('Content-Type', 'application/xml');
      }
      $rest = $rest->getResponse();

      if ($rest->error === false && $rest->code !== 200)
        $rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
      if ($rest->error !== false)
      {
        self::__triggerError(sprintf("S3::putBucket({$bucket}, {$acl}, {$location}): [%s] %s",
        $rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
        return false;
      }
      return true;
    }


    /**
    * Delete an empty bucket
    *
    * @param string $bucket Bucket name
    * @return boolean
    */
    public static function deleteBucket($bucket)
    {
      $rest = new S3Request('DELETE', $bucket, '', self::$endpoint);
      $rest = $rest->getResponse();
      if ($rest->error === false && $rest->code !== 204)
        $rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
      if ($rest->error !== false)
      {
        self::__triggerError(sprintf("S3::deleteBucket({$bucket}): [%s] %s",
        $rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
        return false;
      }
      return true;
    }


    /**
    * Create input info array for putObject()
    *
    * @param string $file Input file
    * @param mixed $md5sum Use MD5 hash (supply a string if you want to use your own)
    * @return array | false
    */
    public static function inputFile($file, $md5sum = true)
    {
      if (!file_exists($file) || !is_file($file) || !is_readable($file))
      {
        self::__triggerError('S3::inputFile(): Unable to open input file: '.$file, __FILE__, __LINE__);
        return false;
      }
      return array('file' => $file, 'size' => filesize($file), 'md5sum' => $md5sum !== false ?
      (is_string($md5sum) ? $md5sum : base64_encode(md5_file($file, true))) : '');
    }


    /**
    * Create input array info for putObject() with a resource
    *
    * @param string $resource Input resource to read from
    * @param integer $bufferSize Input byte size
    * @param string $md5sum MD5 hash to send (optional)
    * @return array | false
    */
    public static function inputResource(&$resource, $bufferSize, $md5sum = '')
    {
      if (!is_resource($resource) || $bufferSize < 0)
      {
        self::__triggerError('S3::inputResource(): Invalid resource or buffer size', __FILE__, __LINE__);
        return false;
      }
      $input = array('size' => $bufferSize, 'md5sum' => $md5sum);
      $input['fp'] =& $resource;
      return $input;
    }


    /**
    * Put an object
    *
    * @param mixed $input Input data
    * @param string $bucket Bucket name
    * @param string $uri Object URI
    * @param constant $acl ACL constant
    * @param array $metaHeaders Array of x-amz-meta-* headers
    * @param array $requestHeaders Array of request headers or content type as a string
    * @param constant $storageClass Storage class constant
    * @return boolean
    */
    public static function putObject($input, $bucket, $uri, $acl = self::ACL_PRIVATE, $metaHeaders = array(), $requestHeaders = array(), $storageClass = self::STORAGE_CLASS_STANDARD)
    {
      if ($input === false) return false;
      $rest = new S3Request('PUT', $bucket, $uri, self::$endpoint);

      if (is_string($input)) $input = array(
        'data' => $input, 'size' => strlen($input),
        'md5sum' => base64_encode(md5($input, true))
      );

      // Data
      if (isset($input['fp']))
        $rest->fp =& $input['fp'];
      elseif (isset($input['file']))
        $rest->fp = @fopen($input['file'], 'rb');
      elseif (isset($input['data']))
        $rest->data = $input['data'];

      // Content-Length (required)
      if (isset($input['size']) && $input['size'] >= 0)
        $rest->size = $input['size'];
      else {
        if (isset($input['file']))
          $rest->size = filesize($input['file']);
        elseif (isset($input['data']))
          $rest->size = strlen($input['data']);
      }

      // Custom request headers (Content-Type, Content-Disposition, Content-Encoding)
      if (is_array($requestHeaders))
        foreach ($requestHeaders as $h => $v) $rest->setHeader($h, $v);
      elseif (is_string($requestHeaders)) // Support for legacy contentType parameter
        $input['type'] = $requestHeaders;

      // Content-Type
      if (!isset($input['type']))
      {
        if (isset($requestHeaders['Content-Type']))
          $input['type'] =& $requestHeaders['Content-Type'];
        elseif (isset($input['file']))
          $input['type'] = self::__getMimeType($input['file']);
        else
          $input['type'] = 'application/octet-stream';
      }

      if ($storageClass !== self::STORAGE_CLASS_STANDARD) // Storage class
        $rest->setAmzHeader('x-amz-storage-class', $storageClass);

      // We need to post with Content-Length and Content-Type, MD5 is optional
      if ($rest->size >= 0 && ($rest->fp !== false || $rest->data !== false))
      {
        $rest->setHeader('Content-Type', $input['type']);
        if (isset($input['md5sum'])) $rest->setHeader('Content-MD5', $input['md5sum']);

        $rest->setAmzHeader('x-amz-acl', $acl);
        foreach ($metaHeaders as $h => $v) $rest->setAmzHeader('x-amz-meta-'.$h, $v);
        $rest->getResponse();
      } else
        $rest->response->error = array('code' => 0, 'message' => 'Missing input parameters');

      if ($rest->response->error === false && $rest->response->code !== 200)
        $rest->response->error = array('code' => $rest->response->code, 'message' => 'Unexpected HTTP status');
      if ($rest->response->error !== false)
      {
        self::__triggerError(sprintf("S3::putObject(): [%s] %s",
        $rest->response->error['code'], $rest->response->error['message']), __FILE__, __LINE__);
        return false;
      }
      return true;
    }


    /**
    * Put an object from a file (legacy function)
    *
    * @param string $file Input file path
    * @param string $bucket Bucket name
    * @param string $uri Object URI
    * @param constant $acl ACL constant
    * @param array $metaHeaders Array of x-amz-meta-* headers
    * @param string $contentType Content type
    * @return boolean
    */
    public static function putObjectFile($file, $bucket, $uri, $acl = self::ACL_PRIVATE, $metaHeaders = array(), $contentType = null)
    {
      return self::putObject(self::inputFile($file), $bucket, $uri, $acl, $metaHeaders, $contentType);
    }


    /**
    * Put an object from a string (legacy function)
    *
    * @param string $string Input data
    * @param string $bucket Bucket name
    * @param string $uri Object URI
    * @param constant $acl ACL constant
    * @param array $metaHeaders Array of x-amz-meta-* headers
    * @param string $contentType Content type
    * @return boolean
    */
    public static function putObjectString($string, $bucket, $uri, $acl = self::ACL_PRIVATE, $metaHeaders = array(), $contentType = 'text/plain')
    {
      return self::putObject($string, $bucket, $uri, $acl, $metaHeaders, $contentType);
    }


    /**
    * Get an object
    *
    * @param string $bucket Bucket name
    * @param string $uri Object URI
    * @param mixed $saveTo Filename or resource to write to
    * @return mixed
    */
    public static function getObject($bucket, $uri, $saveTo = false)
    {
      $rest = new S3Request('GET', $bucket, $uri, self::$endpoint);
      if ($saveTo !== false)
      {
        if (is_resource($saveTo))
          $rest->fp =& $saveTo;
        else
          if (($rest->fp = @fopen($saveTo, 'wb')) !== false)
            $rest->file = realpath($saveTo);
          else
            $rest->response->error = array('code' => 0, 'message' => 'Unable to open save file for writing: '.$saveTo);
      }
      if ($rest->response->error === false) $rest->getResponse();

      if ($rest->response->error === false && $rest->response->code !== 200)
        $rest->response->error = array('code' => $rest->response->code, 'message' => 'Unexpected HTTP status');
      if ($rest->response->error !== false)
      {
        self::__triggerError(sprintf("S3::getObject({$bucket}, {$uri}): [%s] %s",
        $rest->response->error['code'], $rest->response->error['message']), __FILE__, __LINE__);
        return false;
      }
      return $rest->response;
    }


    /**
    * Get object information
    *
    * @param string $bucket Bucket name
    * @param string $uri Object URI
    * @param boolean $returnInfo Return response information
    * @return mixed | false
    */
    public static function getObjectInfo($bucket, $uri, $returnInfo = true)
    {
      $rest = new S3Request('HEAD', $bucket, $uri, self::$endpoint);
      $rest = $rest->getResponse();
      if ($rest->error === false && ($rest->code !== 200 && $rest->code !== 404))
        $rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
      if ($rest->error !== false)
      {
        self::__triggerError(sprintf("S3::getObjectInfo({$bucket}, {$uri}): [%s] %s",
        $rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
        return false;
      }
      return $rest->code == 200 ? $returnInfo ? $rest->headers : true : false;
    }


    /**
    * Copy an object
    *
    * @param string $bucket Source bucket name
    * @param string $uri Source object URI
    * @param string $bucket Destination bucket name
    * @param string $uri Destination object URI
    * @param constant $acl ACL constant
    * @param array $metaHeaders Optional array of x-amz-meta-* headers
    * @param array $requestHeaders Optional array of request headers (content type, disposition, etc.)
    * @param constant $storageClass Storage class constant
    * @return mixed | false
    */
    public static function copyObject($srcBucket, $srcUri, $bucket, $uri, $acl = self::ACL_PRIVATE, $metaHeaders = array(), $requestHeaders = array(), $storageClass = self::STORAGE_CLASS_STANDARD)
    {
      $rest = new S3Request('PUT', $bucket, $uri, self::$endpoint);
      $rest->setHeader('Content-Length', 0);
      foreach ($requestHeaders as $h => $v) $rest->setHeader($h, $v);
      foreach ($metaHeaders as $h => $v) $rest->setAmzHeader('x-amz-meta-'.$h, $v);
      if ($storageClass !== self::STORAGE_CLASS_STANDARD) // Storage class
        $rest->setAmzHeader('x-amz-storage-class', $storageClass);
      $rest->setAmzHeader('x-amz-acl', $acl); // Added rawurlencode() for $srcUri (thanks a.yamanoi)
      $rest->setAmzHeader('x-amz-copy-source', sprintf('/%s/%s', $srcBucket, rawurlencode($srcUri)));
      if (sizeof($requestHeaders) > 0 || sizeof($metaHeaders) > 0)
        $rest->setAmzHeader('x-amz-metadata-directive', 'REPLACE');

      $rest = $rest->getResponse();
      if ($rest->error === false && $rest->code !== 200)
        $rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
      if ($rest->error !== false)
      {
        self::__triggerError(sprintf("S3::copyObject({$srcBucket}, {$srcUri}, {$bucket}, {$uri}): [%s] %s",
        $rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
        return false;
      }
      return isset($rest->body->LastModified, $rest->body->ETag) ? array(
        'time' => strtotime((string)$rest->body->LastModified),
        'hash' => substr((string)$rest->body->ETag, 1, -1)
      ) : false;
    }


    /**
    * Set logging for a bucket
    *
    * @param string $bucket Bucket name
    * @param string $targetBucket Target bucket (where logs are stored)
    * @param string $targetPrefix Log prefix (e,g; domain.com-)
    * @return boolean
    */
    public static function setBucketLogging($bucket, $targetBucket, $targetPrefix = null)
    {
      // The S3 log delivery group has to be added to the target bucket's ACP
      if ($targetBucket !== null && ($acp = self::getAccessControlPolicy($targetBucket, '')) !== false)
      {
        // Only add permissions to the target bucket when they do not exist
        $aclWriteSet = false;
        $aclReadSet = false;
        foreach ($acp['acl'] as $acl)
        if ($acl['type'] == 'Group' && $acl['uri'] == 'http://acs.amazonaws.com/groups/s3/LogDelivery')
        {
          if ($acl['permission'] == 'WRITE') $aclWriteSet = true;
          elseif ($acl['permission'] == 'READ_ACP') $aclReadSet = true;
        }
        if (!$aclWriteSet) $acp['acl'][] = array(
          'type' => 'Group', 'uri' => 'http://acs.amazonaws.com/groups/s3/LogDelivery', 'permission' => 'WRITE'
        );
        if (!$aclReadSet) $acp['acl'][] = array(
          'type' => 'Group', 'uri' => 'http://acs.amazonaws.com/groups/s3/LogDelivery', 'permission' => 'READ_ACP'
        );
        if (!$aclReadSet || !$aclWriteSet) self::setAccessControlPolicy($targetBucket, '', $acp);
      }

      $dom = new DOMDocument;
      $bucketLoggingStatus = $dom->createElement('BucketLoggingStatus');
      $bucketLoggingStatus->setAttribute('xmlns', 'http://s3.amazonaws.com/doc/2006-03-01/');
      if ($targetBucket !== null)
      {
        if ($targetPrefix == null) $targetPrefix = $bucket . '-';
        $loggingEnabled = $dom->createElement('LoggingEnabled');
        $loggingEnabled->appendChild($dom->createElement('TargetBucket', $targetBucket));
        $loggingEnabled->appendChild($dom->createElement('TargetPrefix', $targetPrefix));
        // TODO: Add TargetGrants?
        $bucketLoggingStatus->appendChild($loggingEnabled);
      }
      $dom->appendChild($bucketLoggingStatus);

      $rest = new S3Request('PUT', $bucket, '', self::$endpoint);
      $rest->setParameter('logging', null);
      $rest->data = $dom->saveXML();
      $rest->size = strlen($rest->data);
      $rest->setHeader('Content-Type', 'application/xml');
      $rest = $rest->getResponse();
      if ($rest->error === false && $rest->code !== 200)
        $rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
      if ($rest->error !== false)
      {
        self::__triggerError(sprintf("S3::setBucketLogging({$bucket}, {$uri}): [%s] %s",
        $rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
        return false;
      }
      return true;
    }


    /**
    * Get logging status for a bucket
    *
    * This will return false if logging is not enabled.
    * Note: To enable logging, you also need to grant write access to the log group
    *
    * @param string $bucket Bucket name
    * @return array | false
    */
    public static function getBucketLogging($bucket)
    {
      $rest = new S3Request('GET', $bucket, '', self::$endpoint);
      $rest->setParameter('logging', null);
      $rest = $rest->getResponse();
      if ($rest->error === false && $rest->code !== 200)
        $rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
      if ($rest->error !== false)
      {
        self::__triggerError(sprintf("S3::getBucketLogging({$bucket}): [%s] %s",
        $rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
        return false;
      }
      if (!isset($rest->body->LoggingEnabled)) return false; // No logging
      return array(
        'targetBucket' => (string)$rest->body->LoggingEnabled->TargetBucket,
        'targetPrefix' => (string)$rest->body->LoggingEnabled->TargetPrefix,
      );
    }


    /**
    * Disable bucket logging
    *
    * @param string $bucket Bucket name
    * @return boolean
    */
    public static function disableBucketLogging($bucket)
    {
      return self::setBucketLogging($bucket, null);
    }


    /**
    * Get a bucket's location
    *
    * @param string $bucket Bucket name
    * @return string | false
    */
    public static function getBucketLocation($bucket)
    {
      $rest = new S3Request('GET', $bucket, '', self::$endpoint);
      $rest->setParameter('location', null);
      $rest = $rest->getResponse();
      if ($rest->error === false && $rest->code !== 200)
        $rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
      if ($rest->error !== false)
      {
        self::__triggerError(sprintf("S3::getBucketLocation({$bucket}): [%s] %s",
        $rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
        return false;
      }
      return (isset($rest->body[0]) && (string)$rest->body[0] !== '') ? (string)$rest->body[0] : 'US';
    }


    /**
    * Set object or bucket Access Control Policy
    *
    * @param string $bucket Bucket name
    * @param string $uri Object URI
    * @param array $acp Access Control Policy Data (same as the data returned from getAccessControlPolicy)
    * @return boolean
    */
    public static function setAccessControlPolicy($bucket, $uri = '', $acp = array())
    {
      $dom = new DOMDocument;
      $dom->formatOutput = true;
      $accessControlPolicy = $dom->createElement('AccessControlPolicy');
      $accessControlList = $dom->createElement('AccessControlList');

      // It seems the owner has to be passed along too
      $owner = $dom->createElement('Owner');
      $owner->appendChild($dom->createElement('ID', $acp['owner']['id']));
      $owner->appendChild($dom->createElement('DisplayName', $acp['owner']['name']));
      $accessControlPolicy->appendChild($owner);

      foreach ($acp['acl'] as $g)
      {
        $grant = $dom->createElement('Grant');
        $grantee = $dom->createElement('Grantee');
        $grantee->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        if (isset($g['id']))
        { // CanonicalUser (DisplayName is omitted)
          $grantee->setAttribute('xsi:type', 'CanonicalUser');
          $grantee->appendChild($dom->createElement('ID', $g['id']));
        }
        elseif (isset($g['email']))
        { // AmazonCustomerByEmail
          $grantee->setAttribute('xsi:type', 'AmazonCustomerByEmail');
          $grantee->appendChild($dom->createElement('EmailAddress', $g['email']));
        }
        elseif ($g['type'] == 'Group')
        { // Group
          $grantee->setAttribute('xsi:type', 'Group');
          $grantee->appendChild($dom->createElement('URI', $g['uri']));
        }
        $grant->appendChild($grantee);
        $grant->appendChild($dom->createElement('Permission', $g['permission']));
        $accessControlList->appendChild($grant);
      }

      $accessControlPolicy->appendChild($accessControlList);
      $dom->appendChild($accessControlPolicy);

      $rest = new S3Request('PUT', $bucket, $uri, self::$endpoint);
      $rest->setParameter('acl', null);
      $rest->data = $dom->saveXML();
      $rest->size = strlen($rest->data);
      $rest->setHeader('Content-Type', 'application/xml');
      $rest = $rest->getResponse();
      if ($rest->error === false && $rest->code !== 200)
        $rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
      if ($rest->error !== false)
      {
        self::__triggerError(sprintf("S3::setAccessControlPolicy({$bucket}, {$uri}): [%s] %s",
        $rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
        return false;
      }
      return true;
    }


    /**
    * Get object or bucket Access Control Policy
    *
    * @param string $bucket Bucket name
    * @param string $uri Object URI
    * @return mixed | false
    */
    public static function getAccessControlPolicy($bucket, $uri = '')
    {
      $rest = new S3Request('GET', $bucket, $uri, self::$endpoint);
      $rest->setParameter('acl', null);
      $rest = $rest->getResponse();
      if ($rest->error === false && $rest->code !== 200)
        $rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
      if ($rest->error !== false)
      {
        self::__triggerError(sprintf("S3::getAccessControlPolicy({$bucket}, {$uri}): [%s] %s",
        $rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
        return false;
      }

      $acp = array();
      if (isset($rest->body->Owner, $rest->body->Owner->ID, $rest->body->Owner->DisplayName))
        $acp['owner'] = array(
          'id' => (string)$rest->body->Owner->ID, 'name' => (string)$rest->body->Owner->DisplayName
        );

      if (isset($rest->body->AccessControlList))
      {
        $acp['acl'] = array();
        foreach ($rest->body->AccessControlList->Grant as $grant)
        {
          foreach ($grant->Grantee as $grantee)
          {
            if (isset($grantee->ID, $grantee->DisplayName)) // CanonicalUser
              $acp['acl'][] = array(
                'type' => 'CanonicalUser',
                'id' => (string)$grantee->ID,
                'name' => (string)$grantee->DisplayName,
                'permission' => (string)$grant->Permission
              );
            elseif (isset($grantee->EmailAddress)) // AmazonCustomerByEmail
              $acp['acl'][] = array(
                'type' => 'AmazonCustomerByEmail',
                'email' => (string)$grantee->EmailAddress,
                'permission' => (string)$grant->Permission
              );
            elseif (isset($grantee->URI)) // Group
              $acp['acl'][] = array(
                'type' => 'Group',
                'uri' => (string)$grantee->URI,
                'permission' => (string)$grant->Permission
              );
            else continue;
          }
        }
      }
      return $acp;
    }


    /**
    * Delete an object
    *
    * @param string $bucket Bucket name
    * @param string $uri Object URI
    * @return boolean
    */
    public static function deleteObject($bucket, $uri)
    {
      $rest = new S3Request('DELETE', $bucket, $uri, self::$endpoint);
      $rest = $rest->getResponse();
      if ($rest->error === false && $rest->code !== 204)
        $rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
      if ($rest->error !== false)
      {
        self::__triggerError(sprintf("S3::deleteObject(): [%s] %s",
        $rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
        return false;
      }
      return true;
    }


    /**
    * Get a query string authenticated URL
    *
    * @param string $bucket Bucket name
    * @param string $uri Object URI
    * @param integer $lifetime Lifetime in seconds
    * @param boolean $hostBucket Use the bucket name as the hostname
    * @param boolean $https Use HTTPS ($hostBucket should be false for SSL verification)
    * @return string
    */
    public static function getAuthenticatedURL($bucket, $uri, $lifetime, $hostBucket = false, $https = false)
    {
      $expires = time() + $lifetime;
      $uri = str_replace(array('%2F', '%2B'), array('/', '+'), rawurlencode($uri)); // URI should be encoded (thanks Sean O'Dea)
      return sprintf(($https ? 'https' : 'http').'://%s/%s?AWSAccessKeyId=%s&Expires=%u&Signature=%s',
      // $hostBucket ? $bucket : $bucket.'.s3.amazonaws.com', $uri, self::$__accessKey, $expires,
      $hostBucket ? $bucket : 's3.amazonaws.com/'.$bucket, $uri, self::$__accessKey, $expires,
      urlencode(self::__getHash("GET\n\n\n{$expires}\n/{$bucket}/{$uri}")));
    }


    /**
    * Get a CloudFront signed policy URL
    *
    * @param array $policy Policy
    * @return string
    */
    public static function getSignedPolicyURL($policy)
    {
      $data = json_encode($policy);
      $signature = '';
      if (!openssl_sign($data, $signature, self::$__signingKeyResource)) return false;

      $encoded = str_replace(array('+', '='), array('-', '_', '~'), base64_encode($data));
      $signature = str_replace(array('+', '='), array('-', '_', '~'), base64_encode($signature));

      $url = $policy['Statement'][0]['Resource'] . '?';
      foreach (array('Policy' => $encoded, 'Signature' => $signature, 'Key-Pair-Id' => self::$__signingKeyPairId) as $k => $v)
        $url .= $k.'='.str_replace('%2F', '/', rawurlencode($v)).'&';
      return substr($url, 0, -1);
    }


    /**
    * Get a CloudFront canned policy URL
    *
    * @param string $string URL to sign
    * @param integer $lifetime URL lifetime
    * @return string
    */
    public static function getSignedCannedURL($url, $lifetime)
    {
      return self::getSignedPolicyURL(array(
        'Statement' => array(
          array('Resource' => $url, 'Condition' => array(
            'DateLessThan' => array('AWS:EpochTime' => time() + $lifetime)
          ))
        )
      ));
    }


    /**
    * Get upload POST parameters for form uploads
    *
    * @param string $bucket Bucket name
    * @param string $uriPrefix Object URI prefix
    * @param constant $acl ACL constant
    * @param integer $lifetime Lifetime in seconds
    * @param integer $maxFileSize Maximum filesize in bytes (default 5MB)
    * @param string $successRedirect Redirect URL or 200 / 201 status code
    * @param array $amzHeaders Array of x-amz-meta-* headers
    * @param array $headers Array of request headers or content type as a string
    * @param boolean $flashVars Includes additional "Filename" variable posted by Flash
    * @return object
    */
    public static function getHttpUploadPostParams($bucket, $uriPrefix = '', $acl = self::ACL_PRIVATE, $lifetime = 3600,
    $maxFileSize = 5242880, $successRedirect = "201", $amzHeaders = array(), $headers = array(), $flashVars = false)
    {
      // Create policy object
      $policy = new stdClass;
      $policy->expiration = gmdate('Y-m-d\TH:i:s\Z', (time() + $lifetime));
      $policy->conditions = array();
      $obj = new stdClass; $obj->bucket = $bucket; array_push($policy->conditions, $obj);
      $obj = new stdClass; $obj->acl = $acl; array_push($policy->conditions, $obj);

      $obj = new stdClass; // 200 for non-redirect uploads
      if (is_numeric($successRedirect) && in_array((int)$successRedirect, array(200, 201)))
        $obj->success_action_status = (string)$successRedirect;
      else // URL
        $obj->success_action_redirect = $successRedirect;
      array_push($policy->conditions, $obj);

      if ($acl !== self::ACL_PUBLIC_READ)
        array_push($policy->conditions, array('eq', '$acl', $acl));

      array_push($policy->conditions, array('starts-with', '$key', $uriPrefix));
      if ($flashVars) array_push($policy->conditions, array('starts-with', '$Filename', ''));
      foreach (array_keys($headers) as $headerKey)
        array_push($policy->conditions, array('starts-with', '$'.$headerKey, ''));
      foreach ($amzHeaders as $headerKey => $headerVal)
      {
        $obj = new stdClass;
        $obj->{$headerKey} = (string)$headerVal;
        array_push($policy->conditions, $obj);
      }
      array_push($policy->conditions, array('content-length-range', 0, $maxFileSize));
      $policy = base64_encode(str_replace('\/', '/', json_encode($policy)));

      // Create parameters
      $params = new stdClass;
      $params->AWSAccessKeyId = self::$__accessKey;
      $params->key = $uriPrefix.'${filename}';
      $params->acl = $acl;
      $params->policy = $policy; unset($policy);
      $params->signature = self::__getHash($params->policy);
      if (is_numeric($successRedirect) && in_array((int)$successRedirect, array(200, 201)))
        $params->success_action_status = (string)$successRedirect;
      else
        $params->success_action_redirect = $successRedirect;
      foreach ($headers as $headerKey => $headerVal) $params->{$headerKey} = (string)$headerVal;
      foreach ($amzHeaders as $headerKey => $headerVal) $params->{$headerKey} = (string)$headerVal;
      return $params;
    }


    /**
    * Create a CloudFront distribution
    *
    * @param string $bucket Bucket name
    * @param boolean $enabled Enabled (true/false)
    * @param array $cnames Array containing CNAME aliases
    * @param string $comment Use the bucket name as the hostname
    * @param string $defaultRootObject Default root object
    * @param string $originAccessIdentity Origin access identity
    * @param array $trustedSigners Array of trusted signers
    * @return array | false
    */
    public static function createDistribution($bucket, $enabled = true, $cnames = array(), $comment = null, $defaultRootObject = null, $originAccessIdentity = null, $trustedSigners = array())
    {
      if (!extension_loaded('openssl'))
      {
        self::__triggerError(sprintf("S3::createDistribution({$bucket}, ".(int)$enabled.", [], '$comment'): %s",
        "CloudFront functionality requires SSL"), __FILE__, __LINE__);
        return false;
      }
      $useSSL = self::$useSSL;

      self::$useSSL = true; // CloudFront requires SSL
      $rest = new S3Request('POST', '', '2010-11-01/distribution', 'cloudfront.amazonaws.com');
      $rest->data = self::__getCloudFrontDistributionConfigXML(
        $bucket.'.s3.amazonaws.com',
        $enabled,
        (string)$comment,
        (string)microtime(true),
        $cnames,
        $defaultRootObject,
        $originAccessIdentity,
        $trustedSigners
      );

      $rest->size = strlen($rest->data);
      $rest->setHeader('Content-Type', 'application/xml');
      $rest = self::__getCloudFrontResponse($rest);

      self::$useSSL = $useSSL;

      if ($rest->error === false && $rest->code !== 201)
        $rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
      if ($rest->error !== false)
      {
        self::__triggerError(sprintf("S3::createDistribution({$bucket}, ".(int)$enabled.", [], '$comment'): [%s] %s",
        $rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
        return false;
      } elseif ($rest->body instanceof SimpleXMLElement)
        return self::__parseCloudFrontDistributionConfig($rest->body);
      return false;
    }


    /**
    * Get CloudFront distribution info
    *
    * @param string $distributionId Distribution ID from listDistributions()
    * @return array | false
    */
    public static function getDistribution($distributionId)
    {
      if (!extension_loaded('openssl'))
      {
        self::__triggerError(sprintf("S3::getDistribution($distributionId): %s",
        "CloudFront functionality requires SSL"), __FILE__, __LINE__);
        return false;
      }
      $useSSL = self::$useSSL;

      self::$useSSL = true; // CloudFront requires SSL
      $rest = new S3Request('GET', '', '2010-11-01/distribution/'.$distributionId, 'cloudfront.amazonaws.com');
      $rest = self::__getCloudFrontResponse($rest);

      self::$useSSL = $useSSL;

      if ($rest->error === false && $rest->code !== 200)
        $rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
      if ($rest->error !== false)
      {
        self::__triggerError(sprintf("S3::getDistribution($distributionId): [%s] %s",
        $rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
        return false;
      }
      elseif ($rest->body instanceof SimpleXMLElement)
      {
        $dist = self::__parseCloudFrontDistributionConfig($rest->body);
        $dist['hash'] = $rest->headers['hash'];
        $dist['id'] = $distributionId;
        return $dist;
      }
      return false;
    }


    /**
    * Update a CloudFront distribution
    *
    * @param array $dist Distribution array info identical to output of getDistribution()
    * @return array | false
    */
    public static function updateDistribution($dist)
    {
      if (!extension_loaded('openssl'))
      {
        self::__triggerError(sprintf("S3::updateDistribution({$dist['id']}): %s",
        "CloudFront functionality requires SSL"), __FILE__, __LINE__);
        return false;
      }

      $useSSL = self::$useSSL;

      self::$useSSL = true; // CloudFront requires SSL
      $rest = new S3Request('PUT', '', '2010-11-01/distribution/'.$dist['id'].'/config', 'cloudfront.amazonaws.com');
      $rest->data = self::__getCloudFrontDistributionConfigXML(
        $dist['origin'],
        $dist['enabled'],
        $dist['comment'],
        $dist['callerReference'],
        $dist['cnames'],
        $dist['defaultRootObject'],
        $dist['originAccessIdentity'],
        $dist['trustedSigners']
      );

      $rest->size = strlen($rest->data);
      $rest->setHeader('If-Match', $dist['hash']);
      $rest = self::__getCloudFrontResponse($rest);

      self::$useSSL = $useSSL;

      if ($rest->error === false && $rest->code !== 200)
        $rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
      if ($rest->error !== false)
      {
        self::__triggerError(sprintf("S3::updateDistribution({$dist['id']}): [%s] %s",
        $rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
        return false;
      } else {
        $dist = self::__parseCloudFrontDistributionConfig($rest->body);
        $dist['hash'] = $rest->headers['hash'];
        return $dist;
      }
      return false;
    }


    /**
    * Delete a CloudFront distribution
    *
    * @param array $dist Distribution array info identical to output of getDistribution()
    * @return boolean
    */
    public static function deleteDistribution($dist)
    {
      if (!extension_loaded('openssl'))
      {
        self::__triggerError(sprintf("S3::deleteDistribution({$dist['id']}): %s",
        "CloudFront functionality requires SSL"), __FILE__, __LINE__);
        return false;
      }

      $useSSL = self::$useSSL;

      self::$useSSL = true; // CloudFront requires SSL
      $rest = new S3Request('DELETE', '', '2008-06-30/distribution/'.$dist['id'], 'cloudfront.amazonaws.com');
      $rest->setHeader('If-Match', $dist['hash']);
      $rest = self::__getCloudFrontResponse($rest);

      self::$useSSL = $useSSL;

      if ($rest->error === false && $rest->code !== 204)
        $rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
      if ($rest->error !== false)
      {
        self::__triggerError(sprintf("S3::deleteDistribution({$dist['id']}): [%s] %s",
        $rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
        return false;
      }
      return true;
    }


    /**
    * Get a list of CloudFront distributions
    *
    * @return array
    */
    public static function listDistributions()
    {
      if (!extension_loaded('openssl'))
      {
        self::__triggerError(sprintf("S3::listDistributions(): [%s] %s",
        "CloudFront functionality requires SSL"), __FILE__, __LINE__);
        return false;
      }

      $useSSL = self::$useSSL;
      self::$useSSL = true; // CloudFront requires SSL
      $rest = new S3Request('GET', '', '2010-11-01/distribution', 'cloudfront.amazonaws.com');
      $rest = self::__getCloudFrontResponse($rest);
      self::$useSSL = $useSSL;

      if ($rest->error === false && $rest->code !== 200)
        $rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
      if ($rest->error !== false)
      {
        self::__triggerError(sprintf("S3::listDistributions(): [%s] %s",
        $rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
        return false;
      }
      elseif ($rest->body instanceof SimpleXMLElement && isset($rest->body->DistributionSummary))
      {
        $list = array();
        if (isset($rest->body->Marker, $rest->body->MaxItems, $rest->body->IsTruncated))
        {
          //$info['marker'] = (string)$rest->body->Marker;
          //$info['maxItems'] = (int)$rest->body->MaxItems;
          //$info['isTruncated'] = (string)$rest->body->IsTruncated == 'true' ? true : false;
        }
        foreach ($rest->body->DistributionSummary as $summary)
          $list[(string)$summary->Id] = self::__parseCloudFrontDistributionConfig($summary);

        return $list;
      }
      return array();
    }

    /**
    * List CloudFront Origin Access Identities
    *
    * @return array
    */
    public static function listOriginAccessIdentities()
    {
      if (!extension_loaded('openssl'))
      {
        self::__triggerError(sprintf("S3::listOriginAccessIdentities(): [%s] %s",
        "CloudFront functionality requires SSL"), __FILE__, __LINE__);
        return false;
      }

      self::$useSSL = true; // CloudFront requires SSL
      $rest = new S3Request('GET', '', '2010-11-01/origin-access-identity/cloudfront', 'cloudfront.amazonaws.com');
      $rest = self::__getCloudFrontResponse($rest);
      $useSSL = self::$useSSL;

      if ($rest->error === false && $rest->code !== 200)
        $rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
      if ($rest->error !== false)
      {
        trigger_error(sprintf("S3::listOriginAccessIdentities(): [%s] %s",
        $rest->error['code'], $rest->error['message']), E_USER_WARNING);
        return false;
      }

      if (isset($rest->body->CloudFrontOriginAccessIdentitySummary))
      {
        $identities = array();
        foreach ($rest->body->CloudFrontOriginAccessIdentitySummary as $identity)
          if (isset($identity->S3CanonicalUserId))
            $identities[(string)$identity->Id] = array('id' => (string)$identity->Id, 's3CanonicalUserId' => (string)$identity->S3CanonicalUserId);
        return $identities;
      }
      return false;
    }


    /**
    * Invalidate objects in a CloudFront distribution
    *
    * Thanks to Martin Lindkvist for S3::invalidateDistribution()
    *
    * @param string $distributionId Distribution ID from listDistributions()
    * @param array $paths Array of object paths to invalidate
    * @return boolean
    */
    public static function invalidateDistribution($distributionId, $paths)
    {
      if (!extension_loaded('openssl'))
      {
        self::__triggerError(sprintf("S3::invalidateDistribution(): [%s] %s",
        "CloudFront functionality requires SSL"), __FILE__, __LINE__);
        return false;
      }

      $useSSL = self::$useSSL;
      self::$useSSL = true; // CloudFront requires SSL
      $rest = new S3Request('POST', '', '2010-08-01/distribution/'.$distributionId.'/invalidation', 'cloudfront.amazonaws.com');
      $rest->data = self::__getCloudFrontInvalidationBatchXML($paths, (string)microtime(true));
      $rest->size = strlen($rest->data);
      $rest = self::__getCloudFrontResponse($rest);
      self::$useSSL = $useSSL;

      if ($rest->error === false && $rest->code !== 201)
        $rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
      if ($rest->error !== false)
      {
        trigger_error(sprintf("S3::invalidate('{$distributionId}',{$paths}): [%s] %s",
        $rest->error['code'], $rest->error['message']), E_USER_WARNING);
        return false;
      }
      return true;
    }


    /**
    * Get a InvalidationBatch DOMDocument
    *
    * @internal Used to create XML in invalidateDistribution()
    * @param array $paths Paths to objects to invalidateDistribution
    * @return string
    */
    private static function __getCloudFrontInvalidationBatchXML($paths, $callerReference = '0') {
      $dom = new DOMDocument('1.0', 'UTF-8');
      $dom->formatOutput = true;
      $invalidationBatch = $dom->createElement('InvalidationBatch');
      foreach ($paths as $path)
        $invalidationBatch->appendChild($dom->createElement('Path', $path));

      $invalidationBatch->appendChild($dom->createElement('CallerReference', $callerReference));
      $dom->appendChild($invalidationBatch);
      return $dom->saveXML();
    }


    /**
    * Get a DistributionConfig DOMDocument
    *
    * http://docs.amazonwebservices.com/AmazonCloudFront/latest/APIReference/index.html?PutConfig.html
    *
    * @internal Used to create XML in createDistribution() and updateDistribution()
    * @param string $bucket S3 Origin bucket
    * @param boolean $enabled Enabled (true/false)
    * @param string $comment Comment to append
    * @param string $callerReference Caller reference
    * @param array $cnames Array of CNAME aliases
    * @param string $defaultRootObject Default root object
    * @param string $originAccessIdentity Origin access identity
    * @param array $trustedSigners Array of trusted signers
    * @return string
    */
    private static function __getCloudFrontDistributionConfigXML($bucket, $enabled, $comment, $callerReference = '0', $cnames = array(), $defaultRootObject = null, $originAccessIdentity = null, $trustedSigners = array())
    {
      $dom = new DOMDocument('1.0', 'UTF-8');
      $dom->formatOutput = true;
      $distributionConfig = $dom->createElement('DistributionConfig');
      $distributionConfig->setAttribute('xmlns', 'http://cloudfront.amazonaws.com/doc/2010-11-01/');

      $origin = $dom->createElement('S3Origin');
      $origin->appendChild($dom->createElement('DNSName', $bucket));
      if ($originAccessIdentity !== null) $origin->appendChild($dom->createElement('OriginAccessIdentity', $originAccessIdentity));
      $distributionConfig->appendChild($origin);

      if ($defaultRootObject !== null) $distributionConfig->appendChild($dom->createElement('DefaultRootObject', $defaultRootObject));

      $distributionConfig->appendChild($dom->createElement('CallerReference', $callerReference));
      foreach ($cnames as $cname)
        $distributionConfig->appendChild($dom->createElement('CNAME', $cname));
      if ($comment !== '') $distributionConfig->appendChild($dom->createElement('Comment', $comment));
      $distributionConfig->appendChild($dom->createElement('Enabled', $enabled ? 'true' : 'false'));

      $trusted = $dom->createElement('TrustedSigners');
      foreach ($trustedSigners as $id => $type)
        $trusted->appendChild($id !== '' ? $dom->createElement($type, $id) : $dom->createElement($type));
      $distributionConfig->appendChild($trusted);

      $dom->appendChild($distributionConfig);
      //var_dump($dom->saveXML());
      return $dom->saveXML();
    }


    /**
    * Parse a CloudFront distribution config
    *
    * See http://docs.amazonwebservices.com/AmazonCloudFront/latest/APIReference/index.html?GetDistribution.html
    *
    * @internal Used to parse the CloudFront DistributionConfig node to an array
    * @param object &$node DOMNode
    * @return array
    */
    private static function __parseCloudFrontDistributionConfig(&$node)
    {
      if (isset($node->DistributionConfig))
        return self::__parseCloudFrontDistributionConfig($node->DistributionConfig);

      $dist = array();
      if (isset($node->Id, $node->Status, $node->LastModifiedTime, $node->DomainName))
      {
        $dist['id'] = (string)$node->Id;
        $dist['status'] = (string)$node->Status;
        $dist['time'] = strtotime((string)$node->LastModifiedTime);
        $dist['domain'] = (string)$node->DomainName;
      }

      if (isset($node->CallerReference))
        $dist['callerReference'] = (string)$node->CallerReference;

      if (isset($node->Enabled))
        $dist['enabled'] = (string)$node->Enabled == 'true' ? true : false;

      if (isset($node->S3Origin))
      {
        if (isset($node->S3Origin->DNSName))
          $dist['origin'] = (string)$node->S3Origin->DNSName;

        $dist['originAccessIdentity'] = isset($node->S3Origin->OriginAccessIdentity) ?
        (string)$node->S3Origin->OriginAccessIdentity : null;
      }

      $dist['defaultRootObject'] = isset($node->DefaultRootObject) ? (string)$node->DefaultRootObject : null;

      $dist['cnames'] = array();
      if (isset($node->CNAME))
        foreach ($node->CNAME as $cname)
          $dist['cnames'][(string)$cname] = (string)$cname;

      $dist['trustedSigners'] = array();
      if (isset($node->TrustedSigners))
        foreach ($node->TrustedSigners as $signer)
        {
          if (isset($signer->Self))
            $dist['trustedSigners'][''] = 'Self';
          elseif (isset($signer->KeyPairId))
            $dist['trustedSigners'][(string)$signer->KeyPairId] = 'KeyPairId';
          elseif (isset($signer->AwsAccountNumber))
            $dist['trustedSigners'][(string)$signer->AwsAccountNumber] = 'AwsAccountNumber';
        }

      $dist['comment'] = isset($node->Comment) ? (string)$node->Comment : null;
      return $dist;
    }


    /**
    * Grab CloudFront response
    *
    * @internal Used to parse the CloudFront S3Request::getResponse() output
    * @param object &$rest S3Request instance
    * @return object
    */
    private static function __getCloudFrontResponse(&$rest)
    {
      $rest->getResponse();
      if ($rest->response->error === false && isset($rest->response->body) &&
      is_string($rest->response->body) && substr($rest->response->body, 0, 5) == '<?xml')
      {
        $rest->response->body = simplexml_load_string($rest->response->body);
        // Grab CloudFront errors
        if (isset($rest->response->body->Error, $rest->response->body->Error->Code,
        $rest->response->body->Error->Message))
        {
          $rest->response->error = array(
            'code' => (string)$rest->response->body->Error->Code,
            'message' => (string)$rest->response->body->Error->Message
          );
          unset($rest->response->body);
        }
      }
      return $rest->response;
    }


    /**
    * Get MIME type for file
    *
    * @internal Used to get mime types
    * @param string &$file File path
    * @return string
    */
    public static function __getMimeType(&$file)
    {
      $type = false;
      // Fileinfo documentation says fileinfo_open() will use the
      // MAGIC env var for the magic file
      if (extension_loaded('fileinfo') && isset($_ENV['MAGIC']) &&
      ($finfo = finfo_open(FILEINFO_MIME, $_ENV['MAGIC'])) !== false)
      {
        if (($type = finfo_file($finfo, $file)) !== false)
        {
          // Remove the charset and grab the last content-type
          $type = explode(' ', str_replace('; charset=', ';charset=', $type));
          $type = array_pop($type);
          $type = explode(';', $type);
          $type = trim(array_shift($type));
        }
        finfo_close($finfo);

      // If anyone is still using mime_content_type()
      } elseif (function_exists('mime_content_type'))
        $type = trim(mime_content_type($file));

      if ($type !== false && strlen($type) > 0) return $type;

      // Otherwise do it the old fashioned way
      static $exts = array(
        'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'png' => 'image/png',
        'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'ico' => 'image/x-icon',
        'swf' => 'application/x-shockwave-flash', 'pdf' => 'application/pdf',
        'zip' => 'application/zip', 'gz' => 'application/x-gzip',
        'tar' => 'application/x-tar', 'bz' => 'application/x-bzip',
        'bz2' => 'application/x-bzip2', 'txt' => 'text/plain',
        'asc' => 'text/plain', 'htm' => 'text/html', 'html' => 'text/html',
        'css' => 'text/css', 'js' => 'text/javascript',
        'xml' => 'text/xml', 'xsl' => 'application/xsl+xml',
        'ogg' => 'application/ogg', 'mp3' => 'audio/mpeg', 'wav' => 'audio/x-wav',
        'avi' => 'video/x-msvideo', 'mpg' => 'video/mpeg', 'mpeg' => 'video/mpeg',
        'mov' => 'video/quicktime', 'flv' => 'video/x-flv', 'php' => 'text/x-php'
      );
      $ext = strtolower(pathInfo($file, PATHINFO_EXTENSION));
      return isset($exts[$ext]) ? $exts[$ext] : 'application/octet-stream';
    }


    /**
    * Generate the auth string: "AWS AccessKey:Signature"
    *
    * @internal Used by S3Request::getResponse()
    * @param string $string String to sign
    * @return string
    */
    public static function __getSignature($string)
    {
      return 'AWS '.self::$__accessKey.':'.self::__getHash($string);
    }


    /**
    * Creates a HMAC-SHA1 hash
    *
    * This uses the hash extension if loaded
    *
    * @internal Used by __getSignature()
    * @param string $string String to sign
    * @return string
    */
    private static function __getHash($string)
    {
      return base64_encode(extension_loaded('hash') ?
      hash_hmac('sha1', $string, self::$__secretKey, true) : pack('H*', sha1(
      (str_pad(self::$__secretKey, 64, chr(0x00)) ^ (str_repeat(chr(0x5c), 64))) .
      pack('H*', sha1((str_pad(self::$__secretKey, 64, chr(0x00)) ^
      (str_repeat(chr(0x36), 64))) . $string)))));
    }

  }

  final class S3Request
  {
    private $endpoint, $verb, $bucket, $uri, $resource = '', $parameters = array(),
    $amzHeaders = array(), $headers = array(
      'Host' => '', 'Date' => '', 'Content-MD5' => '', 'Content-Type' => ''
    );
    public $fp = false, $size = 0, $data = false, $response;


    /**
    * Constructor
    *
    * @param string $verb Verb
    * @param string $bucket Bucket name
    * @param string $uri Object URI
    * @return mixed
    */
    function __construct($verb, $bucket = '', $uri = '', $endpoint = 's3.amazonaws.com')
    {
      $this->endpoint = $endpoint;
      $this->verb = $verb;
      $this->bucket = $bucket;
      $this->uri = $uri !== '' ? '/'.str_replace('%2F', '/', rawurlencode($uri)) : '/';

      //if ($this->bucket !== '')
      //  $this->resource = '/'.$this->bucket.$this->uri;
      //else
      //  $this->resource = $this->uri;

      if ($this->bucket !== '')
      {
        if ($this->__dnsBucketName($this->bucket))
        {
          $this->headers['Host'] = $this->bucket.'.'.$this->endpoint;
          $this->resource = '/'.$this->bucket.$this->uri;
        }
        else
        {
          $this->headers['Host'] = $this->endpoint;
          $this->uri = $this->uri;
          if ($this->bucket !== '') $this->uri = '/'.$this->bucket.$this->uri;
          $this->bucket = '';
          $this->resource = $this->uri;
        }
      }
      else
      {
        $this->headers['Host'] = $this->endpoint;
        $this->resource = $this->uri;
      }


      $this->headers['Date'] = gmdate('D, d M Y H:i:s T');
      $this->response = new STDClass;
      $this->response->error = false;
    }


    /**
    * Set request parameter
    *
    * @param string $key Key
    * @param string $value Value
    * @return void
    */
    public function setParameter($key, $value)
    {
      $this->parameters[$key] = $value;
    }


    /**
    * Set request header
    *
    * @param string $key Key
    * @param string $value Value
    * @return void
    */
    public function setHeader($key, $value)
    {
      $this->headers[$key] = $value;
    }


    /**
    * Set x-amz-meta-* header
    *
    * @param string $key Key
    * @param string $value Value
    * @return void
    */
    public function setAmzHeader($key, $value)
    {
      $this->amzHeaders[$key] = $value;
    }


    /**
    * Get the S3 response
    *
    * @return object | false
    */
    public function getResponse()
    {
      $query = '';
      if (sizeof($this->parameters) > 0)
      {
        $query = substr($this->uri, -1) !== '?' ? '?' : '&';
        foreach ($this->parameters as $var => $value)
          if ($value == null || $value == '') $query .= $var.'&';
          // Parameters should be encoded (thanks Sean O'Dea)
          else $query .= $var.'='.rawurlencode($value).'&';
        $query = substr($query, 0, -1);
        $this->uri .= $query;

        if (array_key_exists('acl', $this->parameters) ||
        array_key_exists('location', $this->parameters) ||
        array_key_exists('torrent', $this->parameters) ||
        array_key_exists('website', $this->parameters) ||
        array_key_exists('logging', $this->parameters))
          $this->resource .= $query;
      }
      $url = (S3::$useSSL ? 'https://' : 'http://') . ($this->headers['Host'] !== '' ? $this->headers['Host'] : $this->endpoint) . $this->uri;

      //var_dump('bucket: ' . $this->bucket, 'uri: ' . $this->uri, 'resource: ' . $this->resource, 'url: ' . $url);

      // Basic setup
      $curl = curl_init();
      curl_setopt($curl, CURLOPT_USERAGENT, 'S3/php');

      if (S3::$useSSL)
      {
        // SSL Validation can now be optional for those with broken OpenSSL installations
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, S3::$useSSLValidation ? 1 : 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, S3::$useSSLValidation ? 1 : 0);

        if (S3::$sslKey !== null) curl_setopt($curl, CURLOPT_SSLKEY, S3::$sslKey);
        if (S3::$sslCert !== null) curl_setopt($curl, CURLOPT_SSLCERT, S3::$sslCert);
        if (S3::$sslCACert !== null) curl_setopt($curl, CURLOPT_CAINFO, S3::$sslCACert);
      }

      curl_setopt($curl, CURLOPT_URL, $url);

      if (S3::$proxy != null && isset(S3::$proxy['host']))
      {
        curl_setopt($curl, CURLOPT_PROXY, S3::$proxy['host']);
        curl_setopt($curl, CURLOPT_PROXYTYPE, S3::$proxy['type']);
        if (isset(S3::$proxy['user'], S3::$proxy['pass']) && $proxy['user'] != null && $proxy['pass'] != null)
          curl_setopt($curl, CURLOPT_PROXYUSERPWD, sprintf('%s:%s', S3::$proxy['user'], S3::$proxy['pass']));
      }

      // Headers
      $headers = array(); $amz = array();
      foreach ($this->amzHeaders as $header => $value)
        if (strlen($value) > 0) $headers[] = $header.': '.$value;
      foreach ($this->headers as $header => $value)
        if (strlen($value) > 0) $headers[] = $header.': '.$value;

      // Collect AMZ headers for signature
      foreach ($this->amzHeaders as $header => $value)
        if (strlen($value) > 0) $amz[] = strtolower($header).':'.$value;

      // AMZ headers must be sorted
      if (sizeof($amz) > 0)
      {
        sort($amz);
        $amz = "\n".implode("\n", $amz);
      } else $amz = '';

      if (S3::hasAuth())
      {
        // Authorization string (CloudFront stringToSign should only contain a date)
        if ($this->headers['Host'] == 'cloudfront.amazonaws.com')
          $headers[] = 'Authorization: ' . S3::__getSignature($this->headers['Date']);
        else
        {
          $headers[] = 'Authorization: ' . S3::__getSignature(
            $this->verb."\n".
            $this->headers['Content-MD5']."\n".
            $this->headers['Content-Type']."\n".
            $this->headers['Date'].$amz."\n".
            $this->resource
          );
        }
          }

      curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($curl, CURLOPT_HEADER, false);
      curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
      curl_setopt($curl, CURLOPT_WRITEFUNCTION, array(&$this, '__responseWriteCallback'));
      curl_setopt($curl, CURLOPT_HEADERFUNCTION, array(&$this, '__responseHeaderCallback'));
      curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);

      // Request types
      switch ($this->verb)
      {
        case 'GET': break;
        case 'PUT': case 'POST': // POST only used for CloudFront
          if ($this->fp !== false)
          {
            curl_setopt($curl, CURLOPT_PUT, true);
            curl_setopt($curl, CURLOPT_INFILE, $this->fp);
            if ($this->size >= 0)
              curl_setopt($curl, CURLOPT_INFILESIZE, $this->size);
          }
          elseif ($this->data !== false)
          {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $this->verb);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $this->data);
          }
          else
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $this->verb);
        break;
        case 'HEAD':
          curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'HEAD');
          curl_setopt($curl, CURLOPT_NOBODY, true);
        break;
        case 'DELETE':
          curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
        break;
        default: break;
      }

      // Execute, grab errors
      if (curl_exec($curl))
        $this->response->code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
      else
        $this->response->error = array(
          'code' => curl_errno($curl),
          'message' => curl_error($curl),
          'resource' => $this->resource
        );

      @curl_close($curl);

      // Parse body into XML
      if ($this->response->error === false && isset($this->response->headers['type']) &&
      $this->response->headers['type'] == 'application/xml' && isset($this->response->body))
      {
        $this->response->body = simplexml_load_string($this->response->body);

        // Grab S3 errors
        if (!in_array($this->response->code, array(200, 204, 206)) &&
        isset($this->response->body->Code, $this->response->body->Message))
        {
          $this->response->error = array(
            'code' => (string)$this->response->body->Code,
            'message' => (string)$this->response->body->Message
          );
          if (isset($this->response->body->Resource))
            $this->response->error['resource'] = (string)$this->response->body->Resource;
          unset($this->response->body);
        }
      }

      // Clean up file resources
      if ($this->fp !== false && is_resource($this->fp)) fclose($this->fp);

      return $this->response;
    }


    /**
    * CURL write callback
    *
    * @param resource &$curl CURL resource
    * @param string &$data Data
    * @return integer
    */
    private function __responseWriteCallback(&$curl, &$data)
    {
      if (in_array($this->response->code, array(200, 206)) && $this->fp !== false)
        return fwrite($this->fp, $data);
      else
        $this->response->body .= $data;
      return strlen($data);
    }


    /**
    * Check DNS conformity
    *
    * @param string $bucket Bucket name
    * @return boolean
    */
    private function __dnsBucketName($bucket)
    {
      if (strlen($bucket) > 63 || !preg_match("/[^a-z0-9\.-]/", $bucket)) return false;
      if (strstr($bucket, '-.') !== false) return false;
      if (strstr($bucket, '..') !== false) return false;
      if (!preg_match("/^[0-9a-z]/", $bucket)) return false;
      if (!preg_match("/[0-9a-z]$/", $bucket)) return false;
      return true;
    }


    /**
    * CURL header callback
    *
    * @param resource &$curl CURL resource
    * @param string &$data Data
    * @return integer
    */
    private function __responseHeaderCallback(&$curl, &$data)
    {
      if (($strlen = strlen($data)) <= 2) return $strlen;
      if (substr($data, 0, 4) == 'HTTP')
        $this->response->code = (int)substr($data, 9, 3);
      else
      {
        $data = trim($data);
        if (strpos($data, ': ') === false) return $strlen;
        list($header, $value) = explode(': ', $data, 2);
        if ($header == 'Last-Modified')
          $this->response->headers['time'] = strtotime($value);
        elseif ($header == 'Content-Length')
          $this->response->headers['size'] = (int)$value;
        elseif ($header == 'Content-Type')
          $this->response->headers['type'] = $value;
        elseif ($header == 'ETag')
          $this->response->headers['hash'] = $value{0} == '"' ? substr($value, 1, -1) : $value;
        elseif (preg_match('/^x-amz-meta-.*$/', $header))
          $this->response->headers[$header] = is_numeric($value) ? (int)$value : $value;
      }
      return $strlen;
    }

  }

  class S3Exception extends Exception {
    function __construct($message, $file, $line, $code = 0)
    {
      parent::__construct($message, $code);
      $this->file = $file;
      $this->line = $line;
    }
  }

  $s3 = new S3($accessKey, $secretKey);
  $contents = $s3->getBucket('packages.couchbase.com', 'releases', null, null, '|');
  if (function_exists('cache_set')) {
    cache_set('s3downloadsListing', $contents);
  }
} else {
  $contents = cache_get('s3downloadsListing');
}

function collectFor($product_string, $contents) {

  $platform_names = array('rpm' => array('title'=>'Red Hat',  'icon'=>'redhat'),
                          'deb' => array('title'=>'Ubuntu',   'icon'=>'ubuntu'),
                          'exe' => array('title'=>'Windows',  'icon'=>'windows'),
                          'dmg' => array('title'=>'Mac OS X', 'icon'=>'mac'));

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
      || substr($filename, -3, 3) === 'md5'
      || substr($filename, -3, 3) === 'xml'
      || substr($filename, -3, 3) === 'txt'
      || substr($filename, 0, 10) === 'northscale'
      || substr($filename, 0, 15) === 'CouchbaseServer') continue;

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
      preg_match("/([A-Za-z\-]*)[-](enterprise|community)([_]?(win2008)?_(x86)[_]?(64)?)?[_]([0-9\.]*)[\.|_](.*)/",
        $filename, $matches);

      list(, $product, $edition, , $type, $arch, $bits, $version, $postfix) = $matches;

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

    unset($version, $product, $edition, $type, $arch, $bits, $version, $postfix);
  }

  function cmp($a, $b) {
    if ($a['version'] == $b['version']) {
      return 0;
    }
    return ($a['version'] > $b['version']) ? -1 : 1;
  }
  usort($output['releases'], 'cmp');

  return $output;
}

header('Content-Type: ' . $mimetype);

$membase_releases = collectFor('membase-server', $contents);

if ($mimetype === 'application/json') {
  print_r(json_encode($membase_releases));
} else {
  /**
   * A Mustache implementation in PHP.
   *
   * {@link http://defunkt.github.com/mustache}
   *
   * Mustache is a framework-agnostic logic-less templating language. It enforces separation of view
   * logic from template files. In fact, it is not even possible to embed logic in the template.
   *
   * This is very, very rad.
   *
   * @author Justin Hileman {@link http://justinhileman.com}
   */
  class Mustache {

    const VERSION      = '0.8.1';
    const SPEC_VERSION = '1.1.2';

    /**
     * Should this Mustache throw exceptions when it finds unexpected tags?
     *
     * @see self::_throwsException()
     */
    protected $_throwsExceptions = array(
      MustacheException::UNKNOWN_VARIABLE         => false,
      MustacheException::UNCLOSED_SECTION         => true,
      MustacheException::UNEXPECTED_CLOSE_SECTION => true,
      MustacheException::UNKNOWN_PARTIAL          => false,
      MustacheException::UNKNOWN_PRAGMA           => true,
    );

    // Override charset passed to htmlentities() and htmlspecialchars(). Defaults to UTF-8.
    protected $_charset = 'UTF-8';

    /**
     * Pragmas are macro-like directives that, when invoked, change the behavior or
     * syntax of Mustache.
     *
     * They should be considered extremely experimental. Most likely their implementation
     * will change in the future.
     */

    /**
     * The {{%UNESCAPED}} pragma swaps the meaning of the {{normal}} and {{{unescaped}}}
     * Mustache tags. That is, once this pragma is activated the {{normal}} tag will not be
     * escaped while the {{{unescaped}}} tag will be escaped.
     *
     * Pragmas apply only to the current template. Partials, even those included after the
     * {{%UNESCAPED}} call, will need their own pragma declaration.
     *
     * This may be useful in non-HTML Mustache situations.
     */
    const PRAGMA_UNESCAPED    = 'UNESCAPED';

    /**
     * Constants used for section and tag RegEx
     */
    const SECTION_TYPES = '\^#\/';
    const TAG_TYPES = '#\^\/=!<>\\{&';

    protected $_otag = '{{';
    protected $_ctag = '}}';

    protected $_tagRegEx;

    protected $_template = '';
    protected $_context  = array();
    protected $_partials = array();
    protected $_pragmas  = array();

    protected $_pragmasImplemented = array(
      self::PRAGMA_UNESCAPED
    );

    protected $_localPragmas = array();

    /**
     * Mustache class constructor.
     *
     * This method accepts a $template string and a $view object. Optionally, pass an associative
     * array of partials as well.
     *
     * Passing an $options array allows overriding certain Mustache options during instantiation:
     *
     *     $options = array(
     *         // `charset` -- must be supported by `htmlspecialentities()`. defaults to 'UTF-8'
     *         'charset' => 'ISO-8859-1',
     *
     *         // opening and closing delimiters, as an array or a space-separated string
     *         'delimiters' => '<% %>',
     *
     *         // an array of pragmas to enable/disable
     *         'pragmas' => array(
     *             Mustache::PRAGMA_UNESCAPED => true
     *         ),
     *     );
     *
     * @access public
     * @param string $template (default: null)
     * @param mixed $view (default: null)
     * @param array $partials (default: null)
     * @param array $options (default: array())
     * @return void
     */
    public function __construct($template = null, $view = null, $partials = null, array $options = null) {
      if ($template !== null) $this->_template = $template;
      if ($partials !== null) $this->_partials = $partials;
      if ($view !== null)     $this->_context = array($view);
      if ($options !== null)  $this->_setOptions($options);
    }

    /**
     * Helper function for setting options from constructor args.
     *
     * @access protected
     * @param array $options
     * @return void
     */
    protected function _setOptions(array $options) {
      if (isset($options['charset'])) {
        $this->_charset = $options['charset'];
      }

      if (isset($options['delimiters'])) {
        $delims = $options['delimiters'];
        if (!is_array($delims)) {
          $delims = array_map('trim', explode(' ', $delims, 2));
        }
        $this->_otag = $delims[0];
        $this->_ctag = $delims[1];
      }

      if (isset($options['pragmas'])) {
        foreach ($options['pragmas'] as $pragma_name => $pragma_value) {
          if (!in_array($pragma_name, $this->_pragmasImplemented, true)) {
            throw new MustacheException('Unknown pragma: ' . $pragma_name, MustacheException::UNKNOWN_PRAGMA);
          }
        }
        $this->_pragmas = $options['pragmas'];
      }
    }

    /**
     * Mustache class clone method.
     *
     * A cloned Mustache instance should have pragmas, delimeters and root context
     * reset to default values.
     *
     * @access public
     * @return void
     */
    public function __clone() {
      $this->_otag = '{{';
      $this->_ctag = '}}';
      $this->_localPragmas = array();

      if ($keys = array_keys($this->_context)) {
        $last = array_pop($keys);
        if ($this->_context[$last] instanceof Mustache) {
          $this->_context[$last] =& $this;
        }
      }
    }

    /**
     * Render the given template and view object.
     *
     * Defaults to the template and view passed to the class constructor unless a new one is provided.
     * Optionally, pass an associative array of partials as well.
     *
     * @access public
     * @param string $template (default: null)
     * @param mixed $view (default: null)
     * @param array $partials (default: null)
     * @return string Rendered Mustache template.
     */
    public function render($template = null, $view = null, $partials = null) {
      if ($template === null) $template = $this->_template;
      if ($partials !== null) $this->_partials = $partials;

      $otag_orig = $this->_otag;
      $ctag_orig = $this->_ctag;

      if ($view) {
        $this->_context = array($view);
      } else if (empty($this->_context)) {
        $this->_context = array($this);
      }

      $template = $this->_renderPragmas($template);
      $template = $this->_renderTemplate($template, $this->_context);

      $this->_otag = $otag_orig;
      $this->_ctag = $ctag_orig;

      return $template;
    }

    /**
     * Wrap the render() function for string conversion.
     *
     * @access public
     * @return string
     */
    public function __toString() {
      // PHP doesn't like exceptions in __toString.
      // catch any exceptions and convert them to strings.
      try {
        $result = $this->render();
        return $result;
      } catch (Exception $e) {
        return "Error rendering mustache: " . $e->getMessage();
      }
    }

    /**
     * Internal render function, used for recursive calls.
     *
     * @access protected
     * @param string $template
     * @return string Rendered Mustache template.
     */
    protected function _renderTemplate($template) {
      if ($section = $this->_findSection($template)) {
        list($before, $type, $tag_name, $content, $after) = $section;

        $rendered_before = $this->_renderTags($before);

        $rendered_content = '';
        $val = $this->_getVariable($tag_name);
        switch($type) {
          // inverted section
          case '^':
            if (empty($val)) {
              $rendered_content = $this->_renderTemplate($content);
            }
            break;

          // regular section
          case '#':
            // higher order sections
            if ($this->_varIsCallable($val)) {
              $rendered_content = $this->_renderTemplate(call_user_func($val, $content));
            } else if ($this->_varIsIterable($val)) {
              foreach ($val as $local_context) {
                $this->_pushContext($local_context);
                $rendered_content .= $this->_renderTemplate($content);
                $this->_popContext();
              }
            } else if ($val) {
              if (is_array($val) || is_object($val)) {
                $this->_pushContext($val);
                $rendered_content = $this->_renderTemplate($content);
                $this->_popContext();
              } else {
                $rendered_content = $this->_renderTemplate($content);
              }
            }
            break;
        }

        return $rendered_before . $rendered_content . $this->_renderTemplate($after);
      }

      return $this->_renderTags($template);
    }

    /**
     * Prepare a section RegEx string for the given opening/closing tags.
     *
     * @access protected
     * @param string $otag
     * @param string $ctag
     * @return string
     */
    protected function _prepareSectionRegEx($otag, $ctag) {
      return sprintf(
        '/(?:(?<=\\n)[ \\t]*)?%s(?:(?P<type>[%s])(?P<tag_name>.+?)|=(?P<delims>.*?)=)%s\\n?/s',
        preg_quote($otag, '/'),
        self::SECTION_TYPES,
        preg_quote($ctag, '/')
      );
    }

    /**
     * Extract the first section from $template.
     *
     * @access protected
     * @param string $template
     * @return array $before, $type, $tag_name, $content and $after
     */
    protected function _findSection($template) {
      $regEx = $this->_prepareSectionRegEx($this->_otag, $this->_ctag);

      $section_start = null;
      $section_type  = null;
      $content_start = null;

      $search_offset = 0;

      $section_stack = array();
      $matches = array();
      while (preg_match($regEx, $template, $matches, PREG_OFFSET_CAPTURE, $search_offset)) {
        if (isset($matches['delims'][0])) {
          list($otag, $ctag) = explode(' ', $matches['delims'][0]);
          $regEx = $this->_prepareSectionRegEx($otag, $ctag);
          $search_offset = $matches[0][1] + strlen($matches[0][0]);
          continue;
        }

        $match    = $matches[0][0];
        $offset   = $matches[0][1];
        $type     = $matches['type'][0];
        $tag_name = trim($matches['tag_name'][0]);

        $search_offset = $offset + strlen($match);

        switch ($type) {
          case '^':
          case '#':
            if (empty($section_stack)) {
              $section_start = $offset;
              $section_type  = $type;
              $content_start = $search_offset;
            }
            array_push($section_stack, $tag_name);
            break;
          case '/':
            if (empty($section_stack) || ($tag_name !== array_pop($section_stack))) {
              if ($this->_throwsException(MustacheException::UNEXPECTED_CLOSE_SECTION)) {
                throw new MustacheException('Unexpected close section: ' . $tag_name, MustacheException::UNEXPECTED_CLOSE_SECTION);
              }
            }

            if (empty($section_stack)) {
              // $before, $type, $tag_name, $content, $after
              return array(
                substr($template, 0, $section_start),
                $section_type,
                $tag_name,
                substr($template, $content_start, $offset - $content_start),
                substr($template, $search_offset),
              );
            }
            break;
        }
      }

      if (!empty($section_stack)) {
        if ($this->_throwsException(MustacheException::UNCLOSED_SECTION)) {
          throw new MustacheException('Unclosed section: ' . $section_stack[0], MustacheException::UNCLOSED_SECTION);
        }
      }
    }

    /**
     * Prepare a pragma RegEx for the given opening/closing tags.
     *
     * @access protected
     * @param string $otag
     * @param string $ctag
     * @return string
     */
    protected function _preparePragmaRegEx($otag, $ctag) {
      return sprintf(
        '/%s%%\\s*(?P<pragma_name>[\\w_-]+)(?P<options_string>(?: [\\w]+=[\\w]+)*)\\s*%s\\n?/s',
        preg_quote($otag, '/'),
        preg_quote($ctag, '/')
      );
    }

    /**
     * Initialize pragmas and remove all pragma tags.
     *
     * @access protected
     * @param string $template
     * @return string
     */
    protected function _renderPragmas($template) {
      $this->_localPragmas = $this->_pragmas;

      // no pragmas
      if (strpos($template, $this->_otag . '%') === false) {
        return $template;
      }

      $regEx = $this->_preparePragmaRegEx($this->_otag, $this->_ctag);
      return preg_replace_callback($regEx, array($this, '_renderPragma'), $template);
    }

    /**
     * A preg_replace helper to remove {{%PRAGMA}} tags and enable requested pragma.
     *
     * @access protected
     * @param mixed $matches
     * @return void
     * @throws MustacheException unknown pragma
     */
    protected function _renderPragma($matches) {
      $pragma         = $matches[0];
      $pragma_name    = $matches['pragma_name'];
      $options_string = $matches['options_string'];

      if (!in_array($pragma_name, $this->_pragmasImplemented)) {
        throw new MustacheException('Unknown pragma: ' . $pragma_name, MustacheException::UNKNOWN_PRAGMA);
      }

      $options = array();
      foreach (explode(' ', trim($options_string)) as $o) {
        if ($p = trim($o)) {
          $p = explode('=', $p);
          $options[$p[0]] = $p[1];
        }
      }

      if (empty($options)) {
        $this->_localPragmas[$pragma_name] = true;
      } else {
        $this->_localPragmas[$pragma_name] = $options;
      }

      return '';
    }

    /**
     * Check whether this Mustache has a specific pragma.
     *
     * @access protected
     * @param string $pragma_name
     * @return bool
     */
    protected function _hasPragma($pragma_name) {
      if (array_key_exists($pragma_name, $this->_localPragmas) && $this->_localPragmas[$pragma_name]) {
        return true;
      } else {
        return false;
      }
    }

    /**
     * Return pragma options, if any.
     *
     * @access protected
     * @param string $pragma_name
     * @return mixed
     * @throws MustacheException Unknown pragma
     */
    protected function _getPragmaOptions($pragma_name) {
      if (!$this->_hasPragma($pragma_name)) {
        throw new MustacheException('Unknown pragma: ' . $pragma_name, MustacheException::UNKNOWN_PRAGMA);
      }

      return (is_array($this->_localPragmas[$pragma_name])) ? $this->_localPragmas[$pragma_name] : array();
    }

    /**
     * Check whether this Mustache instance throws a given exception.
     *
     * Expects exceptions to be MustacheException error codes (i.e. class constants).
     *
     * @access protected
     * @param mixed $exception
     * @return void
     */
    protected function _throwsException($exception) {
      return (isset($this->_throwsExceptions[$exception]) && $this->_throwsExceptions[$exception]);
    }

    /**
     * Prepare a tag RegEx for the given opening/closing tags.
     *
     * @access protected
     * @param string $otag
     * @param string $ctag
     * @return string
     */
    protected function _prepareTagRegEx($otag, $ctag, $first = false) {
      return sprintf(
        '/(?P<leading>(?:%s\\r?\\n)[ \\t]*)?%s(?P<type>[%s]?)(?P<tag_name>.+?)(?:\\2|})?%s(?P<trailing>\\s*(?:\\r?\\n|\\Z))?/s',
        ($first ? '\\A|' : ''),
        preg_quote($otag, '/'),
        self::TAG_TYPES,
        preg_quote($ctag, '/')
      );
    }

    /**
     * Loop through and render individual Mustache tags.
     *
     * @access protected
     * @param string $template
     * @return void
     */
    protected function _renderTags($template) {
      if (strpos($template, $this->_otag) === false) {
        return $template;
      }

      $first = true;
      $this->_tagRegEx = $this->_prepareTagRegEx($this->_otag, $this->_ctag, true);

      $html = '';
      $matches = array();
      while (preg_match($this->_tagRegEx, $template, $matches, PREG_OFFSET_CAPTURE)) {
        $tag      = $matches[0][0];
        $offset   = $matches[0][1];
        $modifier = $matches['type'][0];
        $tag_name = trim($matches['tag_name'][0]);

        if (isset($matches['leading']) && $matches['leading'][1] > -1) {
          $leading = $matches['leading'][0];
        } else {
          $leading = null;
        }

        if (isset($matches['trailing']) && $matches['trailing'][1] > -1) {
          $trailing = $matches['trailing'][0];
        } else {
          $trailing = null;
        }

        $html .= substr($template, 0, $offset);

        $next_offset = $offset + strlen($tag);
        if ((substr($html, -1) == "\n") && (substr($template, $next_offset, 1) == "\n")) {
          $next_offset++;
        }
        $template = substr($template, $next_offset);

        $html .= $this->_renderTag($modifier, $tag_name, $leading, $trailing);

        if ($first == true) {
          $first = false;
          $this->_tagRegEx = $this->_prepareTagRegEx($this->_otag, $this->_ctag);
        }
      }

      return $html . $template;
    }

    /**
     * Render the named tag, given the specified modifier.
     *
     * Accepted modifiers are `=` (change delimiter), `!` (comment), `>` (partial)
     * `{` or `&` (don't escape output), or none (render escaped output).
     *
     * @access protected
     * @param string $modifier
     * @param string $tag_name
     * @param string $leading Whitespace
     * @param string $trailing Whitespace
     * @throws MustacheException Unmatched section tag encountered.
     * @return string
     */
    protected function _renderTag($modifier, $tag_name, $leading, $trailing) {
      switch ($modifier) {
        case '=':
          return $this->_changeDelimiter($tag_name, $leading, $trailing);
          break;
        case '!':
          return $this->_renderComment($tag_name, $leading, $trailing);
          break;
        case '>':
        case '<':
          return $this->_renderPartial($tag_name, $leading, $trailing);
          break;
        case '{':
          // strip the trailing } ...
          if ($tag_name[(strlen($tag_name) - 1)] == '}') {
            $tag_name = substr($tag_name, 0, -1);
          }
        case '&':
          if ($this->_hasPragma(self::PRAGMA_UNESCAPED)) {
            return $this->_renderEscaped($tag_name, $leading, $trailing);
          } else {
            return $this->_renderUnescaped($tag_name, $leading, $trailing);
          }
          break;
        case '#':
        case '^':
        case '/':
          // remove any leftover section tags
          return $leading . $trailing;
          break;
        default:
          if ($this->_hasPragma(self::PRAGMA_UNESCAPED)) {
            return $this->_renderUnescaped($modifier . $tag_name, $leading, $trailing);
          } else {
            return $this->_renderEscaped($modifier . $tag_name, $leading, $trailing);
          }
          break;
      }
    }

    /**
     * Returns true if any of its args contains the "\r" character.
     *
     * @access protected
     * @param string $str
     * @return boolean
     */
    protected function _stringHasR($str) {
      foreach (func_get_args() as $arg) {
        if (strpos($arg, "\r") !== false) {
          return true;
        }
      }
      return false;
    }

    /**
     * Escape and return the requested tag.
     *
     * @access protected
     * @param string $tag_name
     * @param string $leading Whitespace
     * @param string $trailing Whitespace
     * @return string
     */
    protected function _renderEscaped($tag_name, $leading, $trailing) {
      $rendered = htmlentities($this->_renderUnescaped($tag_name, '', ''), ENT_COMPAT, $this->_charset);
      return $leading . $rendered . $trailing;
    }

    /**
     * Render a comment (i.e. return an empty string).
     *
     * @access protected
     * @param string $tag_name
     * @param string $leading Whitespace
     * @param string $trailing Whitespace
     * @return string
     */
    protected function _renderComment($tag_name, $leading, $trailing) {
      if ($leading !== null && $trailing !== null) {
        if (strpos($leading, "\n") === false) {
          return '';
        }
        return $this->_stringHasR($leading, $trailing) ? "\r\n" : "\n";
      }
      return $leading . $trailing;
    }

    /**
     * Return the requested tag unescaped.
     *
     * @access protected
     * @param string $tag_name
     * @param string $leading Whitespace
     * @param string $trailing Whitespace
     * @return string
     */
    protected function _renderUnescaped($tag_name, $leading, $trailing) {
      $val = $this->_getVariable($tag_name);

      if ($this->_varIsCallable($val)) {
        $val = $this->_renderTemplate(call_user_func($val));
      }

      return $leading . $val . $trailing;
    }

    /**
     * Render the requested partial.
     *
     * @access protected
     * @param string $tag_name
     * @param string $leading Whitespace
     * @param string $trailing Whitespace
     * @return string
     */
    protected function _renderPartial($tag_name, $leading, $trailing) {
      $partial = $this->_getPartial($tag_name);
      if ($leading !== null && $trailing !== null) {
        $whitespace = trim($leading, "\r\n");
        $partial = preg_replace('/(\\r?\\n)(?!$)/s', "\\1" . $whitespace, $partial);
      }

      $view = clone($this);

      if ($leading !== null && $trailing !== null) {
        return $leading . $view->render($partial);
      } else {
        return $leading . $view->render($partial) . $trailing;
      }
    }

    /**
     * Change the Mustache tag delimiter. This method also replaces this object's current
     * tag RegEx with one using the new delimiters.
     *
     * @access protected
     * @param string $tag_name
     * @param string $leading Whitespace
     * @param string $trailing Whitespace
     * @return string
     */
    protected function _changeDelimiter($tag_name, $leading, $trailing) {
      list($otag, $ctag) = explode(' ', $tag_name);
      $this->_otag = $otag;
      $this->_ctag = $ctag;

      $this->_tagRegEx = $this->_prepareTagRegEx($this->_otag, $this->_ctag);

      if ($leading !== null && $trailing !== null) {
        if (strpos($leading, "\n") === false) {
          return '';
        }
        return $this->_stringHasR($leading, $trailing) ? "\r\n" : "\n";
      }
      return $leading . $trailing;
    }

    /**
     * Push a local context onto the stack.
     *
     * @access protected
     * @param array &$local_context
     * @return void
     */
    protected function _pushContext(&$local_context) {
      $new = array();
      $new[] =& $local_context;
      foreach (array_keys($this->_context) as $key) {
        $new[] =& $this->_context[$key];
      }
      $this->_context = $new;
    }

    /**
     * Remove the latest context from the stack.
     *
     * @access protected
     * @return void
     */
    protected function _popContext() {
      $new = array();

      $keys = array_keys($this->_context);
      array_shift($keys);
      foreach ($keys as $key) {
        $new[] =& $this->_context[$key];
      }
      $this->_context = $new;
    }

    /**
     * Get a variable from the context array.
     *
     * If the view is an array, returns the value with array key $tag_name.
     * If the view is an object, this will check for a public member variable
     * named $tag_name. If none is available, this method will execute and return
     * any class method named $tag_name. Failing all of the above, this method will
     * return an empty string.
     *
     * @access protected
     * @param string $tag_name
     * @throws MustacheException Unknown variable name.
     * @return string
     */
    protected function _getVariable($tag_name) {
      if ($tag_name === '.') {
        return $this->_context[0];
      } else if (strpos($tag_name, '.') !== false) {
        $chunks = explode('.', $tag_name);
        $first = array_shift($chunks);

        $ret = $this->_findVariableInContext($first, $this->_context);
        while ($next = array_shift($chunks)) {
          // Slice off a chunk of context for dot notation traversal.
          $c = array($ret);
          $ret = $this->_findVariableInContext($next, $c);
        }
        return $ret;
      } else {
        return $this->_findVariableInContext($tag_name, $this->_context);
      }
    }

    /**
     * Get a variable from the context array. Internal helper used by getVariable() to abstract
     * variable traversal for dot notation.
     *
     * @access protected
     * @param string $tag_name
     * @param array $context
     * @throws MustacheException Unknown variable name.
     * @return string
     */
    protected function _findVariableInContext($tag_name, $context) {
      foreach ($context as $view) {
        if (is_object($view)) {
          if (method_exists($view, $tag_name)) {
            return $view->$tag_name();
          } else if (isset($view->$tag_name)) {
            return $view->$tag_name;
          }
        } else if (is_array($view) && array_key_exists($tag_name, $view)) {
          return $view[$tag_name];
        }
      }

      if ($this->_throwsException(MustacheException::UNKNOWN_VARIABLE)) {
        throw new MustacheException("Unknown variable: " . $tag_name, MustacheException::UNKNOWN_VARIABLE);
      } else {
        return '';
      }
    }

    /**
     * Retrieve the partial corresponding to the requested tag name.
     *
     * Silently fails (i.e. returns '') when the requested partial is not found.
     *
     * @access protected
     * @param string $tag_name
     * @throws MustacheException Unknown partial name.
     * @return string
     */
    protected function _getPartial($tag_name) {
      if (is_array($this->_partials) && isset($this->_partials[$tag_name])) {
        return $this->_partials[$tag_name];
      }

      if ($this->_throwsException(MustacheException::UNKNOWN_PARTIAL)) {
        throw new MustacheException('Unknown partial: ' . $tag_name, MustacheException::UNKNOWN_PARTIAL);
      } else {
        return '';
      }
    }

    /**
     * Check whether the given $var should be iterated (i.e. in a section context).
     *
     * @access protected
     * @param mixed $var
     * @return bool
     */
    protected function _varIsIterable($var) {
      return $var instanceof Traversable || (is_array($var) && !array_diff_key($var, array_keys(array_keys($var))));
    }

    /**
     * Higher order sections helper: tests whether the section $var is a valid callback.
     *
     * In Mustache.php, a variable is considered 'callable' if the variable is:
     *
     *  1. an anonymous function.
     *  2. an object and the name of a public function, i.e. `array($SomeObject, 'methodName')`
     *  3. a class name and the name of a public static function, i.e. `array('SomeClass', 'methodName')`
     *
     * @access protected
     * @param mixed $var
     * @return bool
     */
    protected function _varIsCallable($var) {
      return !is_string($var) && is_callable($var);
    }
  }


  /**
   * MustacheException class.
   *
   * @extends Exception
   */
  class MustacheException extends Exception {

    // An UNKNOWN_VARIABLE exception is thrown when a {{variable}} is not found
    // in the current context.
    const UNKNOWN_VARIABLE         = 0;

    // An UNCLOSED_SECTION exception is thrown when a {{#section}} is not closed.
    const UNCLOSED_SECTION         = 1;

    // An UNEXPECTED_CLOSE_SECTION exception is thrown when {{/section}} appears
    // without a corresponding {{#section}} or {{^section}}.
    const UNEXPECTED_CLOSE_SECTION = 2;

    // An UNKNOWN_PARTIAL exception is thrown whenever a {{>partial}} tag appears
    // with no associated partial.
    const UNKNOWN_PARTIAL          = 3;

    // An UNKNOWN_PRAGMA exception is thrown whenever a {{%PRAGMA}} tag appears
    // which can't be handled by this Mustache instance.
    const UNKNOWN_PRAGMA           = 4;

  }

  $m = new Mustache();
  if ($_SERVER['SERVER_NAME'] === 'localhost') {
    $downloads = file_get_contents('downloads.html');
    $installer = file_get_contents('installer.html');
  } else {
    $downloads = <<<EOD
<style type="text/css">
.cb-download-form{top:44px;z-index:1}
.cb-download{display:none}
</style>
<div style="position:relative">
<div class="cb-download-desc">
  Enterprise Edition or Community Edition. <a href="/couchbase-server/editions">Which one is right for me?</a></div>
<h3 class="step-1">
  Select a download</h3>
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
</div>
<script type="text/javascript">
jQuery(function($) {
  $('.cb-download-form select').change(function(ev) {
    $('.cb-download').fadeOut('fast');
    $('.cb-download[data-version=' + $(ev.target).val() + ']').fadeIn('fast');
  }).trigger('change');
});
</script>
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
    <div class="download-col1">
      <p>
        <a href="{{x86/64.enterprise.url}}">{{version}} Release</a> | <a href="{{x86/64.enterprise.md5}}">[md5]</a></p>
      <p>
        <a href="{{x86.enterprise.url}}">{{version}} Release</a> | <a href="{{x86.enterprise.md5}}">[md5]</a></p>
      <p class="notes">
        <a href="#">Release Notes</a> &nbsp;&nbsp; <a href="#">Manual</a></p>
    </div>
    <div class="download-col2">
      <p>
        <a href="{{x86/64.community.url}}">{{version}} Release</a> | <a href="{{x86/64.community.md5}}">[md5]</a></p>
      <p>
        <a href="{{x86.community.url}}">{{version}} Release</a> | <a href="{{x86.community.md5}}">[md5]</a></p>
      <p class="notes">
        <a href="#">Release Notes</a> &nbsp;&nbsp; <a href="#">Manual</a></p>
    </div>
EOD;
  }
  echo $m->render($downloads, $membase_releases, compact('installer'));
}
