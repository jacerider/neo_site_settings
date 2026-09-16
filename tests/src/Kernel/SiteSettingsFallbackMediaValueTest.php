<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_site_settings\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_site_settings\Entity\SiteSettingsType;
use PHPUnit\Framework\Attributes\Group;

/**
 * The `site_settings_fallback_media` provider's fallback-only contract.
 *
 * This provider is deliberately NOT authoritative: it supplies a
 * client-managed image only where a media prop resolved empty, which is the
 * common case of a queried listing whose individual entities left their image
 * field blank. Everything about it hinges on that "only":
 *
 * - It must never displace a real image. A provider that claimed
 *   unconditionally would replace genuine per-item artwork with one shared
 *   fallback, and the page would look intentional.
 * - It must claim when it does fire, because the whole point is to outrank the
 *   weight-1000 "default" provider that would otherwise seed the SDC example.
 *   Offering instead of claiming would let the placeholder win and look like
 *   the fallback was never configured.
 *
 * The weight (15) encodes both halves: after media (10) and entity_query (5)
 * so real content wins, before default (1000) so the fallback beats the
 * example.
 *
 * @see \Drupal\neo_site_settings\Plugin\ComponentValue\SiteSettingsFallbackMediaValue
 */
#[Group('neo_site_settings')]
final class SiteSettingsFallbackMediaValueTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'entity_test',
    'link',
    'telephone',
    'neo_settings',
    'neo_alchemist',
    'neo_site_settings',
    'neo_site_settings_test',
  ];

  /**
   * The fallback media entity.
   */
  private Media $media;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('neo_site_settings');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['image']);

    $mediaType = MediaType::create([
      'id' => 'image',
      'label' => 'Image',
      'source' => 'image',
    ]);
    $mediaType->save();
    $sourceField = $mediaType->getSource()->createSourceField($mediaType);
    $sourceField->getFieldStorageDefinition()->save();
    $sourceField->save();
    $mediaType->set('source_configuration', ['source_field' => $sourceField->getName()])->save();

    $this->container->get('file_system')
      ->copy(\Drupal::root() . '/core/tests/fixtures/files/image-test.png', 'public://nss-fallback.png');
    $file = File::create(['uri' => 'public://nss-fallback.png', 'status' => 1]);
    $file->save();
    $this->media = Media::create([
      'bundle' => 'image',
      'name' => 'Fallback image',
      $sourceField->getName() => ['target_id' => $file->id(), 'alt' => 'FALLBACK ALT'],
    ]);
    $this->media->save();

    SiteSettingsType::create([
      'id' => 'placeholders',
      'label' => 'Placeholders',
      'aggregate' => TRUE,
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_fallback',
      'entity_type' => 'neo_site_settings',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_fallback',
      'entity_type' => 'neo_site_settings',
      'bundle' => 'placeholders',
      'label' => 'Fallback',
    ])->save();

    // The list the fallback fills the gaps in: a settings-curated reference to
    // entities that each MAY carry their own image.
    FieldStorageConfig::create([
      'field_name' => 'field_items',
      'entity_type' => 'neo_site_settings',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'entity_test'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_items',
      'entity_type' => 'neo_site_settings',
      'bundle' => 'placeholders',
      'label' => 'Items',
      'settings' => [
        'handler' => 'default:entity_test',
        'handler_settings' => ['target_bundles' => ['entity_test' => 'entity_test']],
      ],
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_media',
      'entity_type' => 'entity_test',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_media',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Media',
    ])->save();
  }

  /**
   * Creates a media entity wrapping the shared test image.
   */
  private function createMedia(string $name, string $alt): Media {
    $sourceField = MediaType::load('image')->getSource()
      ->getSourceFieldDefinition(MediaType::load('image'))->getName();
    $file = File::create(['uri' => 'public://nss-fallback.png', 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'image',
      'name' => $name,
      $sourceField => ['target_id' => $file->id(), 'alt' => $alt],
    ]);
    $media->save();
    return $media;
  }

  /**
   * Saves the settings entity: the shared fallback, plus the curated list.
   *
   * @param bool $filled
   *   Whether the shared fallback media field points at anything.
   * @param bool ...$withOwnImage
   *   One target per flag; TRUE gives that target its own media.
   */
  private function settings(bool $filled, bool ...$withOwnImage): void {
    $targets = [];
    foreach ($withOwnImage as $index => $own) {
      $values = ['name' => 'ITEM' . $index];
      if ($own) {
        $values['field_media'] = $this->createMedia('Own image ' . $index, 'OWN ALT')->id();
      }
      $entity = EntityTest::create($values);
      $entity->save();
      $targets[] = $entity->id();
    }

    $storage = $this->container->get('entity_type.manager')->getStorage('neo_site_settings');
    $settings = $storage->loadOrCreateByType('placeholders');
    $settings->set('field_fallback', $filled ? $this->media->id() : NULL);
    $settings->set('field_items', $targets);
    $settings->save();
  }

  /**
   * Builds the probe component the way a site actually wires this.
   *
   * A curated list whose image child carries the fallback. The fallback is an
   * INLINE plugin on the child shape, not a provider on the prop — that is the
   * only place the picker offers it, and the only shape the config schema
   * accepts.
   */
  private function buildComponent(array $settings = []): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('nss_media_probe')) {
      Component::create([
        'id' => 'nss_media_probe',
        'label' => 'Probe',
        'description' => '',
        'component' => 'neo_site_settings_test:nss_media_probe',
        'status' => TRUE,
      ])->save();
    }
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.nss_media_probe')
      ->set('settings.props.items.plugins.items', [
        'site_settings_reference' => [
          'id' => 'site_settings_reference',
          'settings' => [
            'bundle' => 'placeholders',
            'reference' => 'field_items:entity',
            'shape_fields' => [
              'title' => ['field' => 'name'],
              'image' => [
                'field' => 'field_media',
                'plugins' => [
                  'site_settings_fallback_media' => [
                    'plugin_id' => 'site_settings_fallback_media',
                    'status' => TRUE,
                    'settings' => $settings + [
                      'bundle' => 'placeholders',
                      'field' => 'field_fallback',
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
      ])
      ->save();
    $storage->resetCache(['nss_media_probe']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('nss_media_probe');
    return $component;
  }

  /**
   * The resolved image src per item, in order.
   *
   * @return string[]
   *   The srcs.
   */
  private function itemSrcs(Component $component): array {
    $items = $component->getPropValues()['items'] ?? [];
    return array_map(
      static fn (array $item) => (string) ($item['image']['src'] ?? ''),
      $items,
    );
  }

  /**
   * An item with no image of its own is filled from Site Settings.
   */
  public function testItemWithoutOwnImageGetsTheFallback(): void {
    $this->settings(TRUE, FALSE);

    $srcs = $this->itemSrcs($this->buildComponent());

    $this->assertCount(1, $srcs);
    $this->assertStringContainsString('nss-fallback.png', $srcs[0]);
  }

  /**
   * An item WITH its own image keeps it.
   *
   * The half that makes this a fallback rather than an override: a provider
   * that claimed unconditionally would replace genuine per-item artwork with
   * one shared image, and the result would look deliberate.
   */
  public function testItemWithOwnImageKeepsIt(): void {
    $this->settings(TRUE, TRUE);

    $alt = $this->buildComponent()->getPropValues()['items'][0]['image']['alt'] ?? '';

    $this->assertSame('OWN ALT', (string) $alt, 'Real content outranks the fallback.');
  }

  /**
   * Both cases resolve side by side in one list.
   *
   * The real shape of the problem: a listing where SOME entities left the
   * image blank. Per-item resolution is what the weight ordering buys.
   */
  public function testFallbackFillsOnlyTheGaps(): void {
    $this->settings(TRUE, TRUE, FALSE);

    $alts = array_map(
      static fn (array $item) => (string) ($item['image']['alt'] ?? ''),
      $this->buildComponent()->getPropValues()['items'] ?? [],
    );

    $this->assertSame(['OWN ALT', 'FALLBACK ALT'], $alts);
  }

  /**
   * An empty Site Settings media field supplies nothing.
   */
  public function testEmptySettingsFieldSuppliesNothing(): void {
    $this->settings(FALSE, FALSE);

    $this->assertStringNotContainsString(
      'nss-fallback.png',
      $this->itemSrcs($this->buildComponent())[0] ?? '',
      'Nothing to fall back to means nothing is supplied.',
    );
  }

  /**
   * An unconfigured fallback supplies nothing.
   */
  public function testUnconfiguredFallbackSuppliesNothing(): void {
    $this->settings(TRUE, FALSE);

    $this->assertStringNotContainsString(
      'nss-fallback.png',
      $this->itemSrcs($this->buildComponent(['bundle' => '', 'field' => '']))[0] ?? '',
    );
  }

  /**
   * The settings entity is a cache dependency wherever the fallback is wired.
   *
   * Scoped deliberately to the settings tags. The provider also calls
   * addCacheableDependency() with the fallback MEDIA entity, and that tag does
   * NOT reach the component: metadata added to a child shape — this plugin is
   * inline on the image child — does not bubble the way the root prop shape's
   * does, which is why neo_site_settings_list:placeholders is here and
   * media:N is not.
   *
   * The practical hole is narrow but real: editing the fallback media itself
   * (swapping its file, changing its alt) touches neither the settings entity
   * nor the listed entities, so a page cached with the fallback keeps serving
   * the old image. Asserting media:N here would fail; asserting its absence
   * would freeze the gap as intended behaviour. So this pins the guarantee
   * that does hold and leaves the gap documented rather than encoded.
   */
  public function testSettingsEntityIsCacheDependency(): void {
    $this->settings(TRUE, FALSE);

    $component = $this->buildComponent();
    $component->getPropValues();
    $tags = $component->getCacheableMetadata()->getCacheTags();

    $this->assertContains('neo_site_settings_list:placeholders', $tags);
    $this->assertContains(
      'neo_site_settings:placeholders',
      $tags,
      'Repointing the fallback field invalidates whatever rendered it.',
    );
  }

}
