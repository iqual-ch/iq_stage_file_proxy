<?php

namespace Drupal\Tests\iq_stage_file_proxy\Kernel;

use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\KernelTests\KernelTestBase;
use Drupal\iq_stage_file_proxy\EventSubscriber\KernelRequestSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests the redirect of requests for missing public files.
 *
 * @coversDefaultClass \Drupal\iq_stage_file_proxy\EventSubscriber\KernelRequestSubscriber
 * @group iq_stage_file_proxy
 */
#[CoversClass(KernelRequestSubscriber::class)]
#[Group('iq_stage_file_proxy')]
#[RunTestsInSeparateProcesses]
class KernelRequestSubscriberTest extends KernelTestBase {

  /**
   * The remote instance used in the tests.
   */
  const REMOTE_INSTANCE = 'https://origin.example.com';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'iq_stage_file_proxy'];

  /**
   * The public files directory on disk, relative to the Drupal root.
   *
   * @var string
   */
  protected string $publicPath;

  /**
   * {@inheritdoc}
   *
   * The subscriber resolves request paths against DRUPAL_ROOT, so the public
   * files directory must be a real path below it, not the default vfs://.
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    $this->publicPath = 'sites/simpletest/' . substr($this->databasePrefix, 4) . '/files';
    mkdir(DRUPAL_ROOT . '/' . $this->publicPath, 0777, TRUE);
    $this->setSetting('file_public_path', $this->publicPath);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    unset($GLOBALS['config']['iq_stage_file_proxy.settings']);
    \Drupal::service('file_system')->deleteRecursive(DRUPAL_ROOT . '/' . dirname($this->publicPath));
    parent::tearDown();
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Configured from settings.php in real setups, see README.md.
    $GLOBALS['config']['iq_stage_file_proxy.settings']['remote_instance'] = self::REMOTE_INSTANCE;
  }

  /**
   * Missing files are redirected to the remote instance, encoded once.
   *
   * @param string $requestTarget
   *   The URL-encoded path below the public files directory, as requested.
   * @param string $expectedTarget
   *   The URL-encoded path below the public files directory of the redirect.
   *
   * @dataProvider providerMissingFileRedirect
   */
  #[DataProvider('providerMissingFileRedirect')]
  public function testMissingFileRedirect(string $requestTarget, string $expectedTarget): void {
    $response = $this->dispatch('/' . PublicStream::basePath() . '/' . $requestTarget);

    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertSame(
      self::REMOTE_INSTANCE . '/' . PublicStream::basePath() . '/' . $expectedTarget,
      $response->getTargetUrl()
    );
  }

  /**
   * Data provider for testMissingFileRedirect().
   */
  public static function providerMissingFileRedirect(): array {
    return [
      'plain file name' => ['logos/plain.png', 'logos/plain.png'],
      'encoded @' => ['logos/sca_col%402x.png', 'logos/sca_col%402x.png'],
      'unencoded @' => ['logos/sca_col@2x.png', 'logos/sca_col%402x.png'],
      'umlaut and space' => ['bilder/K%C3%A4se%20gross.jpg', 'bilder/K%C3%A4se%20gross.jpg'],
      'literal +' => ['bilder/a+b.png', 'bilder/a%2Bb.png'],
      'encoded +' => ['bilder/a%2Bb.png', 'bilder/a%2Bb.png'],
    ];
  }

  /**
   * Requests that must not be handled by the subscriber.
   *
   * @param string $path
   *   The URL-encoded request path; "{files}" is replaced by the public files
   *   base path.
   *
   * @dataProvider providerNotRedirected
   */
  #[DataProvider('providerNotRedirected')]
  public function testNotRedirected(string $path): void {
    $path = str_replace('{files}', PublicStream::basePath(), $path);
    $this->assertNull($this->dispatch($path));
  }

  /**
   * Data provider for testNotRedirected().
   */
  public static function providerNotRedirected(): array {
    return [
      'non-public path' => ['/node/1'],
      'image style derivative' => ['/{files}/styles/thumbnail/public/logos/plain.png'],
      'encoded parent directory' => ['/{files}/%2e%2e/%2e%2e/settings.php'],
      'parent directory' => ['/{files}/logos/../../settings.php'],
    ];
  }

  /**
   * Files that exist locally are served locally, also with special names.
   */
  public function testExistingFileWithSpecialCharacters(): void {
    $directory = PublicStream::basePath() . '/logos';
    mkdir(DRUPAL_ROOT . '/' . $directory, 0777, TRUE);
    file_put_contents(DRUPAL_ROOT . '/' . $directory . '/Käse@2x.png', 'local');

    $this->assertNull($this->dispatch('/' . $directory . '/K%C3%A4se%402x.png'));
  }

  /**
   * Dispatches a main request for the given path to the subscriber.
   *
   * @param string $path
   *   The URL-encoded request path.
   *
   * @return \Symfony\Component\HttpFoundation\Response|null
   *   The response set by the subscriber, if any.
   */
  protected function dispatch(string $path) {
    $request = Request::create($path);
    $event = new RequestEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST);
    $this->container->get('iq_stage_file_proxy.kernel_request_subscriber')->kernelRequest($event);
    return $event->getResponse();
  }

}
