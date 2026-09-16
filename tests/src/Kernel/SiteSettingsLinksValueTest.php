<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_site_settings\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_site_settings\Entity\SiteSettingsType;
use PHPUnit\Framework\Attributes\Group;

/**
 * The `site_settings_links` provider's menu-building contract.
 *
 * Every filled-in link field on a settings entity becomes one menu item. The
 * presentation is NOT read from the field values but from the bundle's view
 * display, so that "Manage display" stays the single place a site builder
 * arranges these links — the ordering especially, which comes from component
 * weight rather than from field name or creation order.
 *
 * The cases below are the ones where a plausible-looking implementation
 * silently differs: an empty field must vanish rather than render a bare
 * bullet, and the neo_link formatter's "no target" option stores 0, which is
 * not a valid HTML target and must not be emitted as one.
 *
 * @see \Drupal\neo_site_settings\Plugin\ComponentValue\SiteSettingsLinksValue
 */
#[Group('neo_site_settings')]
final class SiteSettingsLinksValueTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'link',
    'telephone',
    // The provider reads the neo_link formatter's icon/title/target settings,
    // so the real formatter has to be present: core's own link formatter has
    // no `title` or `icon` setting and EntityViewDisplay silently drops what
    // the formatter does not declare. neo pulls path_alias and linkit in
    // through neo.linkit_resolver.
    'path_alias',
    'linkit',
    'neo',
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
    $this->installEntitySchema('neo_site_settings');

    SiteSettingsType::create([
      'id' => 'social',
      'label' => 'Social',
      'aggregate' => TRUE,
    ])->save();

    foreach (['field_facebook', 'field_instagram', 'field_unused'] as $fieldName) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'neo_site_settings',
        'type' => 'link',
      ])->save();
      FieldConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'neo_site_settings',
        'bundle' => 'social',
        'label' => ucfirst(substr($fieldName, 6)),
      ])->save();
    }
  }

  /**
   * Saves the settings entity with the given link field values.
   */
  private function settings(array $values): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_site_settings');
    $settings = $storage->loadOrCreateByType('social');
    foreach ($values as $name => $value) {
      $settings->set($name, $value);
    }
    $settings->save();
  }

  /**
   * Places link fields on the view display with the given weights/settings.
   *
   * @param array $components
   *   Field name => component definition overrides.
   */
  private function viewDisplay(array $components): void {
    $display = $this->container->get('entity_display.repository')
      ->getViewDisplay('neo_site_settings', 'social');
    foreach ($components as $fieldName => $component) {
      $display->setComponent($fieldName, $component + [
        'type' => 'neo_link',
        'region' => 'content',
        'settings' => [],
        'third_party_settings' => [],
      ]);
    }
    $display->save();
  }

  /**
   * Builds the probe component with the provider bound to `links`.
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
      ->set('settings.props.links.plugins.links', [
        'site_settings_links' => [
          'id' => 'site_settings_links',
          'settings' => $settings + ['bundle' => 'social'],
        ],
      ])
      ->save();
    $storage->resetCache(['nss_probe']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('nss_probe');
    return $component;
  }

  /**
   * The resolved menu items.
   *
   * @return array[]
   *   The items.
   */
  private function links(Component $component): array {
    return $component->getPropValues()['links'] ?? [];
  }

  /**
   * Filled link fields become menu items and empty ones are skipped.
   */
  public function testFilledLinksBecomeItemsAndEmptyOnesAreSkipped(): void {
    $this->settings([
      'field_facebook' => ['uri' => 'https://example.com/fb'],
      'field_instagram' => ['uri' => 'https://example.com/ig'],
    ]);
    $this->viewDisplay([
      'field_facebook' => ['weight' => 0],
      'field_instagram' => ['weight' => 1],
      'field_unused' => ['weight' => 2],
    ]);

    $links = $this->links($this->buildComponent());

    $this->assertCount(2, $links, 'The unfilled field contributes no bullet.');
    $this->assertSame(
      ['https://example.com/fb', 'https://example.com/ig'],
      array_map(static fn (array $item) => $item['url']['uri'], $links),
    );
  }

  /**
   * Item order follows the view display weight, not the field name.
   *
   * Weighted against alphabetical order on purpose: sorting by field name is
   * the most plausible accidental implementation, and it would agree with the
   * expected result under any other arrangement.
   */
  public function testOrderFollowsViewDisplayWeight(): void {
    $this->settings([
      'field_facebook' => ['uri' => 'https://example.com/fb'],
      'field_instagram' => ['uri' => 'https://example.com/ig'],
    ]);
    $this->viewDisplay([
      'field_facebook' => ['weight' => 10],
      'field_instagram' => ['weight' => -10],
    ]);

    $this->assertSame(
      ['https://example.com/ig', 'https://example.com/fb'],
      array_map(
        static fn (array $item) => $item['url']['uri'],
        $this->links($this->buildComponent()),
      ),
      'Manage display is where a site builder arranges these links.',
    );
  }

  /**
   * The formatter title wins over the link's own title and the field label.
   */
  public function testFormatterTitleWinsOverLinkTitle(): void {
    $this->settings([
      'field_facebook' => ['uri' => 'https://example.com/fb', 'title' => 'LINK TITLE'],
    ]);
    $this->viewDisplay([
      'field_facebook' => ['weight' => 0, 'settings' => ['title' => 'FORMATTER TITLE']],
    ]);

    $this->assertSame('FORMATTER TITLE', $this->links($this->buildComponent())[0]['title']);
  }

  /**
   * Without a formatter title the link's own title is used.
   */
  public function testLinkTitleUsedWhenFormatterTitleIsUnset(): void {
    $this->settings([
      'field_facebook' => ['uri' => 'https://example.com/fb', 'title' => 'LINK TITLE'],
    ]);
    $this->viewDisplay(['field_facebook' => ['weight' => 0]]);

    $this->assertSame('LINK TITLE', $this->links($this->buildComponent())[0]['title']);
  }

  /**
   * With neither title set the field label is the fallback.
   */
  public function testFieldLabelIsTheLastResortTitle(): void {
    $this->settings(['field_facebook' => ['uri' => 'https://example.com/fb']]);
    $this->viewDisplay(['field_facebook' => ['weight' => 0]]);

    $this->assertSame('Facebook', $this->links($this->buildComponent())[0]['title']);
  }

  /**
   * The formatter's "no target" option is not emitted as a target.
   *
   * Neo_link stores that choice as 0, which is not one of the two values an
   * HTML target may take; passing it through would put target="0" on the tag.
   *
   * The provider's job is to decline to set it — the url shape then supplies
   * its own '_self' default, which is why this asserts the absence of the
   * broken value rather than the absence of the key.
   */
  public function testNoTargetOptionIsNotEmitted(): void {
    $this->settings(['field_facebook' => ['uri' => 'https://example.com/fb']]);
    $this->viewDisplay([
      'field_facebook' => ['weight' => 0, 'settings' => ['target' => 0]],
    ]);

    $url = $this->links($this->buildComponent())[0]['url'];

    $this->assertNotSame('0', (string) ($url['target'] ?? ''), 'target="0" is not a link target.');
    $this->assertArrayNotHasKey(
      'target',
      $url['options']['attributes'] ?? [],
      'Nothing was merged into the link attributes either.',
    );
  }

  /**
   * The formatter's icon reaches the menu item.
   */
  public function testFormatterIconReachesTheItem(): void {
    $this->settings(['field_facebook' => ['uri' => 'https://example.com/fb']]);
    $this->viewDisplay([
      'field_facebook' => ['weight' => 0, 'settings' => ['icon' => 'brand-facebook']],
    ]);

    $this->assertSame('brand-facebook', $this->links($this->buildComponent())[0]['icon']);
  }

  /**
   * A real target reaches the url's own target key.
   *
   * Only that key: the provider ALSO merges the target into
   * `options.attributes`, but the url shape does not carry `options` through,
   * so what arrives here is an empty array no matter what was merged. This
   * pins the half that works rather than the half that is currently inert —
   * asserting the attributes would encode a behaviour the shape does not
   * deliver, and asserting they are empty would freeze what looks like an
   * oversight in the provider.
   */
  public function testTargetReachesTheUrl(): void {
    $this->settings(['field_facebook' => ['uri' => 'https://example.com/fb']]);
    $this->viewDisplay([
      'field_facebook' => ['weight' => 0, 'settings' => ['target' => '_blank']],
    ]);

    $this->assertSame('_blank', $this->links($this->buildComponent())[0]['url']['target']);
  }

  /**
   * The bundle list cache tag reaches the component.
   */
  public function testSettingsListCacheTagIsAttached(): void {
    $this->settings(['field_facebook' => ['uri' => 'https://example.com/fb']]);
    $this->viewDisplay(['field_facebook' => ['weight' => 0]]);

    $component = $this->buildComponent();
    $component->getPropValues();

    $this->assertContains(
      'neo_site_settings_list:social',
      $component->getCacheableMetadata()->getCacheTags(),
    );
  }

}
