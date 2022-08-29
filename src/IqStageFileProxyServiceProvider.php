<?php

namespace Drupal\iq_stage_file_proxy;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a service provider for the iq_stage_file_proxy module.
 */
class IqStageFileProxyServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   *
   * Set decoration_on_invalid: ignore since setting it inside services.yml has no effect.
   */
  public function alter(ContainerBuilder $container) {
    // For service iq_stage_file_proxy.pagedesigner.service.renderer.decorator.
    $decoratorService = $container->getDefinition('iq_stage_file_proxy.pagedesigner.service.renderer.decorator');
    if ($decoratedService = $decoratorService->getDecoratedService()) {
      $decoratorService->setDecoratedService($decoratedService[0], $decoratedService[1], $decoratedService[2], ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
    }
  }

}
