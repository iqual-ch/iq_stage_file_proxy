<?php

namespace Drupal\iq_stage_file_proxy\StreamWrapper;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Overrides the default Drupal public stream wrapper class for read operations.
 *
 * Provides access to public files available via an external remote instance,
 * usually the production instance.
 */
class LocalDevPublicStream extends PublicStream {

  use StringTranslationTrait;

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
   * The Config Factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * Creates a LocalDevPublicStream.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The Config Factory service.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   */
  public function __construct(ConfigFactoryInterface $configFactory, FileSystemInterface $fileSystem) {
    $this->configFactory = $configFactory;
    $this->fileSystem = $fileSystem;

    $this->remoteInstance = $this->configFactory->get('iq_stage_file_proxy.settings')->get('remote_instance');
    $this->offload = $this->configFactory->get('iq_stage_file_proxy.settings')->get('offload') ?: $this->offload;
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return $this->t('Public files from a production origin');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Public local files served by the production webserver.');
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
  // phpcs:ignore
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
   * Generates a URL for file URIs that are not available locally.
   *
   * This will either generate a URL to a remote instance, or offload the
   * asset from the remote instance locally and redirect to the now available
   * path.
   *
   * The offloading scenario should run once; afterwards a request for the same
   * asset, should be handled by the webserver since it is stored there for
   * efficiency.
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
   * Offload Remote Asset.
   *
   * Fetches an asset from a remote instance and saves it
   * locally in the same path as requested.
   */
  private function offloadRemoteAsset($remotePath, $localPath) {
    // Get the data.
    $data = \file_get_contents($remotePath);
    // Save the file locally as the original request path.
    $dirs = dirname(DRUPAL_ROOT . '/' . $localPath);
    is_dir($dirs) || $this->fileSystem->mkdir($dirs, NULL, TRUE);
    \file_put_contents(DRUPAL_ROOT . '/' . $localPath, $data);
  }

}
