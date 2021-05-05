<?php

namespace Drupal\iq_stage_file_proxy\StreamWrapper;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\StreamWrapper\PublicStream;

/**
 * Overrides the default Drupal public stream wrapper class for read operations.
 *
 * Provides access to public files available via an external remote instance,
 * usually the production instance.
 */
class LocalDevPublicStream extends PublicStream {

  /**
   * The diff represented as an array of rows.
   *
   * @var string
   */
  protected $remoteInstance = '';

  /**
   * Creates a LocalDevPublicStream.
   */
  public function __construct() {
    $this->remoteInstance = \Drupal::config('iq_stage_file_proxy.settings')->get('remote_instance');
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return t('Public files from a production origin');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return t('Public local files served by the production webserver.');
  }

  /**
   * {@inheritdoc}
   */
  public function getExternalUrl() {
    // Check if we need to load the resource from a local path,
    // i.e. if the file is not found on our local filesystem.
    if ($path = $this->fetchFromRemoteInstance($this->uri)) {
      return $path;
    }
    return parent::getExternalUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function stream_open($uri, $mode, $options, &$opened_path) {
    // If we are not in reading mode, delegate to parent stream wrapper.
    if (!in_array($mode, ['r', 'rb'])) {
      return parent::stream_open($uri, $mode, $options, $opened_path);
    }
    $this->uri = $uri;
    // Check if we need to fetch the file from the remote instance,
    // i.e. if the file is not found on our local filesystem.
    $path = $this->fetchFromRemoteInstance($this->uri) ?: $this->getLocalPath();

    // The rest is a copy+n+paste from PublicStream::stream_open().
    $this->handle = ($options & STREAM_REPORT_ERRORS) ? fopen($path, $mode) : @fopen($path, $mode);

    if ((bool) $this->handle && $options & STREAM_USE_PATH) {
      $opened_path = $path;
    }

    return (bool) $this->handle;
  }

  /**
   * Generates an external URL for file URIs that are not local.
   *
   * @param string $uri
   * @return bool|string
   */
  private function fetchFromRemoteInstance($uri) {
    $path = $this->getDirectoryPath() . '/' . $this->getTarget($uri);
    $realpath = realpath($path);
    return $realpath ?
      FALSE :
      $this->remoteInstance . '/' . UrlHelper::encodePath($path);
  }

}
