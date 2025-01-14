<?php

namespace Drupal\neo_site_settings;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Symfony\Component\Routing\Route;

/**
 * Provides routes for config page type entities.
 *
 * @see Drupal\Core\Entity\Routing\AdminHtmlRouteProvider
 * @see Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider
 */
class SiteSettingsTypeHtmlRouteProvider extends AdminHtmlRouteProvider {

  /**
   * {@inheritdoc}
   */
  public function getRoutes(EntityTypeInterface $entity_type) {
    $collection = parent::getRoutes($entity_type);

    $entity_type_id = $entity_type->id();

    if ($form_route = $this->getPageFormRoute($entity_type)) {
      $collection->add("entity.$entity_type_id.page_form", $form_route);
    }

    return $collection;
  }

  /**
   * Gets the neo config page form route.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   *
   * @return \Symfony\Component\Routing\Route|null
   *   The generated route, if available.
   */
  protected function getPageFormRoute(EntityTypeInterface $entity_type) {
    if ($entity_type->hasLinkTemplate('page-form')) {
      $entity_type_id = $entity_type->id();
      $route = new Route($entity_type->getLinkTemplate('page-form'));
      $route
        ->setDefaults([
          '_controller' => '\Drupal\neo_site_settings\Controller\SiteSettingsController::form',
          '_title_callback' => '\Drupal\neo_site_settings\Controller\SiteSettingsController::formTitle',
        ])
        ->setRequirement('_entity_access', "{$entity_type_id}.page_update")
        ->setOption('parameters', [
          $entity_type_id => ['type' => 'entity:' . $entity_type_id],
        ]);

      // Entity types with serial IDs can specify this in their route
      // requirements, improving the matching process.
      if ($this->getEntityTypeIdKeyType($entity_type) === 'integer') {
        $route->setRequirement($entity_type_id, '\d+');
      }
      return $route;
    }
  }

}
