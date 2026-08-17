<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_site_settings\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_site_settings\Entity\SiteSettingsType;
use PHPUnit\Framework\Attributes\Group;

/**
 * Deleting a settings type takes its content with it, and label() survives.
 *
 * Core does not remove content rows when a bundle is deleted, so this module
 * has to. It used to delete at most one row, chosen by id through a loader that
 * dispatches SiteSettingsPreloadEvent — so a subscriber that remapped the id
 * left rows behind whose bundle then pointed at a type that no longer existed.
 *
 * Such a row is not merely stale data. label() dereferences the bundle entity,
 * so one orphan was enough to fatal any page that listed settings entities or
 * saved one.
 *
 * @see \Drupal\neo_site_settings\Entity\SiteSettingsType::preDelete()
 * @see \Drupal\neo_site_settings\Entity\SiteSettings::label()
 */
#[Group('neo_site_settings')]
final class SiteSettingsTypeDeleteTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'link',
    'telephone',
    'neo_site_settings',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('neo_site_settings');

    foreach (['general', 'social'] as $id) {
      SiteSettingsType::create([
        'id' => $id,
        'label' => ucfirst($id),
        'aggregate' => TRUE,
      ])->save();
    }
  }

  /**
   * Deleting a type removes its settings row.
   */
  public function testDeletingTypeRemovesItsRow(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_site_settings');
    $storage->loadOrCreateByType('general')->save();
    $storage->loadOrCreateByType('social')->save();

    SiteSettingsType::load('general')->delete();
    $storage->resetCache();

    $this->assertNull($storage->loadByType('general'));
    $this->assertNotNull($storage->loadByType('social'), 'Other bundles are untouched.');
  }

  /**
   * Every row in the bundle goes, not just the one whose id matches.
   *
   * Id and bundle are independent columns. A row whose id differs from its
   * bundle is exactly what a SiteSettingsPreloadEvent subscriber produces, and
   * selecting by id used to leave it behind.
   */
  public function testEveryRowInTheBundleIsRemoved(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_site_settings');
    $storage->create(['id' => 'general', 'bundle' => 'general'])->save();
    $storage->create(['id' => 'general_variant', 'bundle' => 'general'])->save();

    $this->assertCount(2, $storage->loadMultiple());

    SiteSettingsType::load('general')->delete();
    $storage->resetCache();

    $this->assertSame(
      [],
      $storage->loadMultiple(),
      'A row whose id differs from its bundle is deleted with the bundle.',
    );
  }

  /**
   * Deleting a type with no saved rows is harmless.
   */
  public function testDeletingTypeWithNoRowsIsHarmless(): void {
    SiteSettingsType::load('social')->delete();

    $this->assertNull(SiteSettingsType::load('social'));
  }

  /**
   * The label comes from the bundle, which is what the UI shows.
   */
  public function testLabelComesFromTheBundle(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_site_settings');
    $entity = $storage->loadOrCreateByType('general');

    $this->assertSame('General', $entity->label());
  }

  /**
   * A row whose bundle is gone falls back to its id instead of fataling.
   *
   * The teardown above is what keeps such a row from existing, but nothing
   * stops one arriving through a partial config import or a hand-edited
   * database, and label() is called per row by the list builder.
   */
  public function testLabelFallsBackWhenTheBundleIsMissing(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_site_settings');
    $entity = $storage->create(['id' => 'orphan', 'bundle' => 'does_not_exist']);

    $this->assertSame('orphan', $entity->label());
  }

}
