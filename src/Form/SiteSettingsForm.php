<?php

declare(strict_types=1);

namespace Drupal\neo_site_settings\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\field\FieldConfigInterface;
use Drupal\neo\NeoNestedEntityFormBaseTrait;
use Drupal\neo\NeoNestedEntityFormInterface;
use Drupal\neo_site_settings\Event\SiteSettingsConfigCloneEvent;

/**
 * Form controller for the site settings entity edit forms.
 */
final class SiteSettingsForm extends ContentEntityForm implements NeoNestedEntityFormInterface {

  use NeoNestedEntityFormBaseTrait;

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    // Clone to config as specified.
    foreach ($this->entity->getFieldDefinitions() as $field) {
      if ($field instanceof FieldConfigInterface) {
        if ($clone = $this->getCloneDefinition($field)) {
          $values = [];
          foreach ($this->entity->get($field->getName())->getValue() as $value) {
            $value = $value[$field->getFieldStorageDefinition()->getMainPropertyName()];
            if ($field->getType() == 'link') {
              $value = str_replace('internal:', '', $value);
            }
            $values[] = $value;
          }
          // An empty field has nothing to clone. Writing the imploded empty
          // string here would blank the target key instead of leaving it alone.
          if (!$values) {
            continue;
          }
          $this->configFactory()->getEditable($clone->getName())
            ->set($clone->getKey(), implode($clone->getDelimiter() ?: '', $values))
            ->save();
        }
      }
    }

    $message_args = ['%label' => $this->entity->label()];
    $logger_args = [
      '%label' => $this->entity->label(),
      'link' => Url::fromRoute('<current>')->toString(),
    ];

    switch ($result) {
      case SAVED_NEW:
        $this->messenger()->addStatus($this->t('New site settings %label has been created.', $message_args));
        $this->logger('neo_site_settings')->notice('New site settings %label has been created.', $logger_args);
        break;

      case SAVED_UPDATED:
        $this->messenger()->addStatus($this->t('The site settings %label has been updated.', $message_args));
        $this->logger('neo_site_settings')->notice('The site settings %label has been updated.', $logger_args);
        break;

      default:
        throw new \LogicException('Could not save the entity.');
    }

    return $result;
  }

  /**
   * Get clone information.
   *
   * @param \Drupal\field\FieldConfigInterface $field
   *   The field config.
   *
   * @return \Drupal\neo_site_settings\Event\SiteSettingsConfigCloneEvent|null
   *   The dispatched event, which carries the resolved config name, key and
   *   delimiter, or NULL when this field is not cloned anywhere.
   */
  protected function getCloneDefinition(FieldConfigInterface $field): ?SiteSettingsConfigCloneEvent {
    $event = new SiteSettingsConfigCloneEvent(
      $field,
      $field->getThirdPartySetting('neo_site_settings', 'config_name'),
      $field->getThirdPartySetting('neo_site_settings', 'config_key'),
      $field->getThirdPartySetting('neo_site_settings', 'config_delimiter'),
    );
    \Drupal::service('event_dispatcher')->dispatch($event, SiteSettingsConfigCloneEvent::EVENT_NAME);
    return $event->isEmpty() ? NULL : $event;
  }

}
