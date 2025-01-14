<?php

declare(strict_types=1);

namespace Drupal\neo_site_settings;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Provides a list controller for the site settings entity type.
 */
final class SiteSettingsListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['name'] = $this->t('Name');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\neo_site_settings\SiteSettingsInterface $entity */
    $row['name'] = $entity->label();
    return $row + parent::buildRow($entity);
  }

}
