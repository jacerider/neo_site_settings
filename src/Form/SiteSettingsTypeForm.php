<?php

declare(strict_types=1);

namespace Drupal\neo_site_settings\Form;

use Drupal\Core\Entity\BundleEntityFormBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_site_settings\Entity\SiteSettingsType;

/**
 * Form handler for site settings type forms.
 */
final class SiteSettingsTypeForm extends BundleEntityFormBase {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\neo_site_settings\SiteSettingsTypeInterface $entity */
    $entity = $this->entity;
    $form = parent::form($form, $form_state);

    if ($this->operation === 'edit') {
      $form['#title'] = $this->t('Edit %label site settings type', ['%label' => $entity->label()]);
    }

    $form['label'] = [
      '#title' => $this->t('Label'),
      '#type' => 'textfield',
      '#default_value' => $entity->label(),
      '#description' => $this->t('The human-readable name of this site settings type.'),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $entity->id(),
      '#maxlength' => EntityTypeInterface::BUNDLE_MAX_LENGTH,
      '#machine_name' => [
        'exists' => [SiteSettingsType::class, 'load'],
        'source' => ['label'],
      ],
      '#description' => $this->t('A unique machine-readable name for this site settings type. It must only contain lowercase letters, numbers, and underscores.'),
    ];

    $form['icon'] = [
      '#type' => 'neo_icon_select',
      '#title' => $this->t('Icon'),
      '#default_value' => $entity->getIcon(),
      '#description' => $this->t('The icon for this site settings type. This can be a font icon class or a custom SVG.'),
    ];

    $form['aggregate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Aggregate'),
      '#description' => $this->t('Enabling will add this form type within the general settings form.'),
      '#default_value' => $entity->isAggregate(),
      '#access' => $entity->id() != 'general',
    ];

    return $this->protectBundleIdElement($form);
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);
    $actions['submit']['#value'] = $this->t('Save site settings type');
    $actions['delete']['#value'] = $this->t('Delete site settings type');
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    $message_args = ['%label' => $this->entity->label()];
    $this->messenger()->addStatus(
      match($result) {
        SAVED_NEW => $this->t('The site settings type %label has been added.', $message_args),
        SAVED_UPDATED => $this->t('The site settings type %label has been updated.', $message_args),
      }
    );
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));

    return $result;
  }

}
