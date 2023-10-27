<?php

namespace Drupal\iq_stage_file_proxy\EventSubscriber;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\StreamWrapper\PublicStream;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Kernel Request Subscriber.
 */
class KernelRequestSubscriber implements EventSubscriberInterface {

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * Constructs a KernelRequestSubscriber.
   *
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   */
  public function __construct(FileUrlGeneratorInterface $file_url_generator) {
    $this->fileUrlGenerator = $file_url_generator;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = [];
    $events[KernelEvents::REQUEST] = ['kernelRequest', 1000];

    return $events;
  }

  /**
   * This method is called when the kernel.request is dispatched.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The dispatched event.
   */
  public function kernelRequest(RequestEvent $event) {
    // Is this a request for a public assets file?
    if ($missingPublicAssetFilePath = $this->getMissingPublicAssetFilePath($event)) {
      $response = $this->generateProxyResponse($missingPublicAssetFilePath);
      $event->setResponse($response);
      $event->stopPropagation();
    }
  }

  /**
   * Generates a redirect response for a request to a missing public file asset.
   *
   * @return \Drupal\Core\Routing\TrustedRedirectResponse
   *   Return TrustedRedirectResponse.
   */
  private function generateProxyResponse($path) {
    // Converts a path to a public stream wrapped URI.
    $uri = $this->wrapAsPublicStream($path);
    // And hand it over to our LocalDevPublicStream wrapper.
    $url = $this->fileUrlGenerator->generateAbsoluteString($uri);
    return new TrustedRedirectResponse($url);
  }

  /**
   * Checks if a file exists locally using realpath().
   *
   * If it does exist, return FALSE.
   * If it doesn't exist, return the mildly processed path.
   *
   * @param Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The Request event.
   *
   * @return string|false
   *   The return value.
   */
  private function getMissingPublicAssetFilePath(RequestEvent $event) {
    $path = $event->getRequest()->getPathInfo();
    $path = $path === '/' ? $path : \rtrim($path, '/');
    // We don't serve non-public assets.
    if (!str_starts_with($path, (string) $this->getBasePath())) {
      return FALSE;
    }
    // We don't serve image styles, css or js assets.
    $stripped_path = \str_replace($this->getBasePath(), '', $path);
    if (str_starts_with($stripped_path, '/styles') ||
      str_starts_with($stripped_path, '/css') ||
      str_starts_with($stripped_path, '/js')) {
      return FALSE;
    }
    return (!\realpath(constant('DRUPAL_ROOT') . $path)) ? $path : FALSE;
  }

  /**
   * Converts a path to a public stream wrapped URI.
   */
  private function wrapAsPublicStream($path) {
    return \str_replace($this->getBasePath(), 'public:/', (string) $path);
  }

  /**
   * Gets the basepath for all asset requests.
   */
  private function getBasePath() {
    // $basePath is typically /sites/default/files
    return '/' . PublicStream::basePath();
  }

}
