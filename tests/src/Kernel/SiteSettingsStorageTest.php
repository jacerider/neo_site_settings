<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_site_settings\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_site_settings\Entity\SiteSettingsType;
use Drupal\neo_site_settings\SiteSettingsStorage;
use PHPUnit\Framework\Attributes\Group;

/**
 * The storage handler's loading contract.
 *
 * Everything in this module reaches a settings entity through one of two
 * methods, so their edges are worth pinning: loadByType() may legitimately find
 * nothing, while loadOrCreateByType() is defined never to. Four value providers
 * and two blocks used to carry an "if (!$entity)" guard against the second,
 * which could not fire and hid the fact that the entity they got back was
 * unsaved and therefore contributed no cache tags.
 *
 * hasNonAggregated() decides which of two entirely different pages
 * /admin/settings renders, and used to compare its count against 1 rather than
 * 0, so a site with exactly one standalone type reported that it had none.
 *
 * @see \Drupal\neo_site_settings\SiteSettingsStorage
 */
#[Group('neo_site_settings')]
final class SiteSettingsStorageTest extends KernelTestBase {

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
   * The settings storage handler under test.
   */
  private SiteSettingsStorage $storage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('neo_site_settings');

    $storage = $this->container->get('entity_type.manager')->getStorage('neo_site_settings');
    assert($storage instanceof SiteSettingsStorage);
    $this->storage = $storage;

    SiteSettingsType::create([
      'id' => 'general',
      'label' => 'General',
      'aggregate' => TRUE,
    ])->save();
  }

  /**
   * Loading by type reports honestly that no row exists yet.
   */
  public function testLoadByTypeReturnsNullWhenNothingIsSaved(): void {
    $this->assertNull($this->storage->loadByType('general'));
  }

  /**
   * Load-or-create always returns an entity, never NULL.
   *
   * This is the guarantee the "if (!$entity) return $value;" guards in the
   * value providers were written against, and it is why those guards were dead.
   */
  public function testLoadOrCreateByTypeNeverReturnsNull(): void {
    $entity = $this->storage->loadOrCreateByType('general');

    $this->assertNotNull($entity);
    $this->assertTrue($entity->isNew());
    $this->assertSame('general', $entity->id());
    $this->assertSame('general', $entity->bundle());
  }

  /**
   * A synthesized entity carries no cache tags of its own.
   *
   * This is the reason the value providers register the bundle list tag rather
   * than the entity: depending on the entity here would register nothing at
   * all, cache the render permanently, and never invalidate it once the
   * settings form was first saved.
   */
  public function testUnsavedEntityContributesNoCacheTags(): void {
    $this->assertSame([], $this->storage->loadOrCreateByType('general')->getCacheTags());

    $saved = $this->storage->loadOrCreateByType('general');
    $saved->save();

    $this->assertSame(['neo_site_settings:general'], $saved->getCacheTags());
  }

  /**
   * Once saved, loadByType() returns the same row.
   */
  public function testLoadByTypeReturnsTheSavedRow(): void {
    $this->storage->loadOrCreateByType('general')->save();

    $loaded = $this->storage->loadByType('general');
    $this->assertNotNull($loaded);
    $this->assertFalse($loaded->isNew());
    $this->assertSame('general', $loaded->id());
  }

  /**
   * The non-aggregated check answers "at least one", not "more than one".
   */
  public function testHasNonAggregatedCountsFromOne(): void {
    // Only the aggregated type from setUp() exists.
    $this->assertFalse($this->storage->hasNonAggregated());

    SiteSettingsType::create([
      'id' => 'standalone',
      'label' => 'Standalone',
      'aggregate' => FALSE,
    ])->save();

    $this->assertTrue(
      $this->storage->hasNonAggregated(),
      'A single standalone type is enough for hasNonAggregated() to be TRUE.',
    );
  }

}
