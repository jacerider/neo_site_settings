<?php

declare(strict_types=1);

namespace Drupal\neo_site_settings;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Drupal\neo_icon\IconTrait;

/**
 * Defines a class to build a listing of site settings type entities.
 *
 * @see \Drupal\neo_site_settings\Entity\SiteSettingsType
 */
final class SiteSettingsTypeListBuilder extends ConfigEntityListBuilder {

  use IconTrait;

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Label');
    $header['id'] = $this->t('ID');
    $header['aggregated'] = $this->t('Aggregated');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\neo_site_settings\SiteSettingsTypeInterface $entity */
    $row['label']['data'] = [
      '#type' => 'link',
      '#title' => $entity->label(),
      '#url' => $entity->isAggregate() ? Url::fromRoute('entity.neo_site_settings.collection') : $entity->toUrl('page-form'),
    ];
    $row['id']['data']['#markup'] = '<small>' . $entity->id() . '</small>';
    $row['aggregated']['data']['#markup'] = $entity->isAggregate() ? $this->icon('Yes')->iconOnly() : $this->icon('No')->iconOnly();
    $row['aggregated']['#neo_size'] = 'min';
    $row['aggregated']['#neo_align'] = 'center';
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();

    $build['table']['#empty'] = $this->t(
      'No site settings types available. <a href=":link">Add site settings type</a>.',
      [':link' => Url::fromRoute('entity.neo_site_settings_type.add_form')->toString()],
    );

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);
    // Place the edit operation after the operations added by field_ui.module
    // which have the weights 15, 20, 25.
    if (isset($operations['edit'])) {
      $operations['edit']['weight'] = 30;
    }
    return $operations;
  }

}
