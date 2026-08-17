<?php

declare(strict_types=1);

namespace Drupal\neo_site_settings\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\neo_site_settings\SiteSettingsTypeInterface;

/**
 * Defines the Site Settings type configuration entity.
 *
 * @ConfigEntityType(
 *   id = "neo_site_settings_type",
 *   label = @Translation("Site Settings type"),
 *   label_collection = @Translation("Site Settings types"),
 *   label_singular = @Translation("site settings type"),
 *   label_plural = @Translation("site settings types"),
 *   label_count = @PluralTranslation(
 *     singular = "@count site settings type",
 *     plural = "@count site settings types",
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\neo_site_settings\SiteSettingsTypeStorage",
 *     "form" = {
 *       "add" = "Drupal\neo_site_settings\Form\SiteSettingsTypeForm",
 *       "edit" = "Drupal\neo_site_settings\Form\SiteSettingsTypeForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *     "access" = "Drupal\neo_site_settings\SiteSettingsTypeAccessControlHandler",
 *     "list_builder" = "Drupal\neo_site_settings\SiteSettingsTypeListBuilder",
 *     "route_provider" = {
 *       "html" = "Drupal\neo_site_settings\SiteSettingsTypeHtmlRouteProvider",
 *     },
 *   },
 *   admin_permission = "administer neo_site_settings",
 *   bundle_of = "neo_site_settings",
 *   config_prefix = "neo_site_settings_type",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "weight" = "weight",
 *   },
 *   links = {
 *     "add-form" = "/admin/structure/neo_site_settings_types/add",
 *     "edit-form" = "/admin/structure/neo_site_settings_types/manage/{neo_site_settings_type}",
 *     "delete-form" = "/admin/structure/neo_site_settings_types/manage/{neo_site_settings_type}/delete",
 *     "collection" = "/admin/structure/neo_site_settings_types",
 *     "page-form" = "/admin/settings/{neo_site_settings_type}"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "uuid",
 *     "weight",
 *     "aggregate",
 *     "icon",
 *   },
 * )
 */
final class SiteSettingsType extends ConfigEntityBundleBase implements SiteSettingsTypeInterface {

  /**
   * The machine name of this site settings type.
   */
  protected string $id;

  /**
   * The human-readable name of the site settings type.
   */
  protected string $label;

  /**
   * The weight of this settings type in relation to other settings types.
   *
   * @var int
   */
  protected $weight = 0;

  /**
   * Aggregate this form into a unified form.
   *
   * @var int
   */
  protected $aggregate = FALSE;

  /**
   * The icon for this site settings type.
   *
   * @var string
   */
  protected $icon = '';

  /**
   * Provides the list of site settings types.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   Storage interface.
   * @param array $entities
   *   Array of entities.
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities) {
    parent::preDelete($storage, $entities);
    // Delete by bundle rather than by id: the two are independent columns, so
    // selecting by id would leave behind any row whose id was remapped, and
    // that row's bundle would then point at a type that no longer exists.
    $settings_storage = \Drupal::entityTypeManager()->getStorage('neo_site_settings');
    $ids = $settings_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', array_keys($entities), 'IN')
      ->execute();
    if ($ids) {
      $settings_storage->delete($settings_storage->loadMultiple($ids));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setWeight(int $weight): self {
    $this->weight = $weight;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int {
    return $this->weight;
  }

  /**
   * {@inheritdoc}
   */
  public function setAggregate($aggregate = TRUE) {
    $this->aggregate = $aggregate;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isAggregate() {
    return !empty($this->aggregate);
  }

  /**
   * {@inheritdoc}
   */
  public function setIcon(string $icon): self {
    $this->icon = $icon;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getIcon(): string {
    if (is_array($this->icon)) {
      return '';
    }
    return $this->icon ?? '';
  }

  /**
   * Sorts by weight.
   */
  public static function sort(ConfigEntityInterface $a, ConfigEntityInterface $b) {
    /** @var \Drupal\neo_site_settings\SiteSettingsTypeInterface $a */
    /** @var \Drupal\neo_site_settings\SiteSettingsTypeInterface $b */
    return $a->getWeight() - $b->getWeight();
  }

}
