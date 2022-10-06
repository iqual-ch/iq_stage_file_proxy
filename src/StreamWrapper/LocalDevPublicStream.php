<?php

namespace Drupal\iq_stage_file_proxy\StreamWrapper;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\remote_stream_wrapper\StreamWrapper\HttpStreamWrapper;

/**
 * Overrides the default Drupal public stream wrapper class for read operations.
 *
 * Provides access to public files available via an external remote instance,
 * usually the production instance.
 */
class LocalDevPublicStream extends PublicStream {

  /**
   * The host used to load public assets from.
   *
   * @var string
   */
  protected $remoteInstance = '';

  /**
   * Whether we will offload remote assets locally.
   *
   * @var string
   */
  protected $offload = FALSE;

  /**
   * Creates a LocalDevPublicStream.
   */
  public function __construct() {
    $this->remoteInstance = \Drupal::config('iq_stage_file_proxy.settings')->get('remote_instance');
    $this->offload = \Drupal::config('iq_stage_file_proxy.settings')->get('offload') ?: $this->offload;
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
   * {@inheritdoc}
   * 
   * We are using drupal/remote_stream_wrapper's url_stat().
   */
  public function url_stat($uri, $flags) {
    $this->uri = $uri;

    // Check if we need to stat the file from the remote instance,
    // i.e. if the file is not found on our local filesystem.
    if (($remote_path = $this->fetchFromRemoteInstance($this->uri)) && !$this->offload) {
      return (new HttpStreamWrapper())->url_stat($remote_path, $flags);
    }
    $path = $this->getLocalPath();

    // Suppress warnings if requested or if the file or directory does not
    // exist. This is consistent with PHP's plain filesystem stream wrapper.
    if ($flags & STREAM_URL_STAT_QUIET || !file_exists($path)) {
      return @stat($path);
    }
    else {
      return stat($path);
    }
  }  

  /**
   * Generates a URL for file URIs that are not available locally.
   *
   * This will either generate a URL to a remote instance, or offload the asset from
   * the remote instance locally and redirect to the now available path.
   * The offloading scenario should run once; afterwards a request for the same asset,
   * should be handled by the webserver since it is stored there for efficiency.
   */
  private function fetchFromRemoteInstance($uri) {
    $localPath = $this->getDirectoryPath() . '/' . $this->getTarget($uri);
    $remotePath = realpath($localPath) ?
      FALSE :
      $this->remoteInstance . '/' . UrlHelper::encodePath($localPath);
    if ($remotePath && $this->offload) {
      $this->offloadRemoteAsset($remotePath, $localPath);
      return FALSE;
    }
    return $remotePath;
  }

  /**
   * Fetches an asset from a remote instance and saves it locally in the same path as requested.
   */
  private function offloadRemoteAsset($remotePath, $localPath) {
    // Get the data.
    $data = \file_get_contents($remotePath);
    // Save the file locally as the original request path.
    $dirs = dirname(DRUPAL_ROOT . '/' . $localPath);
    is_dir($dirs) || \Drupal::service('file_system')
      ->mkdir($dirs, NULL, TRUE);
    \file_put_contents(DRUPAL_ROOT . '/' . $localPath, $data);
  }

}
