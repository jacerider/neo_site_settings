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
 * The `site_settings` provider's sourcing contract.
 *
 * This provider yields the settings entity ITSELF as the single row, which is
 * what makes it an object-prop provider and never a list one. The distinction
 * matters because the two Site Settings sourcing providers look
 * interchangeable in the picker and are not: `site_settings` fills one object
 * from the settings entity's own fields, while `site_settings_reference`
 * iterates a reference ON it. Binding a list to this one would quietly render
 * a single item forever.
 *
 * @see \Drupal\neo_site_settings\Plugin\ComponentValue\SiteSettingsValue
 * @see \Drupal\Tests\neo_site_settings\Kernel\SiteSettingsReferenceValueTest
 */
#[Group('neo_site_settings')]
final class SiteSettingsValueTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'link',
    'telephone',
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
      'id' => 'general',
      'label' => 'General',
      'aggregate' => TRUE,
    ])->save();

    foreach (['field_title', 'field_note'] as $fieldName) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'neo_site_settings',
        'type' => 'string',
      ])->save();
      FieldConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'neo_site_settings',
        'bundle' => 'general',
        'label' => $fieldName,
      ])->save();
    }
  }

  /**
   * Saves the settings entity with the given field values.
   */
  private function settings(array $values): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_site_settings');
    $settings = $storage->loadOrCreateByType('general');
    foreach ($values as $name => $value) {
      $settings->set($name, $value);
    }
    $settings->save();
  }

  /**
   * Builds the probe component with the provider bound to `box`.
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
      ->set('settings.props.box.plugins.box', [
        'site_settings' => [
          'id' => 'site_settings',
          'settings' => $settings + [
            'bundle' => 'general',
            'shape_fields' => [
              'title' => ['field' => 'field_title'],
              'note' => ['field' => 'field_note'],
            ],
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
   * The settings entity's own fields fill the object's children.
   */
  public function testSettingsFieldsFillTheObject(): void {
    $this->settings(['field_title' => 'REAL TITLE', 'field_note' => 'REAL NOTE']);

    $box = $this->buildComponent()->getPropValues()['box'] ?? [];

    $this->assertSame('REAL TITLE', (string) ($box['title'] ?? ''));
    $this->assertSame('REAL NOTE', (string) ($box['note'] ?? ''));
  }

  /**
   * The bundle list cache tag reaches the component.
   */
  public function testSettingsListCacheTagIsAttached(): void {
    $this->settings(['field_title' => 'REAL TITLE']);

    $component = $this->buildComponent();
    $component->getPropValues();

    $this->assertContains(
      'neo_site_settings_list:general',
      $component->getCacheableMetadata()->getCacheTags(),
      'Editing the settings entity invalidates whatever rendered its fields.',
    );
  }

  /**
   * An unsaved settings entity still contributes the bundle list cache tag.
   *
   * The trap the storage handler's own test documents from the other side: a
   * never-saved entity is new and carries no cache tag at all, so a provider
   * that reached for the entity tag would cache "empty" past the moment the
   * client first saves.
   */
  public function testUnsavedSettingsStillAttachesListCacheTag(): void {
    $component = $this->buildComponent();
    $component->getPropValues();

    $this->assertContains(
      'neo_site_settings_list:general',
      $component->getCacheableMetadata()->getCacheTags(),
    );
  }

  /**
   * The provider is offered for objects and withheld from lists.
   *
   * The guard rail against the confusion in the class docblock: were this
   * offered for an array it would render exactly one item, which looks like a
   * content problem rather than a wiring one.
   */
  public function testOfferedForObjectsButNotLists(): void {
    $component = $this->buildComponent();
    $manager = $this->container->get('plugin.manager.neo_component_value');

    $this->assertArrayHasKey(
      'site_settings',
      $manager->getFilteredDefinitionsFromShape($component->getPropShape('box')),
    );
    $this->assertArrayNotHasKey(
      'site_settings',
      $manager->getFilteredDefinitionsFromShape($component->getPropShape('items')),
      'A list must reach for site_settings_reference instead.',
    );
  }

}
