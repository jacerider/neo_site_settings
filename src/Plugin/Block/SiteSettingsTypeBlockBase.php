<?php

namespace Drupal\neo_site_settings\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\neo_site_settings\Entity\SiteSettings;

/**
 * Provides a base for blocks that render from a settings entity.
 *
 * Deprecated, and a candidate for removal in the next major version. Nothing in
 * this package or any known consumer extends it, it is not itself a block
 * plugin, and its settings type is hardcoded to "general" with no way to
 * override it. It is kept only because removing a published class is a breaking
 * change for consumers this package cannot see.
 *
 * Prefer SiteSettingsBlock, which is configurable, or read the entity directly
 * through SiteSettingsStorage::loadByType().
 */
class SiteSettingsTypeBlockBase extends BlockBase implements BlockPluginInterface {

  /**
   * The settings type to use.
   *
   * @var string
   */
  protected $siteSettingsTypeId = 'general';

  /**
   * The site settings entity.
   *
   * @var \Drupal\neo_site_settings\SiteSettingsInterface
   */
  protected $siteSettings;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
    $this->siteSettings = SiteSettings::load($this->siteSettingsTypeId);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return $this->siteSettings->getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getMachineNameSuggestion() {
    return 'neo_site_settings_' . $this->siteSettingsTypeId;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $dependencies = parent::calculateDependencies();
    $dependencies['config'][] = 'neo_site_settings.neo_site_settings_type.' . $this->siteSettingsTypeId;
    return $dependencies;
  }

}
