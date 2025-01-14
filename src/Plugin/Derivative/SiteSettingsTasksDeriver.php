<?php

declare(strict_types = 1);

namespace Drupal\neo_site_settings\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Derives local tasks for entity types.
 */
class SiteSettingsTasksDeriver extends DeriverBase implements ContainerDeriverInterface {

  /**
   * Constructs an entity local tasks deriver.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    if (!$this->derivatives) {
      foreach ($this->entityTypeManager->getStorage('neo_site_settings_type')->loadMultiple() as $siteSettingsType) {
        /** @var \Drupal\neo_site_settings\SiteSettingsTypeInterface $siteSettingsType */
        if ($siteSettingsType->isAggregate()) {
          continue;
        }
        $siteSettingsTypeId = $siteSettingsType->id();
        $this->derivatives["entity.neo_site_settings.$siteSettingsTypeId"] = [
          'route_name' => 'entity.neo_site_settings_type.page_form',
          'route_parameters' => [
            'neo_site_settings_type' => $siteSettingsTypeId,
          ],
          'title' => $siteSettingsType->label(),
          'base_route' => "entity.neo_site_settings.collection",
          'icon' => 'cog',
        ] + $base_plugin_definition;
      }
    }
    return $this->derivatives;
  }

}
