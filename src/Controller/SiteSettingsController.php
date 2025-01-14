<?php

namespace Drupal\neo_site_settings\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_site_settings\SiteSettingsTypeInterface;

/**
 * The site settings controller.
 */
class SiteSettingsController extends ControllerBase {

  /**
   * Collection list for config page.
   *
   * @return array
   *   An array suitable for rendering.
   */
  public function collection() {
    /** @var \Drupal\neo_site_settings\SiteSettingsStorage $storage */
    $storage = $this->entityTypeManager()->getStorage('neo_site_settings');
    if (!$storage->hasNonAggregated()) {
      return $this->formBuilder()->getForm('\Drupal\neo_site_settings\Form\SiteSettingsGeneralForm');
    }
    return $this->entityTypeManager()->getListBuilder('neo_site_settings')->render();
  }

  /**
   * Create/edit form for a config page.
   *
   * @return array
   *   An array suitable for rendering.
   */
  public function form(SiteSettingsTypeInterface $neo_site_settings_type) {
    /** @var \Drupal\neo_site_settings\SiteSettingsStorage $storage */
    $storage = $this->entityTypeManager()->getStorage('neo_site_settings');
    $neo_site_settings = $storage->loadOrCreateByType($neo_site_settings_type->id());
    return $neo_site_settings ? $this->entityFormBuilder()->getForm($neo_site_settings) : [];
  }

  /**
   * Create/edit form for a config page title.
   *
   * @return string
   *   Return Hello string.
   */
  public function formTitle(SiteSettingsTypeInterface $neo_site_settings_type) {
    return $this->t('@label Settings', [
      '@label' => $neo_site_settings_type->label(),
    ]);
  }

}
