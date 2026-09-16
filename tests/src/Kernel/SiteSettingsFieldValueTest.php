<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_site_settings\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;
use Drupal\neo_site_settings\Entity\SiteSettingsType;
use PHPUnit\Framework\Attributes\Group;

/**
 * The `site_settings_field` provider's single-value contract.
 *
 * The generic counterpart to the "Entity Field" provider: it reads one field
 * off a settings entity chosen by bundle rather than off the host entity. Two
 * things are worth pinning.
 *
 * An empty field must be returned AS empty rather than swallowed. The provider
 * deliberately returns what it produced either way, so the pipeline — not the
 * plugin — decides precedence and a lower-weighted provider can still win.
 * Returning the threaded value on empty instead would claim the SDC example as
 * though it were real content, which is the bug this pins.
 *
 * And it must stay off object props: it assigns a scalar, so an object binding
 * would produce a value the shape cannot distribute.
 *
 * @see \Drupal\neo_site_settings\Plugin\ComponentValue\SiteSettingsFieldValue
 */
#[Group('neo_site_settings')]
final class SiteSettingsFieldValueTest extends KernelTestBase {

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

    FieldStorageConfig::create([
      'field_name' => 'field_title',
      'entity_type' => 'neo_site_settings',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_title',
      'entity_type' => 'neo_site_settings',
      'bundle' => 'general',
      'label' => 'Title',
    ])->save();
  }

  /**
   * Saves the settings entity with the given title.
   */
  private function settings(?string $title): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_site_settings');
    $settings = $storage->loadOrCreateByType('general');
    $settings->set('field_title', $title);
    $settings->save();
  }

  /**
   * Builds the probe component with the provider bound to `heading`.
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
      ->set('settings.props.heading.plugins.heading', [
        'site_settings_field' => [
          'id' => 'site_settings_field',
          'settings' => $settings + [
            'bundle' => 'general',
            'field' => 'field_title:value',
            'render' => FALSE,
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
   * The resolved heading.
   */
  private function heading(Component $component): string {
    return (string) ($component->getPropValues()['heading'] ?? '');
  }

  /**
   * A populated field becomes the prop value.
   */
  public function testPopulatedFieldFillsTheProp(): void {
    $this->settings('REAL HEADING');

    $this->assertSame('REAL HEADING', $this->heading($this->buildComponent()));
  }

  /**
   * An empty field falls through rather than claiming the prop.
   *
   * This provider's default mode is stop_when_found, so producing empty is NOT
   * the same as claiming empty: the value is offered, declines to claim, and
   * whatever sits below — a lower-weighted provider, or the SDC example — wins.
   *
   * Deliberately the opposite of site_settings_reference, which defaults to
   * block because the props IT fills carry placeholder cards that must never
   * reach a page. A single field is the other case: a blank tagline should
   * usually defer, not force the region blank. The default is the difference,
   * and the test below shows a site builder can still swap it.
   *
   * @see \Drupal\Tests\neo_site_settings\Kernel\SiteSettingsReferenceValueTest::testEmptyReferenceRendersNothing
   */
  public function testEmptyFieldFallsThroughToTheExample(): void {
    $this->settings(NULL);

    $this->assertSame(
      'EXAMPLE_HEADING',
      $this->heading($this->buildComponent()),
      'Producing empty under stop_when_found defers rather than claims.',
    );
  }

  /**
   * Switching the instance to block makes an empty field claim the prop.
   */
  public function testEmptyFieldClaimsWhenModeIsBlock(): void {
    $this->settings(NULL);

    $this->assertNotSame(
      'EXAMPLE_HEADING',
      $this->heading($this->buildComponent([
        'processing_mode' => ComponentValueProcessingModeInterface::MODE_BLOCK,
      ])),
      'The mode is configurable per instance, not baked into the plugin.',
    );
  }

  /**
   * An unconfigured provider passes the threaded value through.
   */
  public function testUnconfiguredProviderLeavesTheExampleIntact(): void {
    $this->settings('REAL HEADING');

    $this->assertSame(
      'EXAMPLE_HEADING',
      $this->heading($this->buildComponent(['bundle' => '', 'field' => ''])),
    );
  }

  /**
   * The bundle list cache tag reaches the component.
   */
  public function testSettingsListCacheTagIsAttached(): void {
    $this->settings('REAL HEADING');

    $component = $this->buildComponent();
    $component->getPropValues();

    $this->assertContains(
      'neo_site_settings_list:general',
      $component->getCacheableMetadata()->getCacheTags(),
    );
  }

  /**
   * The provider is withheld from object props.
   */
  public function testWithheldFromObjectProps(): void {
    $component = $this->buildComponent();
    $manager = $this->container->get('plugin.manager.neo_component_value');

    $this->assertArrayHasKey(
      'site_settings_field',
      $manager->getFilteredDefinitionsFromShape($component->getPropShape('heading')),
    );
    $this->assertArrayNotHasKey(
      'site_settings_field',
      $manager->getFilteredDefinitionsFromShape($component->getPropShape('box')),
      'It assigns a scalar, which an object shape cannot distribute.',
    );
  }

}
