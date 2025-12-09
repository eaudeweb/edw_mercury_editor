<?php

namespace Drupal\edw_mercury_editor\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    // Mercury Editor hides the admin toolbar on its edit screen. Keep it
    // visible so editors retain the usual site navigation while editing.
    //
    // @see mercury_editor_preprocess_html()
    $route = $collection->get('mercury_editor.editor');
    if ($route instanceof Route) {
      $route->setOption('_hide_admin_toolbar', FALSE);
    }
  }

}
