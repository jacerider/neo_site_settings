<?php

declare(strict_types=1);

namespace Drupal\neo_site_settings\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\neo_site_settings\SiteSettingsInterface;

/**
 * Defines the site settings entity class.
 *
 * @ContentEntityType(
 *   id = "neo_site_settings",
 *   label = @Translation("Site Settings"),
 *   label_collection = @Translation("Site Settings"),
 *   label_singular = @Translation("site settings"),
 *   label_plural = @Translation("site settings"),
 *   label_count = @PluralTranslation(
 *     singular = "@count site settings",
 *     plural = "@count site settings",
 *   ),
 *   bundle_label = @Translation("Site Settings type"),
 *   handlers = {
 *     "storage" = "Drupal\neo_site_settings\SiteSettingsStorage",
 *     "list_builder" = "Drupal\neo_site_settings\SiteSettingsListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "default" = "Drupal\neo_site_settings\Form\SiteSettingsForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\neo_site_settings\SiteSettingsHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "neo_site_settings",
 *   data_table = "neo_site_settings_field_data",
 *   translatable = TRUE,
 *   admin_permission = "administer neo_site_settings",
 *   entity_keys = {
 *     "id" = "id",
 *     "langcode" = "langcode",
 *     "bundle" = "bundle",
 *     "label" = "id",
 *     "uuid" = "uuid",
 *   },
 *   links = {
 *     "collection" = "/admin/settings",
 *   },
 *   bundle_entity_type = "neo_site_settings_type",
 *   field_ui_base_route = "entity.neo_site_settings_type.edit_form",
 * )
 */
final class SiteSettings extends ContentEntityBase implements SiteSettingsInterface {

  /**
   * {@inheritdoc}
   */
  public function label() {
    return $this->bundle->entity->label();
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields[$entity_type->getKey('id')] = BaseFieldDefinition::create('string')
      ->setSetting('max_length', 64)
      ->setRequired(TRUE)
      ->addConstraint('UniqueField')
      ->addPropertyConstraints('value', ['Regex' => ['pattern' => '/^[a-z0-9_]+$/']]);

    return $fields;
  }

}
