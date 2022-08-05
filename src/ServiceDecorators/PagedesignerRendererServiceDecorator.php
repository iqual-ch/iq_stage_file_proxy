<?php

namespace Drupal\iq_stage_file_proxy\ServiceDecorators;

use Drupal\pagedesigner\Service\Renderer;

/**
 * PagedesignerRendererServiceDecorator service.
 */
class PagedesignerRendererServiceDecorator extends Renderer {

  /**
   * Return the styles generated by the last render.
   *
   * @return string
   *   The generated styles.
   */
  public function getStyles() {
    $rendered = parent::getStyles();
    // Modify the generated css; this implies string processing.
    \Drupal::service('module_handler')->alter('pagedesigner_get_rendered_styles', $rendered);
    return $rendered;
  }

}