<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_site_settings\Kernel;

use Drupal\entity_test\Entity\EntityTestRevPub;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_site_settings\Entity\SiteSettingsType;
use Drupal\neo_site_settings\Plugin\ComponentValue\SiteSettingsReferenceValue;
use PHPUnit\Framework\Attributes\Group;

/**
 * The `site_settings_reference` provider's sourcing contract.
 *
 * The provider renders exactly the entities a site builder picked in a
 * reference field on a Site Settings entity. Three things decide whether it is
 * trustworthy, and each is invisible when it goes wrong:
 *
 * - Order. The point of curating a reference field is that delta order IS the
 *   editorial decision. A provider that reordered — or that silently sorted —
 *   would still render plausible content.
 * - Emptiness. The props this fills carry loud SDC examples so the editor
 *   preview is legible. An unpicked reference must therefore render as
 *   NOTHING; falling through to those examples would put placeholder cards on
 *   a production page, which looks like real content to everyone but the
 *   person who wrote the fixture. That is what MODE_BLOCK buys, and why the
 *   assertions below check for the absence of `EXAMPLE_` rather than only for
 *   the presence of real values.
 * - Cacheability. Settings entities are edited rarely and read on every page,
 *   so a missing cache tag yields a menu that never updates, with no error
 *   anywhere. The unsaved case matters most: a never-saved settings entity is
 *   new and contributes no cache tag of its own, so the provider has to reach
 *   for the bundle LIST tag instead.
 *
 * Runs against real storage and a real reference field rather than mocks, so
 * delta order and the published policy come from the same code paths a site
 * uses.
 *
 * @see \Drupal\neo_site_settings\Plugin\ComponentValue\SiteSettingsReferenceValue
 */
#[Group('neo_site_settings')]
final class SiteSettingsReferenceValueTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'link',
    'telephone',
    'entity_test',
    'neo_settings',
    'neo_alchemist',
    'neo_site_settings',
    'neo_site_settings_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // Publishable on purpose: the published policy is one of the behaviours
    // under test, and an unpublishable target would let it pass vacuously.
    $this->installEntitySchema('entity_test_revpub');
    $this->installEntitySchema('neo_site_settings');

    SiteSettingsType::create([
      'id' => 'menu',
      'label' => 'Menu',
      'aggregate' => TRUE,
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_items',
      'entity_type' => 'neo_site_settings',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'entity_test_revpub'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_items',
      'entity_type' => 'neo_site_settings',
      'bundle' => 'menu',
      'label' => 'Items',
      'settings' => [
        'handler' => 'default:entity_test_revpub',
        'handler_settings' => ['target_bundles' => ['entity_test_revpub' => 'entity_test_revpub']],
      ],
    ])->save();
  }

  /**
   * Creates a saved target entity.
   *
   * @param string $name
   *   The name, which the assertions read back as the item title.
   * @param bool $published
   *   Whether the entity is published.
   */
  private function target(string $name, bool $published = TRUE): EntityTestRevPub {
    $entity = EntityTestRevPub::create(['name' => $name]);
    $published ? $entity->setPublished() : $entity->setUnpublished();
    $entity->save();
    return $entity;
  }

  /**
   * Saves the settings entity referencing the given targets, in order.
   */
  private function reference(EntityTestRevPub ...$targets): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_site_settings');
    $settings = $storage->loadOrCreateByType('menu');
    $settings->set('field_items', array_map(static fn ($t) => $t->id(), $targets));
    $settings->save();
  }

  /**
   * Builds the probe component with the provider bound to `items`.
   *
   * @param array $settings
   *   Provider settings merged over the defaults, so each test states only the
   *   keys it cares about.
   */
  private function buildComponent(array $settings = []): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('nss_probe')) {
      Component::create([
        'id' => 'nss_probe',
        'label' => 'Probe',
        'description' => '',
        'component' => 'neo_site_settings_test:nss_probe',
        'status' => TRUE,
      ])->save();
    }
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.nss_probe')
      ->set('settings.props.items.plugins.items', [
        'site_settings_reference' => [
          'id' => 'site_settings_reference',
          'settings' => $settings + [
            'bundle' => 'menu',
            'reference' => 'field_items:entity',
            'shape_fields' => ['title' => ['field' => 'name']],
          ],
        ],
      ])
      ->save();
    $storage->resetCache(['nss_probe']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('nss_probe');
    return $component;
  }

  /**
   * The resolved item titles, in render order.
   *
   * @return string[]
   *   The titles.
   */
  private function itemTitles(Component $component): array {
    $values = $component->getPropValues();
    return array_map(
      static fn (array $item) => (string) ($item['title'] ?? ''),
      $values['items'] ?? [],
    );
  }

  /**
   * The referenced entities become the items, in the order they were picked.
   *
   * Reversed relative to creation so a provider that happened to sort by id —
   * the most plausible accidental ordering — fails rather than passes.
   */
  public function testReferencedEntitiesBecomeItemsInOrder(): void {
    $first = $this->target('ALPHA');
    $second = $this->target('BETA');
    $third = $this->target('GAMMA');
    $this->reference($third, $first, $second);

    $this->assertSame(
      ['GAMMA', 'ALPHA', 'BETA'],
      $this->itemTitles($this->buildComponent()),
      'Delta order is the editorial decision and survives to the rendered items.',
    );
  }

  /**
   * An empty reference renders nothing rather than the SDC examples.
   */
  public function testEmptyReferenceRendersNothing(): void {
    $this->reference();

    $values = $this->buildComponent()->getPropValues();

    $this->assertArrayNotHasKey('items', $values, 'An unpicked reference resolves to no items at all.');
    // Scoped to the item examples: `heading` and `box` are deliberately left
    // unbound by this fixture, so their own examples standing is correct.
    $this->assertStringNotContainsString(
      'EXAMPLE_ITEM_',
      var_export($values, TRUE),
      'Blocking on empty is what keeps placeholder cards off a production page.',
    );
  }

  /**
   * A never-saved settings entity renders nothing rather than the examples.
   *
   * The settings entity is created on demand, so "the client has not filled
   * this in yet" is the state a fresh environment is actually in — and it must
   * look the same as an emptied reference, not like seeded content.
   */
  public function testUnsavedSettingsRendersNothing(): void {
    $values = $this->buildComponent()->getPropValues();

    $this->assertArrayNotHasKey('items', $values, 'No saved settings entity means no items.');
    $this->assertStringNotContainsString('EXAMPLE_ITEM_', var_export($values, TRUE));
  }

  /**
   * An unconfigured provider passes the value through untouched.
   *
   * Distinct from resolving empty: a half-configured provider has not made an
   * editorial statement, so it must not claim the prop. The examples surviving
   * is the visible proof it stayed out of the way.
   */
  public function testUnconfiguredProviderLeavesExamplesIntact(): void {
    $this->reference($this->target('ALPHA'));

    $titles = $this->itemTitles($this->buildComponent(['bundle' => '', 'reference' => '']));

    $this->assertSame(
      ['EXAMPLE_ITEM_TITLE_0', 'EXAMPLE_ITEM_TITLE_1'],
      $titles,
      'With nothing configured the provider cannot act, so the examples stand.',
    );
  }

  /**
   * The published policy drops unpublished targets.
   */
  public function testUnpublishedTargetIsFilteredOut(): void {
    $this->reference(
      $this->target('VISIBLE'),
      $this->target('HIDDEN', FALSE),
    );

    $this->assertSame(
      ['VISIBLE'],
      $this->itemTitles($this->buildComponent(['shape_published' => TRUE])),
      'An unpublished pick is not rendered to an anonymous visitor.',
    );
  }

  /**
   * Turning the published policy off keeps unpublished targets.
   *
   * The mirror of the test above: without it, a provider that dropped
   * unpublished entities unconditionally would look correct.
   */
  public function testUnpublishedTargetIsKeptWhenPolicyIsOff(): void {
    $this->reference(
      $this->target('VISIBLE'),
      $this->target('HIDDEN', FALSE),
    );

    $this->assertSame(
      ['VISIBLE', 'HIDDEN'],
      $this->itemTitles($this->buildComponent(['shape_published' => FALSE])),
      'The policy is a setting, not a hardcoded filter.',
    );
  }

  /**
   * The settings bundle list cache tag reaches the component.
   */
  public function testSettingsListCacheTagIsAttached(): void {
    $this->reference($this->target('ALPHA'));

    $component = $this->buildComponent();
    $component->getPropValues();

    $this->assertContains(
      'neo_site_settings_list:menu',
      $component->getCacheableMetadata()->getCacheTags(),
      'Editing the settings entity invalidates whatever rendered its picks.',
    );
  }

  /**
   * The list cache tag is attached even when nothing is picked yet.
   *
   * The half that is easy to miss: a menu that currently shows nothing must
   * still invalidate when the client makes their first pick, or it caches
   * "nothing here" indefinitely.
   */
  public function testSettingsListCacheTagIsAttachedWhenUnsaved(): void {
    $component = $this->buildComponent();
    $component->getPropValues();

    $this->assertContains(
      'neo_site_settings_list:menu',
      $component->getCacheableMetadata()->getCacheTags(),
      'An unsaved settings entity carries no tag of its own, so the bundle list tag stands in.',
    );
  }

  /**
   * Only shapes whose children can receive a distribution are offered it.
   */
  public function testApplicabilityIsLimitedToDistributableShapes(): void {
    $component = $this->buildComponent();

    $this->assertTrue(
      SiteSettingsReferenceValue::isApplicable($component->getPropShape('items')),
      'An array prop is the ordinary target.',
    );
    $this->assertFalse(
      SiteSettingsReferenceValue::isApplicable($component->getPropShape('heading')),
      'A scalar string has no children to distribute rows onto.',
    );
  }

  /**
   * The provider is offered without the host entity entity_reference needs.
   *
   * The reason this plugin exists: the probe component has no target entity
   * type, which is every component placed in a menu region, and that is
   * exactly the case entity_reference's own isApplicable() rejects.
   */
  public function testOfferedWhenComponentHasNoTargetEntity(): void {
    $component = $this->buildComponent();
    $shape = $component->getPropShape('items');
    $this->assertEmpty($shape->getTargetEntityType(), 'The fixture is unbound, like a menu-region component.');

    $available = $this->container->get('plugin.manager.neo_component_value')
      ->getFilteredDefinitionsFromShape($shape);

    $this->assertArrayHasKey('site_settings_reference', $available);
    $this->assertArrayNotHasKey(
      'entity_reference',
      $available,
      'entity_reference needs a host entity, which is the gap this provider fills.',
    );
  }

  /**
   * The shape constants the attribute declares are the ones being relied on.
   */
  public function testDeclaredPropTypes(): void {
    $definition = $this->container->get('plugin.manager.neo_component_value')
      ->getDefinition('site_settings_reference');

    $this->assertSame(
      [ComponentShapePluginInterface::ARRAY, ComponentShapePluginInterface::OBJECT],
      $definition['prop_types'],
    );
  }

}
