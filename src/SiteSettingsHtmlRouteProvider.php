<?php

namespace Drupal\neo_site_settings;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Symfony\Component\Routing\Route;

/**
 * Provides routes for config pages.
 *
 * @see \Drupal\Core\Entity\Routing\AdminHtmlRouteProvider
 * @see \Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider
 */
class SiteSettingsHtmlRouteProvider extends AdminHtmlRouteProvider {

  /**
   * {@inheritdoc}
   */
  protected function getCollectionRoute(EntityTypeInterface $entity_type) {
    // If the entity type does not provide an admin permission, there is no way
    // to control access, so we cannot provide a route in a sensible way.
    if ($entity_type->hasLinkTemplate('collection') && $entity_type->hasListBuilderClass() && ($admin_permission = $entity_type->getAdminPermission())) {
      /** @var \Drupal\Core\StringTranslation\TranslatableMarkup $label */
      $label = $entity_type->getCollectionLabel();

      $route = new Route($entity_type->getLinkTemplate('collection'));
      $route
        ->addDefaults([
          '_controller' => '\Drupal\neo_site_settings\Controller\SiteSettingsController::collection',
          '_title' => $label->getUntranslatedString(),
          '_title_arguments' => $label->getArguments(),
          '_title_context' => $label->getOption('context'),
        ]);
      $permissions = ['administer neo_site_settings'];
      $permissions = ['edit all neo_site_settings'];
      foreach ($this->entityTypeManager->getStorage('neo_site_settings_type')->loadMultiple() as $site_settings) {
        $permissions[] = 'edit ' . $site_settings->id() . ' site settings';
      }
      $route->setRequirement('_permission', implode('+', $permissions));

      return $route;
    }
  }

}
