<?php

namespace Drupal\edw_mercury_editor;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Overrides Mercury Editor services.
 *
 * Swaps in a param converter that prevents stale tempstore entries from
 * overriding a more recently saved database version of an entity.
 */
class EdwMercuryEditorServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if ($container->hasDefinition('mercury_editor.param_converter')) {
      $definition = $container->getDefinition('mercury_editor.param_converter');
      $definition->setClass('Drupal\edw_mercury_editor\Routing\MercuryEditorParamConverter');
      // The override needs the request stack to tell read and write requests
      // apart. Append it as the fifth constructor argument.
      $definition->addArgument(new Reference('request_stack'));
    }
  }

}
