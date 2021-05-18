<?php

namespace Drupal\iq_stage_file_proxy\EventSubscriber;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\StreamWrapper\PublicStream;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * Class KernelRequestSubscriber.
 */
class KernelRequestSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a new KernelRequestSubscriber object.
   */
  public function __construct() {

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
    $path = $event->getRequest()->getPathInfo();
    $path = $path === '/' ? $path : rtrim($path, '/');
    // $basePath is typically /sites/default/files
    $basePath = '/' . PublicStream::basePath();
    if (strpos($path, $basePath) === 0 &&
        !realpath(DRUPAL_ROOT . $path)) {
        // Convert to a public stream wrapper URI.
        $uri = str_replace($basePath, 'public:/', $path);
        // And hand it over to our LocalDevPublicStream wrapper.
        $url = file_create_url($uri);
        $response = new TrustedRedirectResponse($url);
        $event->setResponse($response);
        $event->stopPropagation();
    }
    return;
  }

}
