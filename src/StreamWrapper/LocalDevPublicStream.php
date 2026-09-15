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
   * Directories below public:// whose content is never looked up remotely.
   *
   * Everything in these directories is generated locally by Drupal (image
   * style derivatives, aggregated CSS/JS, PHP storage). Proxying them would
   * either break local generation (a derivative reported as existing but not
   * readable locally) or serve assets built from the wrong configuration.
   *
   * Used by url_stat() and by the kernel request subscriber.
   */
  const LOCAL_ONLY_DIRECTORIES = ['styles', 'css', 'js', 'php'];

  /**
   * Request options for the remote HEAD request issued by url_stat().
   */
  const REMOTE_STAT_REQUEST_OPTIONS = [
    'timeout' => 3,
    'connect_timeout' => 2,
    'http_errors' => FALSE,
    'allow_redirects' => TRUE,
  ];

  /**
   * Per-request cache of remote stat results, keyed by URI.
   *
   * Holds either a stat array or FALSE (negative cache), so that e.g.
   * file_exists() followed by filesize() for the same URI costs a single
   * remote request.
   *
   * @var array
   */
  private static array $statCache = [];

  /**
   * The host used to load public assets from.
   *
   * @var string
   */
  protected $remoteInstance = '';

  /**
   * Whether we will offload remote assets locally.
   *
   * @var bool
   */
  protected $offload = FALSE;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Creates a LocalDevPublicStream.
   */
  public function __construct() {
    // @codingStandardsIgnoreStart
    // Stream wrappers are instantiated by PHP's stream system, not Drupal's DI container.
    $this->remoteInstance = \Drupal::config('iq_stage_file_proxy.settings')->get('remote_instance');
    $this->offload = \Drupal::config('iq_stage_file_proxy.settings')->get('offload') ?: $this->offload;
    $this->fileSystem = \Drupal::service('file_system');
    // @codingStandardsIgnoreEnd
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
   * Checks whether a public:// target lives in a locally generated directory.
   *
   * @param string $target
   *   The path relative to public://, without leading slash (e.g. as returned
   *   by getTarget()).
   *
   * @return bool
   *   TRUE if the target must never be proxied from the remote instance.
   */
  public static function isLocalOnlyTarget(string $target): bool {
    $target = ltrim($target, '/');
    foreach (static::LOCAL_ONLY_DIRECTORIES as $directory) {
      if ($target === $directory || str_starts_with($target, $directory . '/')) {
        return TRUE;
      }
    }
    return FALSE;
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
   * {@inheritdoc}
   *
   * PHP dispatches file_exists(), filesize(), is_file(), filemtime(), stat()
   * etc. to this method. Files that only exist on the remote instance would
   * otherwise be reported as missing, although stream_open() is able to read
   * them; core then emits "filesize(): stat failed" warnings and refuses to
   * generate image style derivatives for such sources.
   */
  // phpcs:ignore
  public function url_stat($uri, $flags) {
    $this->uri = $uri;
    $target = $this->getTarget($uri);
    $localPath = $this->getDirectoryPath() . '/' . $target;

    // Files available locally, locally generated assets and setups without a
    // remote instance are handled by the regular local filesystem stat.
    if (empty($this->remoteInstance) || realpath($localPath) || static::isLocalOnlyTarget($target)) {
      return parent::url_stat($uri, $flags);
    }

    if (array_key_exists($uri, static::$statCache)) {
      return static::$statCache[$uri];
    }

    // With offloading enabled this downloads the asset; once it is available
    // locally the regular stat applies. Otherwise (offloading disabled or
    // failed) we get the remote URL the file would be read from.
    $remoteUrl = $this->fetchFromRemoteInstance($uri);
    if ($remoteUrl === FALSE) {
      return parent::url_stat($uri, $flags);
    }

    static::$statCache[$uri] = $this->statRemoteAsset($remoteUrl, $flags);
    return static::$statCache[$uri];
  }

  /**
   * Stats an asset on the remote instance with a single HTTP HEAD request.
   *
   * @param string $remoteUrl
   *   The absolute URL of the asset on the remote instance.
   * @param int $flags
   *   The url_stat() flags. Failures are only logged when
   *   STREAM_URL_STAT_QUIET is not set.
   *
   * @return array|false
   *   A stat array as returned by stat(), or FALSE if the asset is not
   *   available on the remote instance or the request failed.
   */
  private function statRemoteAsset(string $remoteUrl, int $flags) {
    try {
      // @codingStandardsIgnoreStart
      // Stream wrappers are instantiated by PHP's stream system, not Drupal's DI container.
      $response = \Drupal::httpClient()->request('HEAD', $remoteUrl, static::REMOTE_STAT_REQUEST_OPTIONS);
      // @codingStandardsIgnoreEnd
      $status = $response->getStatusCode();
      if ($status >= 200 && $status < 300) {
        $size = (int) $response->getHeaderLine('Content-Length');
        $mtime = strtotime($response->getHeaderLine('Last-Modified')) ?: time();
        return $this->buildRemoteStat($size, $mtime);
      }
      $reason = 'HTTP ' . $status;
    }
    catch (\Throwable $e) {
      $reason = $e->getMessage();
    }

    if (!($flags & STREAM_URL_STAT_QUIET)) {
      // @codingStandardsIgnoreStart
      // Stream wrappers are instantiated by PHP's stream system, not Drupal's DI container.
      \Drupal::logger('iq_stage_file_proxy')->notice('Remote stat of @url failed: @reason', [
        '@url' => $remoteUrl,
        '@reason' => $reason,
      ]);
      // @codingStandardsIgnoreEnd
    }
    return FALSE;
  }

  /**
   * Builds a stat array for a regular file that only exists remotely.
   *
   * Mirrors the layout of PHP's stat(): numeric keys 0-12 plus their
   * associative counterparts.
   *
   * @param int $size
   *   The file size in bytes.
   * @param int $mtime
   *   The modification timestamp, also used for atime and ctime.
   *
   * @return array
   *   The stat array.
   */
  private function buildRemoteStat(int $size, int $mtime): array {
    $stat = [
      'dev' => 0,
      'ino' => 0,
      // Regular file, rw-r--r--.
      'mode' => 0100644,
      'nlink' => 1,
      'uid' => 0,
      'gid' => 0,
      'rdev' => 0,
      'size' => $size,
      'atime' => $mtime,
      'mtime' => $mtime,
      'ctime' => $mtime,
      'blksize' => -1,
      'blocks' => -1,
    ];
    return array_values($stat) + $stat;
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
   * efficiency. If offloading fails, the remote URL is returned so the caller
   * still gets a working resource.
   *
   * @param string $uri
   *   The public:// URI.
   *
   * @return string|false
   *   The remote URL, or FALSE if the file is available locally.
   */
  private function fetchFromRemoteInstance($uri) {
    $localPath = $this->getDirectoryPath() . '/' . $this->getTarget($uri);
    if (realpath($localPath)) {
      return FALSE;
    }
    $remotePath = $this->remoteInstance . '/' . UrlHelper::encodePath($localPath);
    if ($this->offload && $this->offloadRemoteAsset($remotePath, $localPath)) {
      // The asset is now available locally.
      return FALSE;
    }
    return $remotePath;
  }

  /**
   * Offload Remote Asset.
   *
   * Fetches an asset from a remote instance and saves it
   * locally in the same path as requested. Nothing is written if the remote
   * fetch fails, so a later request retries instead of finding an empty file.
   *
   * @param string $remotePath
   *   The absolute URL of the asset on the remote instance.
   * @param string $localPath
   *   The local filesystem path to store the asset at.
   *
   * @return bool
   *   TRUE if the asset was stored locally, FALSE otherwise.
   */
  private function offloadRemoteAsset($remotePath, $localPath): bool {
    // Get the data.
    $data = @\file_get_contents($remotePath);
    if ($data === FALSE || $data === '') {
      return FALSE;
    }
    $directory = dirname($localPath);
    if (!$this->fileSystem->prepareDirectory($directory, $this->fileSystem::CREATE_DIRECTORY | $this->fileSystem::MODIFY_PERMISSIONS)) {
      return FALSE;
    }
    return @\file_put_contents($localPath, $data) !== FALSE;
  }

}
