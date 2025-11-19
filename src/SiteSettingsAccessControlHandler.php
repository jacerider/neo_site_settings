<?php

namespace Drupal\neo_site_settings;

use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Access controller for the config page type entity.
 *
 * @see \Drupal\neo_site_settings\Entity\SiteSettingsType.
 */
class SiteSettingsAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\neo_site_settings\SiteSettingsInterface $entity */
    if ($operation == 'update') {
      return AccessResult::allowedIfHasPermission($account, 'administer neo_site_settings')
        ->orIf(AccessResult::allowedIfHasPermission($account, 'edit all neo_site_settings'))
        ->orIf(AccessResult::allowedIfHasPermission($account, 'edit ' . $entity->id() . ' site settings'));
    }
    return parent::checkAccess($entity, $operation, $account);
  }

}
