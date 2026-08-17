<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_site_settings\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\neo_site_settings\Entity\SiteSettingsType;
use Drupal\neo_site_settings\SiteSettingsInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Saving settings mirrors chosen fields into other modules' config.
 *
 * A field can carry third party settings naming a config object and key, and
 * saving the settings form then writes the field's value there — which is how
 * the site name and site email reach system.site on a typical install.
 *
 * The edge that matters is the empty one. The values used to be imploded
 * unconditionally, so an empty field wrote an empty string over whatever the
 * target key held: clearing an optional mapped field silently blanked a core
 * config value, with no message and nothing in the log.
 *
 * The targets below are all real, schema'd keys on system.site. That is not
 * incidental: the config name and key are free text entered by an administrator
 * and are written with no schema check, so naming an object that has none
 * throws SchemaIncompleteException under strict validation and silently stores
 * an untyped key in production. Nothing validates the pair at entry.
 *
 * @see \Drupal\neo_site_settings\Form\SiteSettingsForm::save()
 * @see neo_site_settings_form_field_config_edit_form_alter()
 */
#[Group('neo_site_settings')]
final class SiteSettingsCloneTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'link',
    'telephone',
    'neo',
    'neo_site_settings',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('neo_site_settings');
    $this->installConfig(['system']);

    SiteSettingsType::create([
      'id' => 'general',
      'label' => 'General',
      'aggregate' => TRUE,
    ])->save();

    // Two mirrored fields: one standing in for the site name, one for an
    // optional value that may legitimately be cleared.
    $this->createMirroredField('field_name', 'string', 'system.site', 'name');
    $this->createMirroredField('field_note', 'string', 'system.site', 'slogan');
  }

  /**
   * A populated field is written to the config object it names.
   */
  public function testPopulatedFieldIsMirroredToConfig(): void {
    $this->saveThroughForm(['field_name' => 'Westwood Professional Services']);

    $this->assertSame(
      'Westwood Professional Services',
      $this->config('system.site')->get('name'),
    );
  }

  /**
   * An empty field leaves the target key alone rather than blanking it.
   */
  public function testEmptyFieldDoesNotOverwriteTheTarget(): void {
    $this->config('system.site')->set('slogan', 'PRESET')->save();

    $this->saveThroughForm(['field_note' => NULL]);

    $this->assertSame(
      'PRESET',
      $this->config('system.site')->get('slogan'),
      'An empty source field must not clobber the value already stored.',
    );
  }

  /**
   * Clearing a previously mirrored field leaves the last mirrored value.
   *
   * This is the deliberate consequence of the guard: the config key is no
   * longer a mirror of an empty field, it simply stops being updated. Blanking
   * the target instead is what the old behaviour did.
   */
  public function testClearingFieldLeavesTheLastMirroredValue(): void {
    $this->saveThroughForm(['field_note' => 'first value']);
    $this->assertSame('first value', $this->config('system.site')->get('slogan'));

    $this->saveThroughForm(['field_note' => NULL]);

    $this->assertSame('first value', $this->config('system.site')->get('slogan'));
  }

  /**
   * A field naming no config object is skipped entirely.
   */
  public function testFieldWithoutMirrorSettingsIsSkipped(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_plain',
      'entity_type' => 'neo_site_settings',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_plain',
      'entity_type' => 'neo_site_settings',
      'bundle' => 'general',
      'label' => 'Plain',
    ])->save();

    $this->saveThroughForm(['field_plain' => 'nowhere']);

    // Nothing to assert about a target; the point is that saving does not throw
    // when a field carries no third party settings at all.
    $this->assertSame('nowhere', $this->loadGeneral()->get('field_plain')->value);
  }

  /**
   * Declares a field on the general bundle that mirrors into config.
   */
  private function createMirroredField(string $name, string $type, string $configName, string $configKey): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'neo_site_settings',
      'type' => $type,
    ])->save();

    $field = FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'neo_site_settings',
      'bundle' => 'general',
      'label' => $name,
    ]);
    $field->setThirdPartySetting('neo_site_settings', 'config_name', $configName);
    $field->setThirdPartySetting('neo_site_settings', 'config_key', $configKey);
    $field->setThirdPartySetting('neo_site_settings', 'config_delimiter', '');
    $field->save();
  }

  /**
   * Saves the general settings entity through the real form object.
   *
   * The mirroring lives in the form's save(), not in the entity, so exercising
   * the entity directly would not test it.
   */
  private function saveThroughForm(array $values): void {
    $entity = $this->loadGeneral();
    foreach ($values as $field => $value) {
      $entity->set($field, $value);
    }

    $form_object = $this->container->get('entity_type.manager')
      ->getFormObject('neo_site_settings', 'default');
    $form_object->setEntity($entity);

    $form = [];
    $form_object->save($form, new FormState());
  }

  /**
   * Loads the general settings entity.
   */
  private function loadGeneral(): SiteSettingsInterface {
    return $this->container->get('entity_type.manager')
      ->getStorage('neo_site_settings')
      ->loadOrCreateByType('general');
  }

}
