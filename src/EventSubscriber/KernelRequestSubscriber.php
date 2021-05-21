<?php

namespace Drupal\iq_stage_file_proxy\EventSubscriber;

use Drupal\Core\StreamWrapper\PublicStream;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Routing\TrustedRedirectResponse;

/**
 * Class KernelRequestSubscriber.
 */
class KernelRequestSubscriber implements EventSubscriberInterface {

  /**
   * Whether we will offload remote assets locally.
   *
   * @var string
   */
  protected $offload = FALSE;

  /**
   * Constructs a new KernelRequestSubscriber object.
   */
  public function __construct() {
    $this->offload = \Drupal::config('iq_stage_file_proxy.settings')->get('offload') ?: $this->offload;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events['kernel.request'] = ['kernelRequest', 1000];

    return $events;
  }

  /**
   * This method is called when the kernel.request is dispatched.
   *
   * @param \Symfony\Component\EventDispatcher\Event $event
   *   The dispatched event.
   */
  public function kernelRequest(Event $event) {
    // Was there
    if ($missingPublicAssetFilePath = $this->getMissingPublicAssetFilePath($event)) {
      $response = $this->generateProxyResponse($missingPublicAssetFilePath);
      $event->setResponse($response);
      $event->stopPropagation();
    }
    return;
  }

  /**
   * Redirects to a remote instance or returns an (offloaded) binary response.
   * 
   * The offloading scenario should run once; afterwards a request for the same asset,
   * should be handled by the webserver since it is stored there for efficiency.
   *
   * @return void
   */
  private function generateProxyResponse($missingPublicAssetFilePath) {
    $missingFileAsRemoteInstanceUrl = $this->getAssetAsRemoteInstanceUrl($missingFilePath);
    $response = new TrustedRedirectResponse($missingFileAsRemoteInstanceUrl);
    if ($this->offload) {
      $response = $this->offloadRemoteAsset($missingFileAsRemoteInstanceUrl, $missingFilePath);
    }
    return $response;
  }

  /**
   * Fetches an asset from a remote instance and saves it locally in the same path as requested.
   *
   * @param [type] $url
   * @return void
   */
  private function offloadRemoteAsset($url, $missingFilePath) {
    // Get the data.
    $data = \file_get_contents($missingFileAsRemoteInstanceUrl);
    // Save the file locally as the original request path.
    $dirs = dirname(DRUPAL_ROOT . $missingFilePath);
    is_dir($dirs) || mkdir($dirs);
    \file_put_contents(DRUPAL_ROOT . $missingFilePath, $data);
    // Generate a Response for downloading the file.
    $response = new Response();
    $response->headers->set('Content-Type', 'application/force-download');
    $response->setContent($data);
  }

  /**
   * Uses the LocalDevPublicStream wrapper for generating URLs pointing to a remote instance.
   *
   * @param [type] $path
   * @return void
   */
  private function getAssetAsRemoteInstanceUrl($path) {
    $uri = $this->wrapAsPublicStream($missingFilePath);
    // And hand it over to our LocalDevPublicStream wrapper.
    return \file_create_url($uri);
  }

  /**
   * Converts a path to a public stream wrapped URI.
   */
  private function wrapAsPublicStream($path) {
    return \str_replace($this->getBasePath(), 'public:/', $path);
  }

  /**
   * Get the basepath for all asset requests.
   */
  private function getBasePath() {
    // $basePath is typically /sites/default/files
    return '/' . PublicStream::basePath();
  }

  /**
   * Checks if a file exists locally using realpath().
   *
   * If it does exist, return FALSE.
   * If it doesn't exist, return the mildly processed path.
   */
  private function getMissingPublicAssetFilePath(Event $event) {
    $path = $event->getRequest()->getPathInfo();
    $path = $path === '/' ? $path : \rtrim($path, '/');
    return (\strpos($path, $this->getBasePath()) === 0 && !\realpath(DRUPAL_ROOT . $path)) ? $path : FALSE;
  }

}
